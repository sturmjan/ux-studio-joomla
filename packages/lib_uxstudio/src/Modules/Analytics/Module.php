<?php

namespace Ux1\UxStudio\Modules\Analytics;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Visitor statistics: own cookieless measurement. A small external script (CSP friendly) posts a beacon to the public
 * route analytics/hit after the page has rendered; hits are validated, bot filtered, rate limited and stored in a buffer
 * table that a daily job merges into small aggregates. Visitors are told apart by a daily hash (IP + UA + random daily
 * salt), the raw IP is never stored.
 */
final class Module extends BaseModule
{
    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onBeforeCompileHead', [$this, 'beacon']);
        }
    }

    /** onBeforeCompileHead: add the beacon script to public HTML pages. */
    public function beacon(Event $event): void
    {
        $ctx = $this->ctx();
        $app = $ctx->app();
        $doc = $app->getDocument();
        if (!$doc instanceof HtmlDocument || $app->getInput()->getCmd('format', 'html') !== 'html') {
            return;
        }
        // Staff browsing their own site would only distort the numbers.
        if ($ctx->setting('exclude_staff', true)) {
            $user = $app->getIdentity();
            if ($user && !$user->guest && $user->authorise('core.manage', 'com_uxstudio')) {
                return;
            }
        }

        $endpoint = Uri::root() . 'index.php?option=com_uxstudio&task=public.dispatch&format=json&route=analytics/hit';
        $ctx->useScript('analytics.js', [
            'defer'         => true,
            'data-endpoint' => $endpoint,
            'data-dnt'      => $ctx->setting('respect_dnt', false) ? '1' : '0',
        ]);
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'exclude_staff', 'type' => 'toggle', 'label' => 'UXS_ANALYTICS_EXCLUDE_STAFF', 'help' => 'UXS_ANALYTICS_EXCLUDE_STAFF_HELP', 'default' => true],
            ['key' => 'filter_bots', 'type' => 'toggle', 'label' => 'UXS_ANALYTICS_FILTER_BOTS', 'help' => 'UXS_ANALYTICS_FILTER_BOTS_HELP', 'default' => true],
            ['key' => 'respect_dnt', 'type' => 'toggle', 'label' => 'UXS_ANALYTICS_RESPECT_DNT', 'help' => 'UXS_ANALYTICS_RESPECT_DNT_HELP', 'default' => false],
            ['key' => 'trust_proxy', 'type' => 'toggle', 'label' => 'UXS_ANALYTICS_TRUST_PROXY', 'help' => 'UXS_ANALYTICS_TRUST_PROXY_HELP', 'default' => false],
            ['key' => 'exclude_paths', 'type' => 'textarea', 'label' => 'UXS_ANALYTICS_EXCLUDE_PATHS', 'help' => 'UXS_ANALYTICS_EXCLUDE_PATHS_HELP', 'default' => '', 'maxlength' => 2000, 'placeholder' => '/cart'],
            ['key' => 'retention_days', 'type' => 'number', 'label' => 'UXS_ANALYTICS_RETENTION', 'help' => 'UXS_ANALYTICS_RETENTION_HELP', 'default' => 730, 'min' => 0, 'max' => 3650],
        ];
    }

    public function routes(): array
    {
        return [
            'GET summary' => [$this, 'summary'],
            'GET geoip'   => [$this, 'geoipGet'],
            'POST geoip'  => ['handler' => [$this, 'geoipSet'], 'acl' => 'core.admin'],
        ];
    }

    public function publicRoutes(): array
    {
        return [
            // Anonymous browser beacon: same-origin check by the core. The core limiter counts the TCP peer, which behind
            // Cloudflare / a reverse proxy is one shared edge address, so this cap is only a flood backstop; the per-visitor
            // limit is the BotThrottle guard in Tracker, which uses the proxy-aware client IP.
            'POST hit' => ['handler' => [$this, 'hit'], 'auth' => 'none', 'limit' => [30000, 3600], 'max_body' => 4096],
        ];
    }

    public function migrations(): array
    {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_analytics_hits` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `path` VARCHAR(255) NOT NULL, '
                . '`referer_host` VARCHAR(120) NULL, `visitor_hash` CHAR(64) NULL, `device` VARCHAR(10) NULL, `browser` VARCHAR(24) NULL, `os` VARCHAR(16) NULL, '
                . '`lang` VARCHAR(8) NULL, `country` CHAR(2) NULL, `day` DATE NOT NULL, `ts` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `idx_day` (`day`), KEY `idx_ts` (`ts`))' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_analytics_daily` (`day` DATE NOT NULL, `views` INT UNSIGNED NOT NULL DEFAULT 0, `visitors` INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (`day`))' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_analytics_daily_pages` (`day` DATE NOT NULL, `path` VARCHAR(190) NOT NULL, `views` INT UNSIGNED NOT NULL DEFAULT 0, `visitors` INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (`day`, `path`))' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_analytics_daily_refs` (`day` DATE NOT NULL, `referer_host` VARCHAR(120) NOT NULL, `views` INT UNSIGNED NOT NULL DEFAULT 0, `visitors` INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (`day`, `referer_host`))' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_analytics_daily_dims` (`day` DATE NOT NULL, `dim` VARCHAR(12) NOT NULL, `val` VARCHAR(48) NOT NULL, `views` INT UNSIGNED NOT NULL DEFAULT 0, `visitors` INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (`day`, `dim`, `val`))' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_analytics_salt` (`day` DATE NOT NULL, `salt` CHAR(32) NOT NULL, PRIMARY KEY (`day`))' . $tail,
            ],
        ];
    }

    public function tasks(): array
    {
        return [
            'rollup' => ['label' => 'UXS_ANALYTICS_JOB_ROLLUP', 'callable' => [$this, 'rollup'], 'interval' => 'P1D'],
            'geoip'  => ['label' => 'UXS_ANALYTICS_JOB_GEOIP', 'callable' => [$this, 'geoipRefresh'], 'interval' => 'P7D'],
        ];
    }

    public function retention(): array
    {
        $days = fn (): int => (int) $this->ctx()->setting('retention_days', 730);

        return [
            // Last-resort cap for the raw buffer. Deliberately far above what one rollup run handles (90 days) so raw hits
            // are never purged before they were aggregated (the rollup empties the buffer itself).
            ['table' => '#__uxstudio_analytics_hits', 'column' => 'ts', 'days' => 400],
            ['table' => '#__uxstudio_analytics_daily', 'column' => 'day', 'days' => $days],
            ['table' => '#__uxstudio_analytics_daily_pages', 'column' => 'day', 'days' => $days],
            ['table' => '#__uxstudio_analytics_daily_refs', 'column' => 'day', 'days' => $days],
            ['table' => '#__uxstudio_analytics_daily_dims', 'column' => 'day', 'days' => $days],
        ];
    }

    public function dashboardWidget(): ?array
    {
        $today = $this->stats()->today();

        return ['title' => $this->ctx()->t('UXS_ANALYTICS_WIDGET_TITLE'), 'value' => $today['visitors'], 'text' => $this->ctx()->t('UXS_ANALYTICS_WIDGET_TEXT', $today['views']), 'status' => 'ok'];
    }

    /** POST analytics/hit (public). Always answers ok: a dropped hit (bot, excluded path, ban) must not be distinguishable. */
    public function hit(ApiRequest $r): array
    {
        try {
            (new Tracker($this->ctx()->db(), $this->ctx()->settings()))->record($r->string('path'), $r->string('ref'));
        } catch (\Throwable $e) {
            // Statistics must never break the site; the failure is logged by the core only for API errors.
            \Joomla\CMS\Log\Log::add('Analytics hit failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'uxstudio');
        }

        return [];
    }

    /** GET analytics/summary?period=7d */
    public function summary(ApiRequest $r): array
    {
        return $this->stats()->summary($r->string('period', '7d'));
    }

    /** Scheduler job analytics.rollup */
    public function rollup(): string
    {
        return $this->stats()->rollup() . ' day(s) aggregated';
    }

    /** Scheduler job analytics.geoip: refresh the database when it is enabled and older than 30 days. */
    public function geoipRefresh(): string
    {
        if (!GeoIp::isEnabled()) {
            return 'GeoIP disabled';
        }
        $updated = strtotime((string) (GeoIp::status()['updated'] ?? ''));
        if (GeoIp::isReady() && $updated && time() - $updated < 30 * 86400) {
            return 'GeoIP is up to date';
        }
        [$ok, $msg] = GeoIp::update();

        return ($ok ? 'GeoIP updated: ' : 'GeoIP failed: ') . $msg;
    }

    /** GET analytics/geoip */
    public function geoipGet(ApiRequest $r): array
    {
        $s = GeoIp::status();

        return ['enabled' => GeoIp::isEnabled(), 'ready' => GeoIp::isReady(), 'rows' => (int) ($s['rows'] ?? 0), 'updated' => (string) ($s['updated'] ?? ''),
            'can_manage' => $this->ctx()->app()->getIdentity()?->authorise('core.admin', 'com_uxstudio') === true];
    }

    /** POST analytics/geoip {action: update|disable} (core.admin) */
    public function geoipSet(ApiRequest $r): array
    {
        $action = $r->string('action');
        if (!\in_array($action, ['update', 'disable'], true)) {
            throw new ApiException($this->ctx()->t('UXS_ANALYTICS_ERR_ACTION'), 422, 'invalid_action');
        }
        $message = '';
        if ($action === 'disable') {
            GeoIp::setEnabled(false);
        } else {
            [$ok, $message] = GeoIp::update();
            if (!$ok) {
                throw new ApiException($this->ctx()->t('UXS_ANALYTICS_ERR_GEOIP', $message), 502, 'geoip_failed');
            }
        }

        return $this->geoipGet($r) + ['message' => $message];
    }

    private function stats(): Stats
    {
        return new Stats($this->ctx()->db());
    }
}
