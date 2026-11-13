<?php

namespace Ux1\UxStudio\Modules\CaConnector;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Version;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ClientIp;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Core\Lang;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * The site side of UX1 Network app protocol: GET/POST {site}/index.php?uxstudio_node=<route>.
 * Handled in onAfterInitialise (before Joomla routes), so it works with and without SEF URLs.
 * Every request is HMAC signed (docs/CA-INTEGRACE.md); every answer to an authenticated request is signed back.
 */
final class Node
{
    public const QUERY = 'uxstudio_node';

    /** route => [module id, HTTP method] of the routes served by another module's caFeed(). */
    private const FEEDS = [
        'email-health'      => ['email-health', 'GET'],
        'email-log'         => ['email-log', 'GET'],
        'security-monitor'  => ['security-optimization', 'GET'],
        'ip-bans'           => ['security-optimization', 'GET'],
        'ip-bans/sync'      => ['security-optimization', 'POST'],
        'vulnerabilities'   => ['vulnerability-scanner', 'GET'],
        'bot-throttle/bans' => ['bot-throttle', 'GET'],
    ];

    /** capability => module id that supplies it (the route family is listed in FEEDS). */
    private const CAPABILITY_MODULES = [
        'email_health'     => 'email-health',
        'email_log'        => 'email-log',
        'security_monitor' => 'security-optimization',
        'ip_bans'          => 'security-optimization',
        'vulnerabilities'  => 'vulnerability-scanner',
        'bot_bans'         => 'bot-throttle',
    ];

    private string $key = '';
    private string $nonce = '';

    public function __construct(private ModuleContext $ctx, private Store $store)
    {
    }

    /** Capabilities announced in ping: what this site can really deliver right now. @return string[] */
    public function capabilities(): array
    {
        $registry = $this->ctx->kernel()->registry();
        $caps     = ['updates', 'php_errors'];
        if ($this->ctx->setting('sso_mapping_strategy', 'auto') !== 'disabled') {
            $caps[] = 'sso';
        }
        foreach (self::CAPABILITY_MODULES as $cap => $module) {
            if ($registry->isEnabled($module)) {
                $caps[] = $cap;
            }
        }

        return $caps;
    }

    /** Entry point: answers and terminates the request. */
    public function handle(): void
    {
        $app = $this->ctx->app();
        foreach (headers_list() as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                header_remove('Set-Cookie');
                break;
            }
        }

        $route = \is_string($_GET[self::QUERY] ?? null) ? trim($_GET[self::QUERY], '/') : '';
        if (!preg_match('#^[a-z0-9][a-z0-9/_-]{0,63}$#', $route)) {
            $this->send(404, ['message' => 'Unknown route']);
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!\in_array($method, ['GET', 'POST'], true)) {
            $this->send(405, ['message' => 'Method not allowed']);
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
            $this->send(413, ['message' => 'Request too large']);
        }

        $this->checkAllowlist();
        $body = (string) file_get_contents('php://input', false, null, 0, 1048577);
        if (\strlen($body) > 1048576) {
            $this->send(413, ['message' => 'Request too large']);
        }
        $this->authenticate($method, $body);

        try {
            $this->dispatch($route, $method, $body);
        } catch (ApiException $e) {
            $this->send($e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500, ['message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::add(sprintf('ca-connector route %s failed: %s', $route, $e->getMessage()), Log::ERROR, 'uxstudio');
            $this->send(500, ['message' => 'Internal error']);
        }
        $app->close();
    }

    private function checkAllowlist(): void
    {
        $raw     = (string) $this->ctx->setting('node_allowed_ips', '');
        $allowed = array_filter(array_map('trim', preg_split('/[\r\n,\s]+/', $raw) ?: []));
        // The real client address (Core\ClientIp honours the proxy mode), with CIDR support; behind Cloudflare REMOTE_ADDR is the proxy.
        if ($allowed && !ClientIp::matches(ClientIp::current(), $allowed)) {
            $this->send(403, ['message' => 'This IP address is not on the allowlist']);
        }
    }

    /** Verifies headers, timestamp window, signature and one-time nonce. Ends the request on failure. */
    private function authenticate(string $method, string $body): void
    {
        $signature = (string) ($_SERVER['HTTP_X_CONTENT_SYNC_SIGNATURE'] ?? '');
        $timestamp = (string) ($_SERVER['HTTP_X_CONTENT_SYNC_TIMESTAMP'] ?? '');
        $nonce     = (string) ($_SERVER['HTTP_X_CONTENT_SYNC_NONCE'] ?? '');
        $key       = (string) $this->ctx->setting('node_api_key', '');

        if ($signature === '' || $timestamp === '' || $nonce === '') {
            $this->send(401, ['message' => 'Missing authentication headers']);
        }
        if ($key === '') {
            $this->send(403, ['message' => 'This site has no API key configured']);
        }
        if (!ctype_xdigit($signature) || !ctype_alnum($nonce) || !ctype_digit($timestamp) || \strlen($nonce) > 64) {
            $this->send(401, ['message' => 'Malformed authentication headers']);
        }
        if (abs(time() - (int) $timestamp) > Hmac::TOLERANCE) {
            $this->failed('Timestamp outside window');
        }
        $expected = Hmac::sign($method, Hmac::currentUrl(), $body, (int) $timestamp, $nonce, $key);
        if (!hash_equals($expected, $signature)) {
            $this->failed('Invalid signature');
        }
        if (!$this->store->consumeNonce($nonce)) {
            $this->failed('Nonce already used');
        }
        $this->key   = $key;
        $this->nonce = $nonce;
        CaClient::rememberSiteUrl($this->store);
    }

    /**
     * Counts a failed authentication per IP. A VALID signature is never throttled (forging a 256 bit HMAC is not a
     * realistic threat, and a failure budget must not lock UX1 Network app out), only repeated failures get a 429.
     */
    private function failed(string $message): never
    {
        $ip      = ClientIp::current();
        $retry   = (new RateLimiter($this->ctx->db()))->hit('ca-connector:node-fail:' . $ip, 60, 60);
        // Anonymous callers must not fill the table: log at most 3 failures per IP and 5 minutes, nothing once throttled.
        if ($retry === 0 && (new RateLimiter($this->ctx->db()))->hit('ca-connector:node-fail-log:' . $ip, 3, 300) === 0) {
            $this->store->log('node_auth', false, $message);
        }
        if ($retry > 0) {
            header('Retry-After: ' . $retry);
            $this->send(429, ['message' => 'Too many requests']);
        }
        $this->send(401, ['message' => $message]);
    }

    /** @param string $route @param string $method */
    private function dispatch(string $route, string $method, string $body): void
    {
        $json  = $body !== '' ? json_decode($body, true) : [];
        $json  = \is_array($json) ? $json : [];
        $query = $_GET;
        unset($query[self::QUERY]);

        // Any signed call other than the pairing challenge proves UX1 Network app accepted this site (the site's own self-check does not count).
        if (($_SERVER['HTTP_X_UXS_SELFCHECK'] ?? '') === '') {
            if ($route !== 'ping' && $this->store->get('paired_at') === '') {
                $this->store->set('paired_at', gmdate('Y-m-d H:i:s'));
            }
            $this->store->set('last_contact', gmdate('Y-m-d H:i:s'));
        }

        switch ($route) {
            case 'ping':
                $this->requireMethod($method, 'GET');
                $this->send(200, $this->ping());
            case 'updates':
                $this->requireMethod($method, 'GET');
                $this->send(200, (new Health($this->ctx->db()))->updates());
            case 'php-errors':
                $this->requireMethod($method, 'GET');
                $this->send(200, (new Health($this->ctx->db()))->phpErrors((int) ($query['limit'] ?? 50)));
            case 'sso/issue':
                $this->requireMethod($method, 'POST');
                [$code, $data] = (new Sso($this->ctx, $this->store))->issue($json);
                $this->send($code, $data);
            case 'sso/revoke-all':
                $this->requireMethod($method, 'POST');
                $this->send(200, ['success' => true, 'revoked' => (new Sso($this->ctx, $this->store))->revokeAll()]);
        }

        if (isset(self::FEEDS[$route])) {
            [$moduleId, $httpMethod] = self::FEEDS[$route];
            $this->requireMethod($method, $httpMethod);
            $registry = Kernel::instance()->registry();
            $module   = $registry->isEnabled($moduleId) ? $registry->module($moduleId) : null;
            if ($module === null || !method_exists($module, 'caFeed')) {
                $this->send(404, ['message' => 'Route not available: module ' . $moduleId . ' is not enabled']);
            }
            Lang::loadModule($this->ctx->app(), $moduleId, $module->path());
            $this->send(200, $module->caFeed($route, $query, $json));
        }

        $this->send(404, ['message' => 'Unknown route: ' . $route]);
    }

    private function requireMethod(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            $this->send(405, ['message' => $expected . ' only']);
        }
    }

    /** @return array<string,mixed> */
    private function ping(): array
    {
        $registry = $this->ctx->kernel()->registry();
        $app      = Factory::getApplication();
        $version  = Kernel::version();

        return [
            'site'              => ['name' => (string) $app->get('sitename', ''), 'url' => CaClient::siteUrl()],
            'cms'               => 'joomla',
            'cms_version'       => (new Version())->getShortVersion(),
            'php_version'       => PHP_VERSION,
            'connector'         => 'uxjoomla',
            'connector_version' => $version,
            'enabled_modules'   => $registry->enabledIds(),
            'capabilities'      => $this->capabilities(),
        ];
    }

    /** @param array<string,mixed> $data */
    private function send(int $status, array $data): never
    {
        $out = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($out === '' || $out === 'null') {
            $out = '{}';
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        if ($this->key !== '' && $this->nonce !== '') {
            header(Hmac::HEADER_RESPONSE . ': ' . Hmac::responseSignature($this->nonce, $out, $this->key));
        }
        echo $out;
        $this->ctx->app()->close();
        exit;
    }
}
