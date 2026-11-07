<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

\defined('_JEXEC') or die;

/**
 * Maps a (bot category, load tier) pair to a throttle plan and applies it.
 *
 * Sleeping is planned only at GREEN / YELLOW: at ORANGE / RED a usleep() would hold a PHP worker exactly when the server is
 * short of them, so those tiers serve the microcache and otherwise answer 429 / 503 with Retry-After (block_on_miss).
 * Search engines are never turned away.
 */
final class Throttler
{
    /** @var array<string,string> */
    private array $rules;
    /** @var array<string,int> */
    private array $delays;

    /**
     * @param array<string,string> $rules  category id => action
     * @param array<string,int>    $delays min_delay_ms, light_min, light_max, aggressive_min, aggressive_max
     */
    public function __construct(array $rules = [], array $delays = [])
    {
        $this->rules  = $rules;
        $this->delays = array_merge(['min_delay_ms' => 0, 'light_min' => 300, 'light_max' => 1500, 'aggressive_min' => 2000, 'aggressive_max' => 5000], $delays);
    }

    /** @return array{action:string,delay_ms:int,status:int,block_on_miss?:bool,retry_after?:int} */
    public function plan(string $category, string $tier): array
    {
        $rule = $this->rules[$category] ?? $this->defaultAction($category);

        if ($category === 'search_engines') {
            return match ($tier) {
                'YELLOW'         => ['action' => 'delay', 'delay_ms' => $this->delays['min_delay_ms'], 'status' => 200],
                'ORANGE', 'RED'  => ['action' => 'microcache', 'delay_ms' => 0, 'status' => 200],
                default          => ['action' => 'pass', 'delay_ms' => 0, 'status' => 200],
            };
        }

        return match ($rule) {
            'pass'                => ['action' => 'pass', 'delay_ms' => 0, 'status' => 200],
            'pass_with_min_delay' => $this->minDelay($tier),
            'throttle_aggressive' => $this->aggressive($tier),
            'block'               => ['action' => 'block', 'delay_ms' => 0, 'status' => 429, 'retry_after' => 60],
            default               => $this->light($tier),
        };
    }

    /** @return array{action:string,delay_ms:int,status:int} */
    private function minDelay(string $tier): array
    {
        if ($tier === 'ORANGE' || $tier === 'RED') {
            return ['action' => 'microcache', 'delay_ms' => 0, 'status' => 200];
        }
        $d = $tier === 'YELLOW' ? 200 : 0;

        return ['action' => $d > 0 ? 'delay' : 'pass', 'delay_ms' => $d, 'status' => 200];
    }

    /** @return array<string,mixed> */
    private function light(string $tier): array
    {
        return match ($tier) {
            'YELLOW' => ['action' => 'delay', 'delay_ms' => $this->jitter($this->delays['light_min'], $this->delays['light_max']), 'status' => 200],
            'ORANGE' => ['action' => 'microcache', 'delay_ms' => 0, 'status' => 200],
            'RED'    => ['action' => 'microcache', 'delay_ms' => 0, 'status' => 503, 'block_on_miss' => true, 'retry_after' => 120],
            default  => ['action' => 'pass', 'delay_ms' => 0, 'status' => 200],
        };
    }

    /** @return array<string,mixed> */
    private function aggressive(string $tier): array
    {
        return match ($tier) {
            'GREEN'  => ['action' => 'delay', 'delay_ms' => $this->jitter(200, 800), 'status' => 200],
            'YELLOW' => ['action' => 'delay', 'delay_ms' => $this->jitter($this->delays['aggressive_min'], $this->delays['aggressive_max']), 'status' => 200],
            'ORANGE' => ['action' => 'microcache', 'delay_ms' => 0, 'status' => 429, 'block_on_miss' => true, 'retry_after' => 60],
            'RED'    => ['action' => 'block', 'delay_ms' => 0, 'status' => 503, 'retry_after' => 120],
            default  => ['action' => 'pass', 'delay_ms' => 0, 'status' => 200],
        };
    }

    /** Sleeps for the planned delay (capped at 5 s). */
    public function applyDelay(int $ms): void
    {
        if ($ms > 0) {
            usleep(min(5000, $ms) * 1000);
        }
    }

    /** Sends a 429 / 503 with Retry-After. The caller stops the request afterwards. */
    public static function sendBlock(int $status, int $retryAfter = 60): void
    {
        $status = $status === 503 ? 503 : 429;
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Retry-After: ' . max(1, $retryAfter));
            header('X-UXS-BotThrottle: ' . $status);
        }
        echo $status === 503 ? "Service temporarily unavailable - please retry after {$retryAfter}s.\n" : "Rate limit exceeded - please retry after {$retryAfter}s.\n";
    }

    private function jitter(int $min, int $max): int
    {
        return $min >= $max ? $min : random_int($min, $max);
    }

    private function defaultAction(string $category): string
    {
        if ($category === 'blacklist') {
            return 'throttle_aggressive';
        }

        return Signatures::categories()[$category]['default_action'] ?? 'throttle_light';
    }
}
