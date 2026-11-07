<?php

namespace Ux1\UxStudio\Modules\PageLoad;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Stores beacon samples and reads them back: percentiles, trend and the slowest pages. Nothing personal is stored:
 * no IP, no user agent, only the page path, the timings and a device class derived from the viewport width.
 */
final class Stats
{
    public const PERIODS = ['1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    /** metric => [good limit, poor limit] in the unit of the stored column (ms; CLS in thousandths) */
    private const MAX_NEW_PATHS_PER_DAY = 1000;

    private const LIMITS = [
        'ttfb_ms'   => [800, 1800],
        'fcp_ms'    => [1800, 3000],
        'lcp_ms'    => [2500, 4000],
        'cls_milli' => [100, 250],
    ];

    private const BOT_PATTERN = '~bot\b|bot/|crawl|spider|slurp|fetch|monitor|uptime|pingdom|headless|phantom|lighthouse|pagespeed|gtmetrix|curl|wget|python|java/|libwww|okhttp|go-http|httpclient|axios|node-fetch|postman|preview|validator|zabbix|nagios~i';

    public function __construct(private DatabaseInterface $db, private int $slowMs = 3000)
    {
    }

    public static function isBot(string $ua): bool
    {
        return $ua === '' || preg_match(self::BOT_PATTERN, $ua) === 1;
    }

    /** Site path only, no query except Joomla's non-SEF routing parameters, sub-folder removed, max 255 chars. */
    public static function cleanPath(string $path): string
    {
        $path  = substr(trim($path), 0, 2048);
        $parts = parse_url($path);
        $p     = (string) ($parts['path'] ?? $path);
        $p     = '/' . ltrim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $p), '/');
        // The path is untrusted input (it ends up in the admin screen and in the Central App): only characters that can
        // appear in a URL path are accepted, anything else (markup, quotes) rejects the whole sample.
        if (!preg_match('~^/[\p{L}\p{N}/._\~%:@!$&()*+,;=-]*$~u', $p)) {
            return '';
        }
        $base = rtrim(Uri::root(true), '/');
        if ($base !== '' && ($p === $base || str_starts_with($p, $base . '/'))) {
            $p = substr($p, \strlen($base)) ?: '/';
        }
        $query = (string) ($parts['query'] ?? '');
        if ($query !== '' && preg_match('/(^|&)option=com_[a-z0-9_]+/i', $query)) {
            parse_str($query, $q);
            $keep = [];
            foreach (['option', 'view', 'id', 'catid', 'Itemid', 'layout'] as $k) {
                if (isset($q[$k]) && \is_scalar($q[$k]) && preg_match('/^[A-Za-z0-9_:.-]{1,40}$/', (string) $q[$k])) {
                    $keep[$k] = (string) $q[$k];
                }
            }
            if ($keep) {
                $p .= '?' . http_build_query($keep);
            }
        }

        return mb_substr($p, 0, 255);
    }

    private static function clamp(mixed $v, int $max): int
    {
        return is_numeric($v) ? max(0, min($max, (int) round((float) $v))) : 0;
    }

    /**
     * Validates one beacon payload and stores it. Returns false when it was rejected (implausible numbers).
     *
     * @param array<string,mixed> $in
     */
    public function record(array $in): bool
    {
        $load = self::clamp($in['load'] ?? 0, 120000);
        if ($load <= 0) {
            return false;
        }
        $path = self::cleanPath((string) ($in['path'] ?? ''));
        if ($path === '' || !$this->pathAllowed($path)) {
            return false;
        }
        $w    = self::clamp($in['w'] ?? 0, 20000);
        $dev  = $w === 0 ? 'unknown' : ($w < 768 ? 'mobile' : ($w < 1100 ? 'tablet' : 'desktop'));
        $nav  = \in_array($in['nav'] ?? '', ['navigate', 'reload', 'back_forward', 'prerender'], true) ? (string) $in['nav'] : '';
        $row  = [
            'created'   => Factory::getDate()->toSql(),
            'path'      => $path,
            'device'    => $dev,
            'nav_type'  => $nav,
            'ttfb_ms'   => self::clamp($in['ttfb'] ?? 0, 120000),
            'fcp_ms'    => self::clamp($in['fcp'] ?? 0, 120000),
            'lcp_ms'    => self::clamp($in['lcp'] ?? 0, 120000),
            'dcl_ms'    => self::clamp($in['dcl'] ?? 0, 120000),
            'load_ms'   => $load,
            'cls_milli' => self::clamp($in['cls'] ?? 0, 100000),
            'bytes'     => self::clamp($in['size'] ?? 0, 2000000000),
        ];

        $db  = $this->db;
        $q   = $db->createQuery()->insert($db->quoteName('#__uxstudio_page_load'))->columns($db->quoteName(array_keys($row)));
        $ph  = [];
        foreach ($row as $k => $v) {
            $ph[] = ':' . $k;
        }
        $q->values(implode(', ', $ph));
        foreach ($row as $k => $v) {
            $q->bind(':' . $k, $row[$k], \is_int($v) ? ParameterType::INTEGER : ParameterType::STRING);
        }
        $db->setQuery($q)->execute();

        return true;
    }

    /** A path already seen is always fine; new ones are capped per day so invented paths cannot flood the table. */
    private function pathAllowed(string $path): bool
    {
        $db   = $this->db;
        $seen = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_page_load'))->where($db->quoteName('path') . ' = :p')->bind(':p', $path)
        )->loadResult();
        if ($seen > 0) {
            return true;
        }
        $since    = Factory::getDate('-1 day')->toSql();
        $distinct = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(DISTINCT ' . $db->quoteName('path') . ')')->from($db->quoteName('#__uxstudio_page_load'))->where($db->quoteName('created') . ' >= :s')->bind(':s', $since)
        )->loadResult();

        return $distinct < self::MAX_NEW_PATHS_PER_DAY;
    }

    private function since(string $period): string
    {
        $days = self::PERIODS[$period] ?? 7;

        return Factory::getDate('-' . $days . ' days')->toSql();
    }

    private static function percentile(array $values, float $q): int
    {
        if (!$values) {
            return 0;
        }
        sort($values);
        $i = (int) ceil($q * \count($values)) - 1;

        return (int) $values[max(0, min(\count($values) - 1, $i))];
    }

    private function rate(string $metric, int $value): string
    {
        if ($value <= 0) {
            return 'none';
        }
        [$good, $poor] = self::LIMITS[$metric] ?? [$this->slowMs, $this->slowMs * 2];

        return $value <= $good ? 'good' : ($value <= $poor ? 'ok' : 'poor');
    }

    /** @return array<string,mixed> */
    public function summary(string $period): array
    {
        $db    = $this->db;
        $since = $this->since($period);
        $total = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_page_load'))->where($db->quoteName('created') . ' >= :s')->bind(':s', $since)
        )->loadResult();

        // Percentiles from the newest 5000 samples of the period (enough for a stable p75, cheap on shared hosting).
        $rows = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['device', 'ttfb_ms', 'fcp_ms', 'lcp_ms', 'dcl_ms', 'load_ms', 'cls_milli']))
                ->from($db->quoteName('#__uxstudio_page_load'))->where($db->quoteName('created') . ' >= :s')->bind(':s', $since)
                ->order($db->quoteName('id') . ' DESC'),
            0,
            5000
        )->loadAssocList() ?: [];

        $metrics = [];
        foreach (['ttfb_ms', 'fcp_ms', 'lcp_ms', 'dcl_ms', 'load_ms', 'cls_milli'] as $m) {
            $vals = [];
            foreach ($rows as $r) {
                if ((int) $r[$m] > 0) {
                    $vals[] = (int) $r[$m];
                }
            }
            $p75 = self::percentile($vals, 0.75);
            $metrics[$m] = [
                'samples' => \count($vals),
                'median'  => self::percentile($vals, 0.5),
                'p75'     => $p75,
                'rating'  => $this->rate($m, $p75),
            ];
        }

        $devices = [];
        foreach ($rows as $r) {
            $d = (string) $r['device'];
            $devices[$d] ??= ['device' => $d, 'samples' => 0, 'loads' => []];
            $devices[$d]['samples']++;
            $devices[$d]['loads'][] = (int) $r['load_ms'];
        }
        $deviceOut = [];
        foreach ($devices as $d) {
            $deviceOut[] = ['device' => $d['device'], 'samples' => $d['samples'], 'median_load' => self::percentile($d['loads'], 0.5)];
        }

        $trend = $db->setQuery(
            $db->createQuery()
                ->select('DATE(' . $db->quoteName('created') . ') AS ' . $db->quoteName('day') . ', COUNT(*) AS ' . $db->quoteName('n') . ', ROUND(AVG(' . $db->quoteName('load_ms') . ')) AS ' . $db->quoteName('load_avg') . ', ROUND(AVG(' . $db->quoteName('ttfb_ms') . ')) AS ' . $db->quoteName('ttfb_avg'))
                ->from($db->quoteName('#__uxstudio_page_load'))->where($db->quoteName('created') . ' >= :s')->bind(':s', $since)
                ->group('DATE(' . $db->quoteName('created') . ')')->order($db->quoteName('day') . ' ASC')
        )->loadAssocList() ?: [];

        return [
            'period'  => isset(self::PERIODS[$period]) ? $period : '7d',
            'samples' => $total,
            'slow_ms' => $this->slowMs,
            'metrics' => $metrics,
            'devices' => $deviceOut,
            'trend'   => array_map(static fn (array $t): array => ['day' => $t['day'], 'samples' => (int) $t['n'], 'load_avg' => (int) $t['load_avg'], 'ttfb_avg' => (int) $t['ttfb_avg']], $trend),
        ];
    }

    /**
     * Pages with the highest average value of one metric.
     *
     * @return array{items:array<int,array<string,mixed>>,period:string,sort:string}
     */
    public function pages(string $period, string $sort, int $min): array
    {
        $db     = $this->db;
        $since  = $this->since($period);
        $sort   = \in_array($sort, ['load_ms', 'ttfb_ms', 'lcp_ms', 'fcp_ms'], true) ? $sort : 'load_ms';
        $min    = max(1, min(1000, $min));
        $col    = $db->quoteName($sort);
        $query  = $db->createQuery()
            ->select($db->quoteName('path') . ', COUNT(*) AS ' . $db->quoteName('n')
                . ', ROUND(AVG(' . $db->quoteName('load_ms') . ')) AS ' . $db->quoteName('load_avg')
                . ', MAX(' . $db->quoteName('load_ms') . ') AS ' . $db->quoteName('load_max')
                . ', ROUND(AVG(NULLIF(' . $db->quoteName('ttfb_ms') . ', 0))) AS ' . $db->quoteName('ttfb_avg')
                . ', ROUND(AVG(NULLIF(' . $db->quoteName('fcp_ms') . ', 0))) AS ' . $db->quoteName('fcp_avg')
                . ', ROUND(AVG(NULLIF(' . $db->quoteName('lcp_ms') . ', 0))) AS ' . $db->quoteName('lcp_avg'))
            ->from($db->quoteName('#__uxstudio_page_load'))
            ->where($db->quoteName('created') . ' >= :s')->bind(':s', $since)
            ->group($db->quoteName('path'))
            ->having('COUNT(*) >= :m')->bind(':m', $min, ParameterType::INTEGER)
            ->order('AVG(NULLIF(' . $col . ', 0)) DESC');
        $rows = $db->setQuery($query, 0, 50)->loadAssocList() ?: [];

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'path'     => (string) $r['path'],
                'samples'  => (int) $r['n'],
                'load_avg' => (int) $r['load_avg'],
                'load_max' => (int) $r['load_max'],
                'ttfb_avg' => (int) $r['ttfb_avg'],
                'fcp_avg'  => (int) $r['fcp_avg'],
                'lcp_avg'  => (int) $r['lcp_avg'],
            ];
        }

        return ['items' => $items, 'period' => isset(self::PERIODS[$period]) ? $period : '7d', 'sort' => $sort];
    }

    public function clear(): int
    {
        $db = $this->db;
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__uxstudio_page_load'))->execute();

        return $db->getAffectedRows();
    }
}
