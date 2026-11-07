<?php

namespace Ux1\UxStudio\Modules\Analytics;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Aggregation and read model. Raw hits live in a small buffer table; every day older than today is merged into daily
 * aggregates (idempotent upserts) and its raw rows are deleted, so queries stay fast and the buffer never grows.
 */
final class Stats
{
    /** Dimension breakdowns: key => raw column. */
    private const DIMS = ['device' => 'device', 'browser' => 'browser', 'os' => 'os', 'lang' => 'lang', 'country' => 'country'];

    public const PERIODS = ['today', 'yesterday', '7d', '14d', '30d', 'month'];

    private const T_HITS   = '#__uxstudio_analytics_hits';
    private const T_DAILY  = '#__uxstudio_analytics_daily';
    private const T_PAGES  = '#__uxstudio_analytics_daily_pages';
    private const T_REFS   = '#__uxstudio_analytics_daily_refs';
    private const T_DIMS   = '#__uxstudio_analytics_daily_dims';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** Merges every day before today from the buffer into the aggregates and deletes the raw rows. */
    public function rollup(): int
    {
        $db = $this->db;
        // Single runner: two concurrent rollups could otherwise overwrite a day with an empty re-aggregation.
        try {
            $got = (int) $db->setQuery('SELECT GET_LOCK(' . $db->quote('uxs_analytics_rollup') . ', 0)')->loadResult();
        } catch (\Throwable $e) {
            return 0;
        }
        if ($got !== 1) {
            return 0;
        }

        try {
            return $this->doRollup();
        } finally {
            try {
                $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote('uxs_analytics_rollup') . ')')->execute();
            } catch (\Throwable $e) {
                // The lock dies with the connection anyway.
            }
        }
    }

    private function doRollup(): int
    {
        $db    = $this->db;
        $today = Tracker::today();
        $days  = $db->setQuery(
            $db->createQuery()->select('DISTINCT ' . $db->quoteName('day'))->from($db->quoteName(self::T_HITS))
                ->where($db->quoteName('day') . ' < :t')->bind(':t', $today)->order($db->quoteName('day')),
            0,
            90
        )->loadColumn();

        foreach ($days as $day) {
            $db->transactionStart();
            try {
                $this->rollupDay((string) $day);
                $db->transactionCommit();
            } catch (\Throwable $e) {
                $db->transactionRollback();
                throw $e;
            }
        }

        return \count($days);
    }

    private function rollupDay(string $day): void
    {
        $db = $this->db;
        $d    = $db->quote($day);
        $raw  = $db->quoteName(self::T_HITS);
        $stat = 'COUNT(*), COUNT(DISTINCT visitor_hash)';
        $upd  = ' ON DUPLICATE KEY UPDATE views = VALUES(views), visitors = VALUES(visitors)';

        $db->setQuery("INSERT INTO {$db->quoteName(self::T_DAILY)} (day, views, visitors) SELECT {$d}, {$stat} FROM {$raw} WHERE day = {$d} HAVING COUNT(*) > 0" . $upd)->execute();
        $db->setQuery("INSERT INTO {$db->quoteName(self::T_PAGES)} (day, path, views, visitors) SELECT {$d}, LEFT(path,190), {$stat} FROM {$raw} WHERE day = {$d} GROUP BY LEFT(path,190)" . $upd)->execute();
        $db->setQuery("INSERT INTO {$db->quoteName(self::T_REFS)} (day, referer_host, views, visitors) SELECT {$d}, referer_host, {$stat} FROM {$raw} WHERE day = {$d} AND referer_host IS NOT NULL AND referer_host <> '' GROUP BY referer_host" . $upd)->execute();
        foreach (self::DIMS as $dim => $col) {
            $db->setQuery(
                "INSERT INTO {$db->quoteName(self::T_DIMS)} (day, dim, val, views, visitors) SELECT {$d}, " . $db->quote($dim)
                . ", COALESCE(NULLIF({$col}, ''), '-'), {$stat} FROM {$raw} WHERE day = {$d} GROUP BY COALESCE(NULLIF({$col}, ''), '-')" . $upd
            )->execute();
        }
        $db->setQuery("DELETE FROM {$raw} WHERE day = {$d}")->execute();
    }

    /** @return array{0:string,1:string} inclusive [from, to] in Y-m-d */
    public function range(string $period, string $today): array
    {
        $shift = static fn (string $d, int $n): string => gmdate('Y-m-d', strtotime($d . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));

        return match ($period) {
            'today'     => [$today, $today],
            'yesterday' => [$shift($today, -1), $shift($today, -1)],
            '14d'       => [$shift($today, -13), $today],
            '30d'       => [$shift($today, -29), $today],
            'month'     => [gmdate('Y-m-01', strtotime($today)), $today],
            default     => [$shift($today, -6), $today],
        };
    }

    /** @return array<string,mixed> */
    public function summary(string $period): array
    {
        $this->rollup();

        $today  = Tracker::today();
        $period = \in_array($period, self::PERIODS, true) ? $period : '7d';
        [$from, $to] = $this->range($period, $today);

        $len       = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $prevTo    = gmdate('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom  = gmdate('Y-m-d', strtotime($from . ' -' . $len . ' days'));
        $daily     = $this->dailySeries($from, $to, $today);
        $prev      = $this->dailySeries($prevFrom, $prevTo, $today);

        return [
            'range'      => ['from' => $from, 'to' => $to, 'period' => $period, 'days' => $len],
            'totals'     => ['views' => array_sum(array_column($daily, 'views')), 'visitors' => array_sum(array_column($daily, 'uniq'))],
            'previous'   => ['views' => array_sum(array_column($prev, 'views')), 'visitors' => array_sum(array_column($prev, 'uniq'))],
            'realtime'   => $this->realtime(),
            'daily'      => $daily,
            'top_pages'  => $this->topDimension(self::T_PAGES, 'path', $from, $to, $today, 15),
            'referers'   => $this->topDimension(self::T_REFS, 'referer_host', $from, $to, $today, 15),
            'breakdowns' => $this->breakdowns($from, $to, $today),
        ];
    }

    /** Page views and distinct visitors of one day straight from the buffer (dashboard widget). */
    public function today(): array
    {
        $db    = $this->db;
        $today = Tracker::today();
        $row   = $db->setQuery(
            $db->createQuery()->select('COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors')->from($db->quoteName(self::T_HITS))
                ->where($db->quoteName('day') . ' = :d')->bind(':d', $today)
        )->loadAssoc() ?: [];

        return ['views' => (int) ($row['views'] ?? 0), 'visitors' => (int) ($row['visitors'] ?? 0)];
    }

    private function realtime(): int
    {
        $db    = $this->db;
        $since = Factory::getDate('-1 hour')->toSql();

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::T_HITS))->where($db->quoteName('ts') . ' >= :s')->bind(':s', $since)
        )->loadResult();
    }

    /**
     * Zero filled series [{day, views, uniq}]: history from the aggregate, today from the buffer.
     *
     * @return array<int,array{day:string,views:int,uniq:int}>
     */
    private function dailySeries(string $from, string $to, string $today): array
    {
        $db      = $this->db;
        $map     = [];
        $histTo  = $to >= $today ? gmdate('Y-m-d', strtotime($today . ' -1 day')) : $to;
        if ($from <= $histTo) {
            $rows = $db->setQuery(
                $db->createQuery()->select($db->quoteName(['day', 'views', 'visitors']))->from($db->quoteName(self::T_DAILY))
                    ->where($db->quoteName('day') . ' >= :f')->where($db->quoteName('day') . ' <= :t')->bind(':f', $from)->bind(':t', $histTo)
            )->loadAssocList();
            foreach ($rows as $r) {
                $map[$r['day']] = [(int) $r['views'], (int) $r['visitors']];
            }
        }
        if ($to >= $today && $from <= $today) {
            $r = $db->setQuery(
                $db->createQuery()->select('COUNT(*) AS v, COUNT(DISTINCT visitor_hash) AS u')->from($db->quoteName(self::T_HITS))
                    ->where($db->quoteName('day') . ' = :d')->bind(':d', $today)
            )->loadAssoc() ?: [];
            $map[$today] = [(int) ($r['v'] ?? 0), (int) ($r['u'] ?? 0)];
        }

        $series = [];
        for ($d = $from; $d <= $to; $d = gmdate('Y-m-d', strtotime($d . ' +1 day'))) {
            $series[] = ['day' => $d, 'views' => $map[$d][0] ?? 0, 'uniq' => $map[$d][1] ?? 0];
        }

        return $series;
    }

    /**
     * Ranking of pages / referrers across the range (aggregate + today's buffer), sorted by views.
     *
     * @return array<int,array<string,mixed>>
     */
    private function topDimension(string $table, string $col, string $from, string $to, string $today, int $limit): array
    {
        $db     = $this->db;
        $acc    = [];
        $histTo = $to >= $today ? gmdate('Y-m-d', strtotime($today . ' -1 day')) : $to;

        if ($from <= $histTo) {
            $rows = $db->setQuery(
                $db->createQuery()->select($db->quoteName($col, 'k') . ', SUM(views) AS v, SUM(visitors) AS u')->from($db->quoteName($table))
                    ->where($db->quoteName('day') . ' >= :f')->where($db->quoteName('day') . ' <= :t')->bind(':f', $from)->bind(':t', $histTo)
                    ->group($db->quoteName($col))
            )->loadAssocList();
            foreach ($rows as $r) {
                $acc[(string) $r['k']] = [(int) $r['v'], (int) $r['u']];
            }
        }
        if ($to >= $today && $from <= $today) {
            $sel = $col === 'path' ? 'LEFT(path,190)' : $col;
            $q   = $db->createQuery()->select("{$sel} AS k, COUNT(*) AS v, COUNT(DISTINCT visitor_hash) AS u")->from($db->quoteName(self::T_HITS))
                ->where($db->quoteName('day') . ' = :d')->bind(':d', $today)->group($sel);
            if ($col === 'referer_host') {
                $q->where($db->quoteName('referer_host') . ' IS NOT NULL')->where($db->quoteName('referer_host') . " <> ''");
            }
            foreach ($db->setQuery($q)->loadAssocList() as $r) {
                $k       = (string) $r['k'];
                $acc[$k] = [($acc[$k][0] ?? 0) + (int) $r['v'], ($acc[$k][1] ?? 0) + (int) $r['u']];
            }
        }

        uasort($acc, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $out = [];
        foreach ($acc as $k => $vu) {
            if ($k === '') {
                continue;
            }
            $out[] = [$col => (string) $k, 'views' => $vu[0], 'visitors' => $vu[1]];
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return array<string,array<int,array{val:string,views:int,visitors:int}>> */
    private function breakdowns(string $from, string $to, string $today): array
    {
        $out = [];
        foreach (self::DIMS as $dim => $col) {
            $rows = $this->dimBreakdown($dim, $col, $from, $to, $today, 8);
            if ($dim === 'country') {
                $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['val'] !== '-'));
            }
            $out[$dim] = $rows;
        }

        return $out;
    }

    /** @return array<int,array{val:string,views:int,visitors:int}> */
    private function dimBreakdown(string $dim, string $col, string $from, string $to, string $today, int $limit): array
    {
        $db     = $this->db;
        $acc    = [];
        $histTo = $to >= $today ? gmdate('Y-m-d', strtotime($today . ' -1 day')) : $to;

        if ($from <= $histTo) {
            $rows = $db->setQuery(
                $db->createQuery()->select('val, SUM(views) AS v, SUM(visitors) AS u')->from($db->quoteName(self::T_DIMS))
                    ->where($db->quoteName('dim') . ' = :dim')->where($db->quoteName('day') . ' >= :f')->where($db->quoteName('day') . ' <= :t')
                    ->bind(':dim', $dim)->bind(':f', $from)->bind(':t', $histTo)->group($db->quoteName('val'))
            )->loadAssocList();
            foreach ($rows as $r) {
                $acc[(string) $r['val']] = [(int) $r['v'], (int) $r['u']];
            }
        }
        if ($to >= $today && $from <= $today) {
            $expr = "COALESCE(NULLIF({$col}, ''), '-')";
            $rows = $db->setQuery(
                $db->createQuery()->select("{$expr} AS val, COUNT(*) AS v, COUNT(DISTINCT visitor_hash) AS u")->from($db->quoteName(self::T_HITS))
                    ->where($db->quoteName('day') . ' = :d')->bind(':d', $today)->group($expr)
            )->loadAssocList();
            foreach ($rows as $r) {
                $k       = (string) $r['val'];
                $acc[$k] = [($acc[$k][0] ?? 0) + (int) $r['v'], ($acc[$k][1] ?? 0) + (int) $r['u']];
            }
        }

        uasort($acc, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        $out = [];
        foreach ($acc as $val => $vu) {
            $out[] = ['val' => (string) $val, 'views' => $vu[0], 'visitors' => $vu[1]];
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
