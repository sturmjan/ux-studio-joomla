<?php

namespace Ux1\UxStudio\Modules\CaConnector;

\defined('_JEXEC') or die;

/**
 * Pure helpers of the one-click pairing (port of includes/Core/Onboarding/CentralApp.php of the WordPress plugin).
 *
 * The browser POSTs to {CA}/?page=connect_site the fields site_url, site_name, api_key, timestamp, site_type, site_id,
 * return_url, signature, with signature = hmac_sha256(site_url|site_name|timestamp|site_type[|site_id], api_key).
 * The signature alone proves nothing (the key travels in the same POST), so the CA makes a live signed ping to this site
 * (the node endpoint) before it asks its administrator to confirm. The key must therefore be stored BEFORE the browser leaves.
 */
final class Pairing
{
    public const DEFAULT_URL = 'https://app.ux1.cz/';
    public const SITE_TYPE   = 'joomla';

    /**
     * UX1 Network app address typed by the administrator, normalised: https, or plain http only for a machine of the
     * developer (localhost, 127.0.0.1, [::1]); no credentials, query or fragment. Empty input = production address.
     *
     * @return string|null `scheme://host[:port]/path/` or null when not acceptable
     */
    public static function normalizeBaseUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            $url = self::DEFAULT_URL;
        }
        if (\strlen($url) > 200 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host   = strtolower((string) $parts['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && self::isLoopbackHost($host))) {
            return null;
        }
        if (!preg_match('/^(\[[0-9a-f:]+\]|[a-z0-9]([a-z0-9.-]*[a-z0-9])?)$/', $host)) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && (!preg_match('#^/[A-Za-z0-9._~/%-]*$#', $path) || str_contains($path, '..'))) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port . rtrim($path, '/') . '/';
    }

    public static function isLoopbackHost(string $host): bool
    {
        return \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public static function signature(string $siteUrl, string $siteName, int $timestamp, string $siteType, string $siteUid, string $apiKey): string
    {
        $payload = $siteUrl . '|' . $siteName . '|' . $timestamp;
        if ($siteType !== '' || $siteUid !== '') {
            $payload .= '|' . $siteType;
        }
        if ($siteUid !== '') {
            $payload .= '|' . $siteUid;
        }

        return hash_hmac('sha256', $payload, $apiKey);
    }

    /** UX1 Network app only follows a return_url on the same origin as the site. */
    public static function sameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if (!\is_array($pa) || !\is_array($pb)) {
            return false;
        }
        $norm = static fn (array $p): string => strtolower((string) ($p['scheme'] ?? '')) . '://' . strtolower((string) ($p['host'] ?? '')) . ':'
            . (int) ($p['port'] ?? (strtolower((string) ($p['scheme'] ?? '')) === 'https' ? 443 : 80));

        return ($pa['host'] ?? '') !== '' && $norm($pa) === $norm($pb);
    }

    /**
     * @return array{action:string,fields:array<string,string>}
     */
    public static function form(string $baseUrl, string $siteUrl, string $siteName, string $apiKey, string $returnUrl, int $timestamp, string $siteUid): array
    {
        $fields = [
            'site_url'   => $siteUrl,
            'site_name'  => $siteName,
            'api_key'    => $apiKey,
            'timestamp'  => (string) $timestamp,
            'site_type'  => self::SITE_TYPE,
            'return_url' => $returnUrl,
            'signature'  => self::signature($siteUrl, $siteName, $timestamp, self::SITE_TYPE, $siteUid, $apiKey),
        ];
        if ($siteUid !== '') {
            $fields['site_id'] = $siteUid;
        }

        return ['action' => $baseUrl . '?page=connect_site', 'fields' => $fields];
    }

    /** UUID v4. */
    public static function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0f) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3f) | 0x80);
        $hex  = bin2hex($b);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
