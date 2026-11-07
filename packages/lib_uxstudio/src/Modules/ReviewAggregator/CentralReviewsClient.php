<?php

namespace Ux1\UxStudio\Modules\ReviewAggregator;

use Joomla\CMS\Application\ConsoleApplication;
use Ux1\UxStudio\Core\Crypto;
use Ux1\UxStudio\Core\Http;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Modules\CaConnector\CaClient;
use Ux1\UxStudio\Modules\CaConnector\Pairing;
use Ux1\UxStudio\Modules\CaConnector\Store as CaStore;

\defined('_JEXEC') or die;

/**
 * Client of the central app public reviews API (`?page=reviews_api`), same protocol as the WordPress CentralReviewsClient.
 * The endpoint does not use the pairing-key headers of CaClient; it verifies a per-site secret (`google_login_secret`):
 *
 *   1. The secret is fetched once from `?page=site_config&action=google_secret`, authenticated with the pairing key
 *      (node_api_key) by the legacy scheme: X-Site-Url / X-Timestamp / X-Signature over METHOD\nURL\nTIMESTAMP\nsha256(body).
 *      It is stored encrypted (Crypto) in the module meta table and re-fetched once after a 401.
 *   2. Every reviews_api call is signed: HMAC-SHA256 over the ksort()ed http_build_query() of site_url + ts + every
 *      non-empty field sent; ts / sig travel in X-Reviews-Timestamp / X-Reviews-Signature. The CA burns each signature,
 *      so every request is signed again.
 *
 * Connection status (paired / connector on) comes from CaClient::fromContext(); this class only reads its settings.
 */
final class CentralReviewsClient
{
    private const META_SECRET = 'reviews_secret';

    private const TIMEOUT = 20;

    private function __construct(
        private ModuleContext $ctx,
        private ReviewStore $store,
        private string $baseUrl,
        private string $nodeKey
    ) {
    }

    /** null = the site is not paired with a central app, or the connector is off. */
    public static function create(ModuleContext $ctx, ReviewStore $store): ?self
    {
        if (!class_exists(CaClient::class) || CaClient::fromContext($ctx) === null) {
            return null;
        }
        $settings = $ctx->kernel()->settings()->all('ca-connector');
        $base     = Pairing::normalizeBaseUrl((string) ($settings['ca_url'] ?? ''));
        $key      = (string) ($settings['node_api_key'] ?? '');
        if ($base === null || $key === '') {
            return null;
        }

        return new self($ctx, $store, $base, $key);
    }

    /**
     * GET one reviews_api action.
     *
     * @param array<string,scalar> $params optional signed fields (profile_id, source, external_id, limit, offset, ...)
     * @param string               $etag   If-None-Match value, '' for none
     * @return array<string,mixed> decoded body; ['not_modified' => true] on HTTP 304
     * @throws \RuntimeException with the HTTP status as code on any failure
     */
    public function call(string $action, array $params = [], string $etag = ''): array
    {
        try {
            return $this->request($action, $params, $etag, false);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 401) {
                throw $e;
            }
            // The CA may have rotated the secret: fetch it again once and retry.
            return $this->request($action, $params, $etag, true);
        }
    }

    /** @return array<string,mixed> */
    private function request(string $action, array $params, string $etag, bool $refreshSecret): array
    {
        $secret = $this->secret($refreshSecret);
        $signed = ['site_url' => $this->siteUrl()];
        foreach ($params as $key => $value) {
            if ((string) $value !== '') {
                $signed[(string) $key] = (string) $value;
            }
        }
        $ts   = (string) time();
        $base = $signed + ['ts' => $ts];
        ksort($base);
        $sig = hash_hmac('sha256', http_build_query($base), $secret);

        $url     = $this->baseUrl . '?' . http_build_query(['page' => 'reviews_api', 'action' => $action] + $signed);
        $headers = ['Accept' => 'application/json', 'X-Reviews-Timestamp' => $ts, 'X-Reviews-Signature' => $sig];
        if ($etag !== '') {
            $headers['If-None-Match'] = '"' . $etag . '"';
        }
        $uid = $this->siteUid();
        if ($uid !== '') {
            $headers['X-UXStudio-Site-Id'] = $uid;
        }

        $res = $this->ctx->http()->get($url, ['headers' => $headers, 'timeout' => self::TIMEOUT, 'allow_private' => $this->hostIsPrivate()]);
        if ($res['status'] === 304) {
            return ['not_modified' => true];
        }

        return $this->decode($res);
    }

    /** The per-site reviews secret (CA sites.google_login_secret), cached encrypted. */
    private function secret(bool $refresh): string
    {
        if (!$refresh) {
            $cached = Crypto::decrypt($this->store->getMeta(self::META_SECRET));
            if ($cached !== '') {
                return $cached;
            }
        }

        $url = $this->baseUrl . '?' . http_build_query(['page' => 'site_config', 'action' => 'google_secret']);
        $now = time();
        $sig = hash_hmac('sha256', "GET\n" . $url . "\n" . $now . "\n" . hash('sha256', ''), $this->nodeKey);
        $headers = ['Accept' => 'application/json', 'X-Site-Url' => $this->siteUrl(), 'X-Timestamp' => (string) $now, 'X-Signature' => $sig];
        $uid = $this->siteUid();
        if ($uid !== '') {
            $headers['X-UXStudio-Site-Id'] = $uid;
        }

        $res  = $this->ctx->http()->get($url, ['headers' => $headers, 'timeout' => self::TIMEOUT, 'allow_private' => $this->hostIsPrivate()]);
        $json = $this->decode($res);

        $secret = (string) ($json['google_login_secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException($this->ctx->t('UXS_REVIEW_AGGREGATOR_ERR_NO_SECRET'), 424);
        }
        $this->store->setMeta(self::META_SECRET, Crypto::encrypt($secret));

        return $secret;
    }

    /**
     * @param array{status:int,body:string} $res
     * @return array<string,mixed>
     */
    private function decode(array $res): array
    {
        $json = json_decode($res['body'], true);
        $json = \is_array($json) ? $json : [];
        if ($res['status'] < 200 || $res['status'] >= 300 || empty($json['success'])) {
            $msg = (string) ($json['error'] ?? '');

            throw new \RuntimeException('Central App HTTP ' . $res['status'] . ($msg !== '' ? ': ' . $msg : ''), $res['status']);
        }

        return $json;
    }

    /**
     * Site address as the CA knows it. Uri::root() is only reliable in a web request: under the CLI scheduler (cron) it differs,
     * so the address seen by the last web request is remembered in the module meta and used there.
     */
    private function siteUrl(): string
    {
        $live = CaClient::siteUrl();
        $kept = $this->store->getMeta('site_url');
        if ($this->ctx->app() instanceof ConsoleApplication) {
            return $kept !== '' ? $kept : $live;
        }
        if ($kept !== $live) {
            $this->store->setMeta('site_url', $live);
        }

        return $live;
    }

    private function siteUid(): string
    {
        try {
            return (new CaStore($this->ctx->kernel()->db()))->get('site_uid');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** True when the configured Central App host is not a public address (development setup): the SSRF guard is lifted for it. */
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
