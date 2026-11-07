<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Sliding-window load sampling -> traffic tier (GREEN / YELLOW / ORANGE / RED).
 *
 * Every site request may add its response time, DB query count and peak memory to a 10-second bucket in #__uxstudio_bt_load
 * (only 1 request in SAMPLE_RATE writes, the score only needs averages). The score (0-100) is derived from the last 60
 * seconds, the system load average counts when the OS exposes it (not on Windows). RED is held for 30 seconds against flapping.
 */
final class LoadSampler
{
    public const SAMPLE_RATE = 10;
    private const BUCKET     = 10;
    private const TABLE      = '#__uxstudio_bt_load';

    public const GREEN  = 'GREEN';
    public const YELLOW = 'YELLOW';
    public const ORANGE = 'ORANGE';
    public const RED    = 'RED';

    /** @var array{yellow:int,orange:int,red:int} */
    private array $thresholds;

    /** @param array{yellow?:int,orange?:int,red?:int} $thresholds */
    public function __construct(private DatabaseInterface $db, private Kv $kv, array $thresholds = [])
    {
        $this->thresholds = array_merge(['yellow' => 50, 'orange' => 75, 'red' => 90], array_map('intval', $thresholds));
    }

    /** Folds one finished request into the window (call for a sampled subset of requests). */
    public function record(float $responseMs, int $queries, float $memMb): void
    {
        try {
            $db     = $this->db;
            $now    = time();
            $bucket = $now - ($now % self::BUCKET);
            $rt     = round($responseMs, 1);
            $mem    = round($memMb, 1);
            $q      = max(0, $queries);
            $query  = $db->createQuery()->setQuery(
                'INSERT INTO ' . $db->quoteName(self::TABLE) . ' (' . implode(', ', $db->quoteName(['bucket', 'n', 'rt_sum', 'q_sum', 'mem_sum'])) . ') VALUES (:b, 1, :rt, :q, :m) '
                . 'ON DUPLICATE KEY UPDATE ' . $db->quoteName('n') . ' = ' . $db->quoteName('n') . ' + 1, ' . $db->quoteName('rt_sum') . ' = ' . $db->quoteName('rt_sum') . ' + VALUES(' . $db->quoteName('rt_sum') . '), '
                . $db->quoteName('q_sum') . ' = ' . $db->quoteName('q_sum') . ' + VALUES(' . $db->quoteName('q_sum') . '), ' . $db->quoteName('mem_sum') . ' = ' . $db->quoteName('mem_sum') . ' + VALUES(' . $db->quoteName('mem_sum') . ')'
            )->bind(':b', $bucket, ParameterType::INTEGER)->bind(':rt', $rt)->bind(':q', $q, ParameterType::INTEGER)->bind(':m', $mem);
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Sampling must never break a page.
        }
    }

    /** @return array{0:int,1:float,2:float,3:float} count, response ms sum, query sum, memory sum of the last 60 s */
    private function totals(): array
    {
        try {
            $db    = $this->db;
            $since = time() - 60;
            $row   = $db->setQuery(
                $db->createQuery()->select('COALESCE(SUM(n),0) AS n, COALESCE(SUM(rt_sum),0) AS rt, COALESCE(SUM(q_sum),0) AS q, COALESCE(SUM(mem_sum),0) AS m')
                    ->from($db->quoteName(self::TABLE))->where($db->quoteName('bucket') . ' >= :s')->bind(':s', $since, ParameterType::INTEGER)
            )->loadAssoc();

            return $row ? [(int) $row['n'], (float) $row['rt'], (float) $row['q'], (float) $row['m']] : [0, 0.0, 0.0, 0.0];
        } catch (\Throwable $e) {
            return [0, 0.0, 0.0, 0.0];
        }
    }

    /** @return array{tier:string,score:float} */
    public function currentTier(): array
    {
        $score = $this->score($this->totals());
        $tier  = $this->tierFor($score);

        // Hysteresis: hold RED for up to 30 s while the score is still above 80.
        $prev = explode('|', (string) $this->kv->get('tier'));
        if (($prev[0] ?? '') === self::RED && $tier !== self::RED && time() - (int) ($prev[1] ?? 0) < 30 && $score > 80) {
            $tier = self::RED;
        }
        if (($prev[0] ?? '') !== $tier) {
            $this->kv->set('tier', $tier . '|' . time(), 300);
        }

        return ['tier' => $tier, 'score' => $score];
    }

    /** @param array{0:int,1:float,2:float,3:float} $totals */
    private function score(array $totals): float
    {
        [$n, $rt, $q, $m] = $totals;
        if ($n <= 0) {
            return $this->systemLoadPercent() ?? 0.0;
        }
        $rtScore  = min(100, ($rt / $n / 2000) * 100);   // 2 s average = 100
        $qScore   = min(100, ($q / $n / 100) * 100);     // 100 queries = 100
        $mScore   = min(100, ($m / $n / 256) * 100);     // 256 MB = 100
        $sysScore = $this->systemLoadPercent() ?? 0.0;

        return round($rtScore * 0.40 + $qScore * 0.30 + $mScore * 0.20 + $sysScore * 0.10, 1);
    }

    private function systemLoadPercent(): ?float
    {
        if (!\function_exists('sys_getloadavg')) {
            return null;
        }
        $load = @sys_getloadavg();
        if (!\is_array($load) || !isset($load[0])) {
            return null;
        }
        $cores = (int) (\defined('UXSTUDIO_BT_CPU_CORES') ? UXSTUDIO_BT_CPU_CORES : 4);

        return min(100.0, ($load[0] / max(1, $cores)) * 100);
    }

    private function tierFor(float $score): string
    {
        return match (true) {
            $score >= $this->thresholds['red']    => self::RED,
            $score >= $this->thresholds['orange'] => self::ORANGE,
            $score >= $this->thresholds['yellow'] => self::YELLOW,
            default                               => self::GREEN,
        };
    }

    public function purge(): void
    {
        try {
            $db  = $this->db;
            $old = time() - 3600;
            $db->setQuery($db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('bucket') . ' < :o')->bind(':o', $old, ParameterType::INTEGER))->execute();
        } catch (\Throwable $e) {
        }
    }
}
