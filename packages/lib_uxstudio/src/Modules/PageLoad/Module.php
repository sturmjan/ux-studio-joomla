<?php

namespace Ux1\UxStudio\Modules\PageLoad;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * Real-user page load measurement. A tiny deferred script sends a sample (Navigation Timing, first paint, LCP, CLS)
 * for a configurable share of page views to a public, rate limited route; the admin screen shows percentiles, a trend
 * and the slowest pages. Cookie-free, no IP and no user agent are stored.
 */
final class Module extends BaseModule
{
    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onBeforeCompileHead', [$this, 'assets']);
        }
    }

    /** onBeforeCompileHead: adds the beacon for a sampled share of page views. */
    public function assets(Event $event): void
    {
        $ctx  = $this->ctx();
        $rate = (float) $ctx->setting('sample_rate', 10);
        $app  = $ctx->app();
        if ($rate <= 0 || !$app->getDocument() instanceof HtmlDocument) {
            return;
        }
        if ($ctx->setting('skip_logged_in', true) && !$app->getIdentity()?->guest) {
            return;
        }
        $ctx->useScript('page-load.js', [
            'defer'         => true,
            'data-endpoint' => Uri::root(true) . '/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=page-load/beacon',
            'data-rate'     => (string) min(100, $rate),
            'data-vitals'   => $ctx->setting('vitals', true) ? '1' : '0',
        ]);
    }

    public function settingsSchema(): array
    {
        $p = 'UXS_PAGE_LOAD_';

        return [
            ['key' => 'sample_rate', 'type' => 'number', 'label' => $p . 'SAMPLE_RATE', 'help' => $p . 'SAMPLE_RATE_HELP', 'default' => 10, 'min' => 0, 'max' => 100, 'step' => 1],
            ['key' => 'vitals', 'type' => 'toggle', 'label' => $p . 'VITALS', 'help' => $p . 'VITALS_HELP', 'default' => true],
            ['key' => 'skip_logged_in', 'type' => 'toggle', 'label' => $p . 'SKIP_LOGGED_IN', 'help' => $p . 'SKIP_LOGGED_IN_HELP', 'default' => true],
            ['key' => 'slow_ms', 'type' => 'number', 'label' => $p . 'SLOW_MS', 'help' => $p . 'SLOW_MS_HELP', 'default' => 3000, 'min' => 500, 'max' => 30000, 'step' => 100],
            ['key' => 'retention_days', 'type' => 'number', 'label' => $p . 'RETENTION_DAYS', 'help' => $p . 'RETENTION_DAYS_HELP', 'default' => 30, 'min' => 1, 'max' => 365],
        ];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_page_load` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `path` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`device` VARCHAR(10) NOT NULL DEFAULT \'unknown\', `nav_type` VARCHAR(16) NOT NULL DEFAULT \'\', '
                . '`ttfb_ms` INT UNSIGNED NOT NULL DEFAULT 0, `fcp_ms` INT UNSIGNED NOT NULL DEFAULT 0, `lcp_ms` INT UNSIGNED NOT NULL DEFAULT 0, '
                . '`dcl_ms` INT UNSIGNED NOT NULL DEFAULT 0, `load_ms` INT UNSIGNED NOT NULL DEFAULT 0, `cls_milli` INT UNSIGNED NOT NULL DEFAULT 0, '
                . '`bytes` INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `idx_created` (`created`), KEY `idx_path` (`path`(100))'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_page_load', 'column' => 'created', 'days' => fn (): int => max(1, min(365, (int) $this->ctx()->setting('retention_days', 30)))]];
    }

    // ------------------------------------------------------------------ public beacon

    public function publicRoutes(): array
    {
        return [
            // Anonymous browser beacon (same-origin check by the core). The core limiter counts the TCP peer, which behind a
            // proxy is one shared address, so this is only a flood backstop; the per-visitor cap is applied in beacon().
            'POST beacon' => ['handler' => [$this, 'beacon'], 'auth' => 'none', 'limit' => [20000, 3600], 'max_body' => 2048],
        ];
    }

    public function beacon(ApiRequest $r): array
    {
        $ctx = $this->ctx();
        try {
            if ((float) $ctx->setting('sample_rate', 10) <= 0 || Stats::isBot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
                return [];
            }
            // At most 40 samples per visitor and 10 minutes (a visitor browsing quickly is still well below).
            if ((new RateLimiter($ctx->db()))->hitClient('page-load:beacon', 40, 600) > 0) {
                return [];
            }
            $this->stats()->record($r->body());
        } catch (\Throwable $e) {
            // Measuring must never produce an error for the visitor.
            Log::add('Page load beacon failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }

        return [];
    }

    // ------------------------------------------------------------------ admin API

    public function routes(): array
    {
        return [
            'GET summary'       => [$this, 'summary'],
            'GET pages'         => [$this, 'pages'],
            'POST clear'        => ['handler' => [$this, 'clear'], 'acl' => 'core.admin'],
        ];
    }

    private function stats(): Stats
    {
        return new Stats($this->ctx()->db(), max(500, (int) $this->ctx()->setting('slow_ms', 3000)));
    }

    /** GET page-load/summary?period=7d */
    public function summary(ApiRequest $r): array
    {
        return $this->stats()->summary($r->string('period', '7d')) + ['sample_rate' => (float) $this->ctx()->setting('sample_rate', 10)];
    }

    /** GET page-load/pages?period=7d&sort=load_ms&min=1 */
    public function pages(ApiRequest $r): array
    {
        return $this->stats()->pages($r->string('period', '7d'), $r->string('sort', 'load_ms'), $r->int('min', 1));
    }

    /** POST page-load/clear (core.admin) */
    public function clear(ApiRequest $r): array
    {
        return ['deleted' => $this->stats()->clear()];
    }

    public function dashboardWidget(): ?array
    {
        $s    = $this->stats()->summary('7d');
        $load = (int) $s['metrics']['load_ms']['median'];

        return [
            'title'  => $this->ctx()->t('UXS_PAGE_LOAD_NAME'),
            'value'  => $load > 0 ? number_format($load / 1000, 1, '.', '') . ' s' : '-',
            'text'   => $this->ctx()->t('UXS_PAGE_LOAD_WIDGET_TEXT', (int) $s['samples']),
            'status' => $load === 0 ? 'ok' : ($load > $s['slow_ms'] * 2 ? 'bad' : ($load > $s['slow_ms'] ? 'warn' : 'ok')),
        ];
    }

    /** Data for UX1 Network app (CaConnector serves it once a route for this module is registered there). */
    public function caFeed(string $route, array $query, array $body): array
    {
        $stats = $this->stats();
        $period = isset($query['period']) && \is_string($query['period']) ? $query['period'] : '7d';

        return ['summary' => $stats->summary($period), 'slowest' => $stats->pages($period, 'load_ms', 2)['items']];
    }
}
