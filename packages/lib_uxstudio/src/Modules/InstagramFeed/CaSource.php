<?php

namespace Ux1\UxStudio\Modules\InstagramFeed;

use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\Http;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Instagram data from the central app (CA) `instagram_api` (a Graph API proxy: the site never sees a Meta token, it only
 * knows its `connection_id`) and the browser OAuth hand-over `instagram_auth`.
 *
 * The CA verifies this endpoint with the legacy request signature (no nonce):
 *   hex( hmac_sha256( "METHOD\nURL\nTIMESTAMP\nsha256hex(body)", node_api_key ) )
 * while the CaConnector's CaClient signs with a nonce (the other CA APIs expect that). That is why this class signs by itself;
 * availability (connector on, site paired) is still decided through CaClient::fromContext(). The browser redirect of the
 * OAuth dance is signed like GoogleProxySigner: hex( hmac_sha256( http_build_query( ksort(params without sig) ), key ) ).
 */
final class CaSource implements MediaSource
{
    private const CLIENT_CLASS  = '\\Ux1\\UxStudio\\Modules\\CaConnector\\CaClient';
    private const PAIRING_CLASS = '\\Ux1\\UxStudio\\Modules\\CaConnector\\Pairing';

    private const TIMEOUT = 20;

    private function __construct(
        private ModuleContext $ctx,
        private string $baseUrl,
        private string $key,
        private string $siteUrl,
        private int $connectionId,
        private string $siteUid = ''
    ) {
    }

    /**
     * Why the central app cannot be used: 'ok' | 'connector_missing' (CaConnector not installed or off) | 'not_paired'.
     */
    public static function availability(ModuleContext $ctx): string
    {
        $client = self::CLIENT_CLASS;
        if (!class_exists($client) || !method_exists($client, 'fromContext') || !$ctx->kernel()->registry()->isEnabled('ca-connector')) {
            return 'connector_missing';
        }
        try {
            return \is_object($client::fromContext($ctx)) ? 'ok' : 'not_paired';
        } catch (\Throwable $e) {
            return 'not_paired';
        }
    }

    /** null = the site is not connected to the central app. $connectionId may be 0 for the OAuth start only. */
    public static function create(ModuleContext $ctx, int $connectionId): ?self
    {
        if (self::availability($ctx) !== 'ok') {
            return null;
        }
        $pairing = self::PAIRING_CLASS;
        $client  = self::CLIENT_CLASS;
        $conn    = $ctx->kernel()->settings()->all('ca-connector');
        $base    = $pairing::normalizeBaseUrl((string) ($conn['ca_url'] ?? ''));
        $key     = (string) ($conn['node_api_key'] ?? '');
        if ($base === null || $key === '') {
            return null;
        }

        // site_uid (X-UXStudio-Site-Id): lets the CA find the site even after its address changed (www / https), like CaClient.
        $uid = '';
        try {
            $storeClass = '\Ux1\UxStudio\Modules\CaConnector\Store';
            if (class_exists($storeClass)) {
                $uid = (string) (new $storeClass($ctx->kernel()->db()))->get('site_uid');
            }
        } catch (\Throwable $e) {
            $uid = '';
        }

        return new self($ctx, $base, $key, (string) $client::siteUrl(), max(0, $connectionId), $uid);
    }

    public function siteUrl(): string
    {
        return $this->siteUrl;
    }

    /** Browser URL that starts the Instagram login at the central app (it redirects back to $returnUrl). */
    public function authorizeUrl(string $returnUrl, string $nonce): string
    {
        $params = [
            'site_url'   => $this->siteUrl,
            'return_url' => $returnUrl,
            'nonce'      => $nonce,
            'ts'         => (string) time(),
        ];
        $params['sig'] = self::proxySignature($params, $this->key);

        return $this->baseUrl . '?' . http_build_query(['page' => 'instagram_auth', 'action' => 'init'] + $params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Signature of the browser hand-over (GoogleProxySigner::sign of the central app). */
    public static function proxySignature(array $params, string $key): string
    {
        unset($params['sig']);
        ksort($params);

        return hash_hmac('sha256', http_build_query($params), $key);
    }

    /**
     * @param array<string,mixed> $params query of the callback
     * @throws ApiException
     */
    public function verifyReturn(array $params, string $expectedNonce): void
    {
        self::verifyCallback($params, $this->key, $expectedNonce, time());
    }

    /**
     * Verifies the redirect the central app sends the browser back with. Pure (no I/O) so it can be tested alone.
     *
     * @param array<string,mixed> $params query of the callback (connection_id, ig_*, nonce, ts, sig)
     * @throws ApiException
     */
    public static function verifyCallback(array $params, string $key, string $expectedNonce, int $now): void
    {
        $fail = static fn (string $why) => new ApiException('Invalid Instagram callback (' . $why . ').', 403, 'invalid_callback');

        $sig = (string) ($params['sig'] ?? '');
        $ts  = (int) ($params['ts'] ?? 0);
        if ($key === '' || $expectedNonce === '' || $sig === '' || $ts <= 0) {
            throw $fail('missing');
        }
        if (abs($now - $ts) > 300) {
            throw $fail('expired');
        }
        $clean = [];
        foreach ($params as $k => $v) {
            if (\is_scalar($v)) {
                $clean[(string) $k] = (string) $v;
            }
        }
        if (!hash_equals(self::proxySignature($clean, $key), $sig)) {
            throw $fail('signature');
        }
        if (!hash_equals($expectedNonce, (string) ($params['nonce'] ?? ''))) {
            throw $fail('nonce');
        }
    }

    public function profile(bool $fresh = false): array
    {
        $data = $this->call('GET', 'profile', $fresh ? ['fresh' => '1'] : []);
        $p    = $data['profile'] ?? [];

        return \is_array($p) ? $p : [];
    }

    public function media(int $limit): array
    {
        $items = [];
        $after = '';
        // The CA caps one page at 100 posts and hands out a cursor; stay under a hard page ceiling.
        for ($page = 0; $page < 5 && \count($items) < $limit; $page++) {
            $query = ['limit' => (string) min(100, $limit - \count($items))];
            if ($after !== '') {
                $query['after'] = $after;
            }
            $data = $this->call('GET', 'media', $query);
            foreach ((array) ($data['items'] ?? []) as $item) {
                if (\is_array($item)) {
                    $items[] = $item;
                }
            }
            $after = (string) ($data['paging']['cursors']['after'] ?? '');
            if ($after === '' || empty($data['paging']['next'])) {
                break;
            }
        }

        return \array_slice($items, 0, $limit);
    }

    /** Asks the CA to revoke the Meta token and forget the connection (best effort for the caller). */
    public function disconnect(): void
    {
        $this->call('POST', 'disconnect', []);
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed> the `data` member of the CA envelope
     * @throws ApiException
     */
    private function call(string $method, string $action, array $query): array
    {
        if ($this->connectionId <= 0) {
            throw new ApiException($this->ctx->t('UXS_INSTAGRAM_FEED_ERR_NO_CONNECTION'), 424, 'no_connection');
        }
        $params = ['page' => 'instagram_api', 'action' => $action, 'site_url' => $this->siteUrl, 'connection_id' => (string) $this->connectionId] + $query;
        $url    = $this->baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $body   = '';
        $ts     = time();

        $headers = [
            'X-Content-Sync-Signature' => hash_hmac('sha256', $method . "\n" . $url . "\n" . $ts . "\n" . hash('sha256', $body), $this->key),
            'X-Content-Sync-Timestamp' => (string) $ts,
            'Accept'                   => 'application/json',
        ];
        if ($this->siteUid !== '') {
            $headers['X-UXStudio-Site-Id'] = $this->siteUid;
        }
        // The signature binds the exact URL: a redirect would arrive unsigned / with a wrong signature, so it is never followed.
        $options = ['headers' => $headers, 'timeout' => self::TIMEOUT, 'allow_private' => $this->hostIsPrivate(), 'follow_redirects' => false];

        try {
            $http = $this->ctx->http();
            $res  = $method === 'POST' ? $http->post($url, $body, $options) : $http->get($url, $options);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException($this->ctx->t('UXS_INSTAGRAM_FEED_ERR_CA_UNREACHABLE'), 502, 'ca_unreachable', $e);
        }

        if (($res['redirect_to'] ?? null) !== null) {
            throw new ApiException($this->ctx->t('UXS_INSTAGRAM_FEED_ERR_CA_REDIRECT', (string) $res['redirect_to']), 502, 'ca_redirect');
        }
        $json = json_decode($res['body'], true);
        if (!\is_array($json)) {
            throw new ApiException($this->ctx->t('UXS_INSTAGRAM_FEED_ERR_CA_BAD_ANSWER', $res['status']), 502, 'ca_bad_answer');
        }
        if (empty($json['success'])) {
            $msg = mb_substr(trim((string) ($json['error'] ?? '')), 0, 300);
            // Only a CA answer that says the Instagram connection / token is gone means "connect the account again". Any other
            // 401 / 404 (bad signature, clock skew, site not registered or moved) is a pairing problem.
            if (self::isConnectionLost($res['status'], $msg)) {
                $code = 'connection_lost';
            } elseif (\in_array($res['status'], [401, 403, 404], true)) {
                $code = 'ca_auth';
            } else {
                $code = 'ca_error';
            }
            $key = $code === 'ca_auth' ? 'UXS_INSTAGRAM_FEED_ERR_CA_AUTH' : 'UXS_INSTAGRAM_FEED_ERR_CA';
            throw new ApiException($this->ctx->t($key, $msg !== '' ? $msg : 'HTTP ' . $res['status']), 502, $code);
        }

        $data = $json['data'] ?? [];

        return \is_array($data) ? $data : [];
    }

    /** True when the CA message says the connection is unknown or has no valid token (Czech or English wording). */
    public static function isConnectionLost(int $status, string $message): bool
    {
        if (!\in_array($status, [400, 401, 403, 404], true)) {
            return false;
        }
        $m = mb_strtolower($message);
        foreach (['neznámé připojení', 'neznama pripojeni', 'platný token', 'platny token', 'unknown connection', 'no valid token', 'token expired', 'token revoked'] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** A development CA on a private or loopback address: the SSRF guard is lifted for this one configured host only. */
    private function hostIsPrivate(): bool
    {
        $host = trim((string) parse_url($this->baseUrl, PHP_URL_HOST), '[]');
        if ($host === '') {
            return false;
        }
        if (\in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
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
