<?php

namespace Ux1\UxStudio\Modules\CaConnector;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Ux1\UxStudio\Core\Http;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Client for calls from this site to UX1 Network app (security_api, ticket_api, reviews_api, ...), signed with the
 * pairing key exactly like the calls in the other direction (HMAC with nonce) and identified by site_url.
 *
 *   $client = CaClient::fromContext($ctx);   // null when the site is not paired or the connector is off
 *   $data   = $client?->get('reviews_api', ['action' => 'list']);
 *   $data   = $client?->post('security_api', ['action' => 'inventory'], ['items' => [...]]);
 *
 * Both methods return the decoded JSON body and throw \RuntimeException on transport errors, HTTP errors (4xx / 5xx)
 * and non-JSON answers. UX1 Network app address is typed by the administrator, so for a development CA on a private
 * or loopback address the SSRF guard of core Http is lifted for this one configured host only.
 */
final class CaClient
{
    private function __construct(
        private ModuleContext $ctx,
        private string $baseUrl,
        private string $key,
        private string $siteUrl,
        private string $siteUid
    ) {
    }

    /** null = the connector is disabled or the site is not paired with UX1 Network app. */
    public static function fromContext(ModuleContext $ctx): ?self
    {
        $kernel = $ctx->kernel();
        if (!$kernel->registry()->isEnabled('ca-connector')) {
            return null;
        }
        $settings = $kernel->settings()->all('ca-connector');
        $base     = Pairing::normalizeBaseUrl((string) ($settings['ca_url'] ?? ''));
        $key      = (string) ($settings['node_api_key'] ?? '');
        if ($base === null || $key === '') {
            return null;
        }
        $store = new Store($kernel->db());
        if ($store->get('paired_at') === '') {
            return null;
        }

        return new self($ctx, $base, $key, self::siteUrl(), $store->get('site_uid'));
    }

    /** Site address as UX1 Network app knows it (no trailing slash). */
    public static function siteUrl(): string
    {
        $live = rtrim(Uri::root(), '/');
        if (PHP_SAPI !== 'cli') {
            return $live;
        }
        // CLI (cron, scheduler:run): with an empty live_site Joomla invents https://joomla.invalid/..., so use the address remembered from the web.
        try {
            $stored = (new Store(Factory::getContainer()->get(DatabaseInterface::class)))->get('site_url');
        } catch (\Throwable $e) {
            $stored = '';
        }

        return $stored !== '' ? $stored : $live;
    }

    /** Remembers the public address from a web request (pairing, signed request from the CA) for CLI use. */
    public static function rememberSiteUrl(Store $store): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $url = rtrim(Uri::root(), '/');
        if ($url !== '' && $store->get('site_url') !== $url) {
            $store->set('site_url', $url);
        }
    }

    /** @param array<string,scalar> $query @return array<string,mixed> */
    public function get(string $page, array $query = []): array
    {
        return $this->send('GET', $page, $query, '');
    }

    /** @param array<string,scalar> $query @param array<string,mixed> $body @return array<string,mixed> */
    public function post(string $page, array $query = [], array $body = []): array
    {
        return $this->send('POST', $page, $query, (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,scalar> $query @return array<string,mixed> */
    private function send(string $method, string $page, array $query, string $body): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $page)) {
            throw new \RuntimeException('Invalid UX1 Network app page.');
        }
        $params = ['page' => $page] + $query;
        if (!isset($params['site_url'])) {
            $params['site_url'] = $this->siteUrl;
        }
        $url = $this->baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $ts      = time();
        $nonce   = Hmac::generateNonce();
        $headers = [
            Hmac::HEADER_SIGNATURE => Hmac::sign($method, $url, $body, $ts, $nonce, $this->key),
            Hmac::HEADER_TIMESTAMP => (string) $ts,
            Hmac::HEADER_NONCE     => $nonce,
            Hmac::HEADER_VERSION   => '1',
            'Accept'               => 'application/json',
        ];
        if ($this->siteUid !== '') {
            $headers['X-UXStudio-Site-Id'] = $this->siteUid;
        }
        // The signature binds the exact URL, so a redirect is never followed (it would arrive unsigned / with a wrong signature).
        $options = ['headers' => $headers, 'timeout' => 20, 'allow_private' => $this->hostIsPrivate(), 'follow_redirects' => false];
        if ($method === 'POST') {
            $options['headers']['Content-Type'] = 'application/json';
        }

        $http = $this->ctx->http();
        $res  = $method === 'POST' ? $http->post($url, $body, $options) : $http->get($url, $options);

        if ($res['redirect_to'] !== null) {
            throw new \RuntimeException('UX1 Network app redirected to ' . $res['redirect_to'] . ' (check UX1 Network app address, e.g. http vs https or www).');
        }
        $json = json_decode($res['body'], true);
        if ($res['status'] >= 400) {
            $msg = \is_array($json) ? (string) ($json['error'] ?? $json['message'] ?? '') : '';
            throw new \RuntimeException('UX1 Network app HTTP ' . $res['status'] . ($msg !== '' ? ': ' . $msg : ''));
        }
        if (!\is_array($json)) {
            throw new \RuntimeException('UX1 Network app returned a non-JSON answer.');
        }

        return $json;
    }

    /** True when the configured UX1 Network app host is not a public address (development setup). */
    private function hostIsPrivate(): bool
    {
        $host = trim((string) parse_url($this->baseUrl, PHP_URL_HOST), '[]');
        if ($host === '') {
            return false;
        }
        if (Pairing::isLoopbackHost($host) || Pairing::isLoopbackHost('[' . $host . ']')) {
            return true;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (!Http::isPublicIp($ip)) {
                return true;
            }
        }

        return false;
    }
}
