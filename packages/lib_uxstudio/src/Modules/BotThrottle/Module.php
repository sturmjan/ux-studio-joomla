<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\ClientIp;

\defined('_JEXEC') or die;

/**
 * Bot Throttle: detects search engine / AI / SEO bots by User-Agent and throttles them with the live server load
 * (GREEN .. RED), keeps a hard per-IP rate limit with automatic bans, an optional microcache for bots and a notification
 * when an address gets banned. Also offers Guard::exceeded() to other modules.
 *
 * Frontend only: the administrator, the UX Studio endpoints (com_uxstudio) and the Central App channel are never touched.
 */
final class Module extends BaseModule
{
    private const CONTEXT = 'site';

    /** @var array<string,mixed>|null */
    private ?array $settings = null;
    private ?Store $store = null;
    private ?Kv $kv = null;
    private ?string $ip = null;
    /** @var string[] */
    private array $trusted = [];

    /** True when this request counts for the load metrics. */
    private bool $tracked = false;
    /** @var array{category:string,name:string,verified:bool}|null */
    private ?array $detected = null;
    /** @var array<string,mixed>|null */
    private ?array $plan = null;
    /** @var array{tier:string,score:float}|null */
    private ?array $tier = null;
    private bool $logged = false;
    /** Milliseconds this request was deliberately delayed (excluded from the load metrics: throttling must not raise the load score). */
    private int $delayed = 0;

    /** Bot rules, limits and the ban list are administrator business (they decide who may reach the site). */
    public function acl(): string
    {
        return 'core.admin';
    }

    public function boot(ModuleContext $ctx): void
    {
        if (!$ctx->isSite()) {
            return;
        }
        $ctx->on('onAfterRoute', [$this, 'evaluate'], Priority::HIGH);
        $ctx->on('onAfterRespond', [$this, 'finish'], Priority::LOW);
    }

    /** @return array<string,mixed> */
    private function s(): array
    {
        return $this->settings ??= $this->ctx()->settings();
    }

    private function store(): Store
    {
        return $this->store ??= new Store($this->ctx()->db());
    }

    private function kv(): Kv
    {
        return $this->kv ??= new Kv($this->ctx()->db());
    }

    private function sampler(): LoadSampler
    {
        $s = $this->s();

        return new LoadSampler($this->ctx()->db(), $this->kv(), ['yellow' => (int) $s['threshold_yellow'], 'orange' => (int) $s['threshold_orange'], 'red' => (int) $s['threshold_red']]);
    }

    /* ------------------------------------------------------------- client IP */

    private function clientIp(): string
    {
        if ($this->ip === null) {
            $s    = $this->s();
            $mode = (string) ($s['ip_source'] ?? 'inherit');
            if ($mode === 'inherit') {
                // Core setting (Settings screen of UX Studio).
                $this->ip      = ClientIp::current();
                $this->trusted = ClientIp::trusted();
            } else {
                $this->trusted = ClientIp::parseList((string) ($s['trusted_proxies'] ?? ''));
                $this->ip      = ClientIp::resolve(\in_array($mode, ClientIp::MODES, true) ? $mode : 'cloudflare', $this->trusted);
            }
        }

        return $this->ip !== '' ? $this->ip : '0.0.0.0';
    }

    /** A Cloudflare edge / trusted proxy address is a shared hop, never a single visitor: no rate limit, no ban for it. */
    private function isSharedHop(string $ip): bool
    {
        // Also loopback / private addresses: they are reported, never throttled or banned automatically.
        return ClientIp::isProtected($ip, $this->trusted);
    }

    private function debugHeaders(): bool
    {
        return (bool) $this->ctx()->app()->get('debug');
    }

    private function hash(string $ip): string
    {
        return Guard::hash($ip);
    }

    /** Keyed fleet hash: the SAME address gives the SAME value on every site that shares the secret. '' = not configured. */
    private function reportHash(string $ip): string
    {
        $secret = (string) ($this->s()['central_report_secret'] ?? '');

        return $secret === '' ? '' : hash_hmac('sha256', $ip, $secret);
    }

    /** @return string[] */
    private function lines(string $key): array
    {
        $val = (string) ($this->s()[$key] ?? '');

        return $val === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $val) ?: [])));
    }

    /* ------------------------------------------------------------- the gate */

    /** onAfterRoute (frontend): ban check, hard rate limit, bot detection and the throttle plan. */
    public function evaluate(Event $event): void
    {
        $app   = $this->ctx()->app();
        $input = $app->getInput();
        if (!\in_array(strtoupper($input->getMethod()), ['GET', 'HEAD'], true)) {
            return;
        }
        // UX Studio's own endpoints and the Central App channel must never be throttled.
        if ($input->getCmd('option') === 'com_uxstudio' || $input->get('uxstudio_node') !== null) {
            return;
        }
        $identity = $app->getIdentity();
        if ($identity && !$identity->guest && ($identity->authorise('core.manage') || $identity->authorise('core.admin'))) {
            return;
        }

        $this->tracked = true;
        $ip            = $this->clientIp();
        $ua            = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $s             = $this->s();
        $hash          = $this->hash($ip);

        try {
            if ($this->store()->isBanned($hash, self::CONTEXT)) {
                $this->stop(429, 3600);
            }

            $limit = (int) $s['requests_per_minute'];
            if ($limit > 0 && !$this->isSharedHop($ip) && $this->overLimit($hash, $limit)) {
                $this->store()->log(['ip_hash' => $hash, 'user_agent' => $ua, 'action' => 'ratelimit', 'url' => $this->url(), 'response_status' => 429]);
                if (!empty($s['ban_enabled']) && $this->strikeLimitReached($hash)) {
                    $this->ban($hash, self::CONTEXT, 'rate limit', (int) $s['ban_minutes'] * 60, $ip);
                }
                $this->stop((int) $s['block_response_code'] === 503 ? 503 : 429, 60);
            }

            // Logged-in visitors are never bot-throttled (only the hard cap above).
            if ($identity && !$identity->guest) {
                return;
            }

            $detectorConfig = [
                'whitelist_ua' => $this->lines('whitelist_ua'),
                'whitelist_ip' => $this->lines('whitelist_ip'),
                'blacklist_ua' => $this->lines('blacklist_ua'),
                'verify_rdns'  => !empty($s['verify_rdns']),
            ];
            $detected = (new Detector($detectorConfig, $this->kv()))->detect($ua, $ip);
            if (!$detected) {
                return;
            }

            $tier = $this->sampler()->currentTier();
            if ($tier['tier'] === 'RED' && $detected['category'] === 'search_engines' && empty($s['verify_rdns'])) {
                // Under full load a spoofed "Googlebot" must not get the generous search engine treatment: prove it.
                $detected = (new Detector(['verify_rdns' => true] + $detectorConfig, $this->kv()))->detect($ua, $ip) ?? $detected;
            }
            $throttler = new Throttler($this->categoryRules(), $this->delayBounds());
            $plan      = $throttler->plan($detected['category'], $tier['tier']);

            $this->detected = $detected;
            $this->plan     = $plan;
            $this->tier     = $tier;

            if ($plan['action'] === 'block') {
                $this->logFinal($ip, $ua, (int) $plan['status']);
                $this->stop((int) $plan['status'], (int) ($plan['retry_after'] ?? 60));
            }

            if ($plan['action'] === 'microcache' && !empty($s['microcache'])) {
                $cache  = new Microcache();
                $key    = $cache->key((string) ($_SERVER['HTTP_HOST'] ?? ''), (string) ($_SERVER['REQUEST_URI'] ?? ''), $detected['category']);
                $cached = $cache->get($key);
                if ($cached !== null) {
                    $this->logFinal($ip, $ua, 200);
                    if (!headers_sent()) {
                        header('Content-Type: text/html; charset=utf-8');
                        header('X-Content-Type-Options: nosniff');
                        header('X-UXS-BotThrottle: microcache-hit');
                        if ($this->debugHeaders()) {
                            header('X-UXS-Tier: ' . $tier['tier']);
                        }
                    }
                    echo $cached;
                    $app->close();
                }
                if (!empty($plan['block_on_miss'])) {
                    $this->logFinal($ip, $ua, (int) $plan['status']);
                    $this->stop((int) $plan['status'], (int) ($plan['retry_after'] ?? 60));
                }
                $this->captureForCache($detected['category'], $tier['tier']);
            } elseif ($plan['action'] === 'microcache' && !empty($plan['block_on_miss'])) {
                // Microcache switched off: a plan that depends on it degrades to a plain refusal.
                $this->logFinal($ip, $ua, (int) $plan['status']);
                $this->stop((int) $plan['status'], (int) ($plan['retry_after'] ?? 60));
            }

            if ($plan['action'] === 'delay' && (int) $plan['delay_ms'] > 0) {
                $throttler->applyDelay((int) $plan['delay_ms']);
                $this->delayed = min(5000, (int) $plan['delay_ms']);
            }
            if (!headers_sent()) {
                header('X-UXS-BotThrottle: ' . $plan['action']);
                if ($this->debugHeaders()) {
                    header('X-UXS-Tier: ' . $tier['tier']);
                }
            }
        } catch (\Throwable $e) {
            // Tables missing / DB trouble: never take the site down because of a throttle.
            Log::add('Bot Throttle skipped: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }
    }

    /** onAfterRespond: load metrics (sampled) and the final log line of a detected bot. */
    public function finish(Event $event): void
    {
        if (!$this->tracked) {
            return;
        }
        try {
            if (random_int(1, LoadSampler::SAMPLE_RATE) === 1) {
                $start   = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
                $db      = $this->ctx()->db();
                $queries = method_exists($db, 'getCount') ? (int) $db->getCount() : 0;
                $this->sampler()->record(max(0.0, (microtime(true) - $start) * 1000 - $this->delayed), $queries, memory_get_peak_usage(true) / 1048576);
            }
            if ($this->detected && $this->plan) {
                $this->logFinal($this->clientIp(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), (int) http_response_code());
            }
        } catch (\Throwable $e) {
        }
    }

    private function url(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? '');
    }

    private function stop(int $status, int $retryAfter): never
    {
        Throttler::sendBlock($status, $retryAfter);
        $this->ctx()->app()->close();
        exit;
    }

    private function logFinal(string $ip, string $ua, int $status): void
    {
        if ($this->logged || !$this->detected || !$this->plan) {
            return;
        }
        $this->logged = true;
        $this->store()->log([
            'ip_hash' => $this->hash($ip), 'user_agent' => $ua, 'action' => (string) $this->plan['action'], 'bot_category' => $this->detected['category'], 'bot_name' => $this->detected['name'],
            'tier' => (string) ($this->tier['tier'] ?? 'GREEN'), 'delay_ms' => (int) $this->plan['delay_ms'], 'url' => $this->url(), 'load_score' => (float) ($this->tier['score'] ?? 0), 'response_status' => $status,
        ]);
    }

    /** True when this address made more than $limit requests in the current minute. */
    private function overLimit(string $hash, int $limit): bool
    {
        if (\function_exists('apcu_inc') && \function_exists('apcu_enabled') && apcu_enabled()) {
            $key = 'uxs_bt_rl_' . $hash . '_' . intdiv(time(), 60);
            apcu_add($key, 0, 120);

            return (int) apcu_inc($key) > $limit;
        }

        return Guard::limiter()->hit('bt:rl:' . $hash, $limit, 60) > 0;
    }

    /** More blocked requests than allowed in 10 minutes: time for a real ban. */
    private function strikeLimitReached(string $hash): bool
    {
        return Guard::limiter()->hit('bt:strike:' . $hash, max(1, (int) $this->s()['ban_after_blocks']), 600) > 0;
    }

    /** Creates a ban, logs it and (throttled) e-mails the administrator. */
    private function ban(string $hash, string $context, string $reason, int $seconds, string $ip): void
    {
        if ($this->isSharedHop($ip)) {
            return;
        }
        $this->store()->ban($hash, $context, $reason, $seconds, $this->reportHash($ip));
        $this->store()->log(['ip_hash' => $hash, 'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'action' => 'ban', 'bot_category' => 'rate-limit', 'bot_name' => $context, 'url' => $this->url(), 'response_status' => 429]);
        if (!empty($this->s()['notify_ban'])) {
            $this->notifyBan($context, $hash, $seconds);
        }
    }

    /** Burst guard entry used by Guard::exceeded(). */
    public function burst(string $context, int $max, int $window, int $banFor): bool
    {
        $ip   = $this->clientIp();
        $hash = $this->hash($ip);
        if ($this->store()->isBanned($hash, $context)) {
            return true;
        }
        if ($this->isSharedHop($ip) || Guard::limiter()->hit('bt:burst:' . $context . ':' . $hash, max(1, $max), max(1, $window)) === 0) {
            return false;
        }
        $this->ban($hash, $context, 'burst limit', $banFor, $ip);

        return true;
    }

    private function notifyBan(string $context, string $hash, int $seconds): void
    {
        try {
            if (Guard::limiter()->hit('bot-throttle:notify', 1, 300) > 0) {
                return;
            }
            $app = $this->ctx()->app();
            $to  = trim((string) ($this->s()['notify_email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $to = (string) $app->get('mailfrom', '');
            }
            if ($to === '') {
                return;
            }
            $mailer = \Ux1\UxStudio\Core\Mail::create();
            $mailer->addRecipient($to);
            $mailer->setSubject(Text::sprintf('UXS_BOT_THROTTLE_MAIL_SUBJECT', (string) $app->get('sitename', 'Joomla')));
            $mailer->setBody(Text::sprintf('UXS_BOT_THROTTLE_MAIL_BODY', $context, (int) round($seconds / 60), $hash));
            $mailer->isHtml(false);
            $mailer->Send();
        } catch (\Throwable $e) {
            Log::add('Bot Throttle notification not sent: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }
    }

    /** Buffers the rendered page so it can be stored in the microcache when the response is flushed. */
    private function captureForCache(string $category, string $tier): void
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $ttl  = match ($tier) {
            'YELLOW' => 300,
            'ORANGE' => 3600,
            'RED'    => 86400,
            default  => 60,
        };
        ob_start(static function (string $buffer) use ($host, $uri, $category, $ttl): string {
            if (\strlen($buffer) > 500 && (int) http_response_code() === 200 && stripos($buffer, '</html>') !== false) {
                $cache = new Microcache(null, $ttl);
                $cache->set($cache->key($host, $uri, $category), $buffer);
            }

            return $buffer;
        });
    }

    /** @return array<string,string> */
    private function categoryRules(): array
    {
        $rules = [];
        foreach (array_keys(Signatures::categories()) as $id) {
            $action = (string) ($this->s()['rule_' . $id] ?? '');
            if ($action !== '') {
                $rules[$id] = $action;
            }
        }

        return $rules;
    }

    /** @return array<string,int> */
    private function delayBounds(): array
    {
        $s = $this->s();

        return [
            'min_delay_ms' => (int) $s['min_delay_ms'], 'light_min' => (int) $s['light_min'], 'light_max' => (int) $s['light_max'],
            'aggressive_min' => (int) $s['aggressive_min'], 'aggressive_max' => (int) $s['aggressive_max'],
        ];
    }

    /* -------------------------------------------------------------- settings */

    public function settingsSchema(): array
    {
        $p       = 'UXS_BOT_THROTTLE_';
        $actions = [
            'pass'                => $p . 'ACT_PASS',
            'pass_with_min_delay' => $p . 'ACT_MIN_DELAY',
            'throttle_light'      => $p . 'ACT_LIGHT',
            'throttle_aggressive' => $p . 'ACT_AGGRESSIVE',
            'block'               => $p . 'ACT_BLOCK',
        ];
        $schema = [
            ['key' => 'ip_source', 'section' => $p . 'SEC_GENERAL', 'type' => 'select', 'label' => $p . 'F_IP_SOURCE', 'help' => $p . 'F_IP_SOURCE_HELP', 'default' => 'inherit',
             'options' => ['inherit' => $p . 'IP_INHERIT', 'none' => $p . 'IP_NONE', 'cloudflare' => $p . 'IP_CLOUDFLARE', 'xff' => $p . 'IP_XFF']],
            ['key' => 'trusted_proxies', 'section' => $p . 'SEC_GENERAL', 'type' => 'textarea', 'label' => $p . 'F_TRUSTED', 'help' => $p . 'F_TRUSTED_HELP', 'default' => '', 'maxlength' => 2000,
             'show_if' => ['key' => 'ip_source', 'in' => ['cloudflare', 'xff']]],
            ['key' => 'verify_rdns', 'section' => $p . 'SEC_GENERAL', 'type' => 'toggle', 'label' => $p . 'F_RDNS', 'help' => $p . 'F_RDNS_HELP', 'default' => false],
            ['key' => 'requests_per_minute', 'section' => $p . 'SEC_GENERAL', 'type' => 'number', 'label' => $p . 'F_RPM', 'help' => $p . 'F_RPM_HELP', 'default' => 120, 'min' => 0, 'max' => 100000],
            ['key' => 'block_response_code', 'section' => $p . 'SEC_GENERAL', 'type' => 'select', 'label' => $p . 'F_BLOCK_CODE', 'default' => '429', 'options' => ['429' => $p . 'CODE_429', '503' => $p . 'CODE_503']],
            ['key' => 'ban_enabled', 'section' => $p . 'SEC_GENERAL', 'type' => 'toggle', 'label' => $p . 'F_BAN', 'help' => $p . 'F_BAN_HELP', 'default' => true],
            ['key' => 'ban_after_blocks', 'section' => $p . 'SEC_GENERAL', 'type' => 'number', 'label' => $p . 'F_BAN_AFTER', 'help' => $p . 'F_BAN_AFTER_HELP', 'default' => 20, 'min' => 1, 'max' => 10000, 'show_if' => ['key' => 'ban_enabled', 'truthy' => true]],
            ['key' => 'ban_minutes', 'section' => $p . 'SEC_GENERAL', 'type' => 'number', 'label' => $p . 'F_BAN_MINUTES', 'default' => 60, 'min' => 1, 'max' => 525600, 'show_if' => ['key' => 'ban_enabled', 'truthy' => true]],
            ['key' => 'notify_ban', 'section' => $p . 'SEC_GENERAL', 'type' => 'toggle', 'label' => $p . 'F_NOTIFY', 'help' => $p . 'F_NOTIFY_HELP', 'default' => true],
            ['key' => 'notify_email', 'section' => $p . 'SEC_GENERAL', 'type' => 'text', 'label' => $p . 'F_NOTIFY_EMAIL', 'help' => $p . 'F_NOTIFY_EMAIL_HELP', 'default' => '', 'maxlength' => 190, 'show_if' => ['key' => 'notify_ban', 'truthy' => true]],
            ['key' => 'microcache', 'section' => $p . 'SEC_GENERAL', 'type' => 'toggle', 'label' => $p . 'F_MICROCACHE', 'help' => $p . 'F_MICROCACHE_HELP', 'default' => true],
            ['key' => 'retention_days', 'section' => $p . 'SEC_GENERAL', 'type' => 'number', 'label' => $p . 'F_RETENTION', 'default' => 14, 'min' => 1, 'max' => 365],
            ['key' => 'central_report_secret', 'section' => $p . 'SEC_GENERAL', 'type' => 'password', 'label' => $p . 'F_REPORT_SECRET', 'help' => $p . 'F_REPORT_SECRET_HELP'],
        ];
        foreach (Signatures::categories() as $id => $cat) {
            $schema[] = ['key' => 'rule_' . $id, 'section' => $p . 'SEC_BOTS', 'type' => 'select', 'label' => $cat['label'], 'help' => implode(', ', array_column($cat['bots'], 'name')), 'options' => $actions, 'default' => $cat['default_action']];
        }
        foreach ([
            'threshold_yellow' => [50, 1, 100], 'threshold_orange' => [75, 1, 100], 'threshold_red' => [90, 1, 100],
            'min_delay_ms' => [0, 0, 5000], 'light_min' => [300, 0, 5000], 'light_max' => [1500, 0, 5000], 'aggressive_min' => [2000, 0, 5000], 'aggressive_max' => [5000, 0, 5000],
        ] as $key => [$default, $min, $max]) {
            $schema[] = ['key' => $key, 'section' => $p . 'SEC_LOAD', 'type' => 'number', 'label' => $p . 'F_' . strtoupper($key), 'default' => $default, 'min' => $min, 'max' => $max];
        }
        $schema[] = ['key' => 'whitelist_ua', 'section' => $p . 'SEC_LISTS', 'type' => 'textarea', 'label' => $p . 'F_WL_UA', 'help' => $p . 'F_WL_UA_HELP', 'default' => '', 'maxlength' => 4000];
        $schema[] = ['key' => 'whitelist_ip', 'section' => $p . 'SEC_LISTS', 'type' => 'textarea', 'label' => $p . 'F_WL_IP', 'help' => $p . 'F_WL_IP_HELP', 'default' => '', 'maxlength' => 4000];
        $schema[] = ['key' => 'blacklist_ua', 'section' => $p . 'SEC_LISTS', 'type' => 'textarea', 'label' => $p . 'F_BL_UA', 'help' => $p . 'F_BL_UA_HELP', 'maxlength' => 4000,
                     'default' => "AhrefsBot\nSemrushBot\nMJ12bot\nDotBot\nPetalBot\nBLEXBot\nMegaIndex\nSerpstatBot\nrogerbot\nDataForSeoBot"];

        return $schema;
    }

    /* ---------------------------------------------------------------- tables */

    public function migrations(): array
    {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_bt_log` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `ip_hash` CHAR(64) NOT NULL DEFAULT \'\', `user_agent` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`action` VARCHAR(20) NOT NULL DEFAULT \'\', `bot_category` VARCHAR(50) NOT NULL DEFAULT \'\', `bot_name` VARCHAR(100) NOT NULL DEFAULT \'\', `tier` VARCHAR(10) NOT NULL DEFAULT \'GREEN\', '
                . '`delay_ms` INT UNSIGNED NOT NULL DEFAULT 0, `url` VARCHAR(500) NOT NULL DEFAULT \'\', `load_score` FLOAT NOT NULL DEFAULT 0, `response_status` SMALLINT UNSIGNED NOT NULL DEFAULT 200, '
                . 'PRIMARY KEY (`id`), KEY `idx_created` (`created`), KEY `idx_bot` (`bot_name`)) ' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_bt_bans` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `ip_hash` CHAR(64) NOT NULL, `context` VARCHAR(100) NOT NULL DEFAULT \'site\', `reason` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`banned_at` DATETIME NOT NULL, `banned_until` DATETIME NOT NULL, `report_hash` CHAR(64) NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_lookup` (`ip_hash`, `context`, `banned_until`), KEY `idx_report` (`report_hash`, `id`)) ' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_bt_load` ('
                . '`bucket` INT UNSIGNED NOT NULL, `n` INT UNSIGNED NOT NULL DEFAULT 0, `rt_sum` DOUBLE NOT NULL DEFAULT 0, `q_sum` BIGINT UNSIGNED NOT NULL DEFAULT 0, `mem_sum` DOUBLE NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (`bucket`)) ' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_bt_kv` (`k` VARCHAR(64) NOT NULL, `v` VARCHAR(255) NOT NULL DEFAULT \'\', `expires` INT UNSIGNED NOT NULL, PRIMARY KEY (`k`), KEY `idx_expires` (`expires`)) ' . $tail,
            ],
        ];
    }

    public function retention(): array
    {
        return [
            ['table' => '#__uxstudio_bt_log', 'column' => 'created', 'days' => fn (): int => max(1, (int) $this->ctx()->setting('retention_days', 14))],
            ['table' => '#__uxstudio_bt_bans', 'column' => 'banned_at', 'days' => 90],
        ];
    }

    public function tasks(): array
    {
        return ['cleanup' => ['label' => 'UXS_BOT_THROTTLE_JOB_CLEANUP', 'callable' => [$this, 'cleanup'], 'interval' => 'PT6H']];
    }

    /** Scheduler job: drops stale load buckets, answer cache and long finished unreported bans. */
    public function cleanup(): string
    {
        $this->sampler()->purge();
        $this->kv()->purge();
        $bans = $this->store()->purgeOldBans();
        $files = 0;
        $dir   = JPATH_ROOT . '/cache/uxs-bot-cache';
        foreach (glob($dir . '/*.cache') ?: [] as $file) {
            if (filemtime($file) < time() - 2 * 86400 && @unlink($file)) {
                $files++;
            }
        }

        return "old bans removed: {$bans}, cache files removed: {$files}";
    }

    public function onDisable(): void
    {
        try {
            $db = $this->ctx()->db();
            $db->setQuery('DELETE FROM ' . $db->quoteName('#__uxstudio_bt_load'))->execute();
            $db->setQuery('DELETE FROM ' . $db->quoteName('#__uxstudio_bt_kv'))->execute();
        } catch (\Throwable $e) {
        }
    }

    public function dashboardWidget(): ?array
    {
        try {
            $stats = $this->store()->stats(24);
            $tier  = $this->sampler()->currentTier();
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'title'  => 'Bot Throttle',
            'value'  => $stats['total'],
            'text'   => Text::sprintf('UXS_BOT_THROTTLE_WIDGET', $stats['blocked'], $tier['tier']),
            'status' => $tier['tier'] === 'GREEN' ? 'ok' : ($tier['tier'] === 'YELLOW' ? 'warn' : 'bad'),
        ];
    }

    /* ------------------------------------------------------------- admin API */

    public function routes(): array
    {
        return [
            'GET overview'            => [$this, 'overview'],
            'GET log'                 => [$this, 'logList'],
            'POST log/clear'          => ['handler' => [$this, 'logClear'], 'acl' => 'core.admin'],
            'GET bans'                => [$this, 'bansList'],
            'POST bans/{id}/delete'   => ['handler' => [$this, 'bansLift'], 'acl' => 'core.admin'],
            'POST test'               => [$this, 'testUa'],
            'POST cache/clear'        => ['handler' => [$this, 'cacheClear'], 'acl' => 'core.admin'],
        ];
    }

    /** @return array<string,mixed> */
    public function overview(ApiRequest $r): array
    {
        $cats = [];
        foreach (Signatures::categories() as $id => $cat) {
            $cats[] = ['id' => $id, 'label' => Text::_($cat['label']), 'action' => (string) ($this->s()['rule_' . $id] ?? $cat['default_action']), 'bots' => array_column($cat['bots'], 'name')];
        }

        return [
            'tier'       => $this->sampler()->currentTier(),
            'stats_24h'  => $this->store()->stats(24),
            'stats_1h'   => $this->store()->stats(1),
            'cache'      => (new Microcache())->size(),
            'active_bans' => $this->store()->activeBanCount(),
            'client_ip'  => $this->clientIp(),
            'edge_warning' => ClientIp::isEdge($this->clientIp(), $this->trusted),
            'local_ip'     => ClientIp::isLocal($this->clientIp()),
            'categories' => $cats,
            'report_hash_enabled' => $this->reportHash('0.0.0.0') !== '',
        ];
    }

    /** @return array<string,mixed> */
    public function logList(ApiRequest $r): array
    {
        return ['items' => $this->store()->recent($r->int('limit', 100), [
            'category' => $r->string('category'), 'action' => $r->string('action'), 'bot_name' => $r->string('bot'),
        ])];
    }

    /** @return array<string,mixed> */
    public function logClear(ApiRequest $r): array
    {
        return ['removed' => $this->store()->clearLog()];
    }

    /** @return array<string,mixed> */
    public function bansList(ApiRequest $r): array
    {
        return $this->store()->listBans($r->int('page', 1), $r->int('per_page', 20), $r->bool('active'));
    }

    /** @return array<string,mixed> */
    public function bansLift(ApiRequest $r): array
    {
        return ['lifted' => $this->store()->unban((int) $r->param('id'))];
    }

    /** Simulates how a User-Agent / IP would be handled at the current load tier (nothing is stored). @return array<string,mixed> */
    public function testUa(ApiRequest $r): array
    {
        $ua = mb_substr($r->string('user_agent'), 0, 500);
        $ip = ClientIp::normalize($r->string('ip', '203.0.113.1')) ?: '203.0.113.1';
        $detected = (new Detector([
            'whitelist_ua' => $this->lines('whitelist_ua'), 'whitelist_ip' => $this->lines('whitelist_ip'), 'blacklist_ua' => $this->lines('blacklist_ua'), 'verify_rdns' => false,
        ], $this->kv()))->detect($ua, $ip);
        $tier = $this->sampler()->currentTier();

        return [
            'detected' => $detected !== null,
            'bot'      => $detected,
            'tier'     => $tier,
            'plan'     => $detected ? (new Throttler($this->categoryRules(), $this->delayBounds()))->plan($detected['category'], $tier['tier']) : null,
        ];
    }

    /** @return array<string,mixed> */
    public function cacheClear(ApiRequest $r): array
    {
        return ['removed' => (new Microcache())->clear()];
    }

    /* ------------------------------------------------------ Central App feed */

    /**
     * Data for the Central App connector (docs/CA-INTEGRACE.md). Route: bot-throttle/bans (?since= cursor).
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function caFeed(string $route, array $query, array $body): array
    {
        // The connector may pass the full route ("bot-throttle/bans") or the part below the module id ("bans").
        if (preg_replace('#^bot-throttle/#', '', $route) !== 'bans') {
            throw new ApiException('Unknown route.', 404, 'not_found');
        }
        $since = max(0, (int) ($query['since'] ?? 0));
        $items = $this->store()->bansSince($since, 200);
        $last  = $since;
        foreach ($items as $row) {
            $last = max($last, $row['id']);
        }

        return ['items' => $items, 'last_id' => $last];
    }
}
