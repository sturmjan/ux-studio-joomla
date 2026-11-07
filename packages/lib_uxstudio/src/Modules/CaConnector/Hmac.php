<?php

namespace Ux1\UxStudio\Modules\CaConnector;

\defined('_JEXEC') or die;

/**
 * Request signing of the Central App channel (same algorithm as the WordPress ContentSync\HmacAuth, with nonce):
 *   hex( hmac_sha256( "METHOD\nURL\nTIMESTAMP\nNONCE\nsha256hex(body)", key ) )
 * Responses carry X-Content-Sync-Response-Signature = hex( hmac_sha256( nonce . "|" . sha256hex(body), key ) ).
 */
final class Hmac
{
    public const TOLERANCE = 300;

    public const HEADER_SIGNATURE = 'X-Content-Sync-Signature';
    public const HEADER_TIMESTAMP = 'X-Content-Sync-Timestamp';
    public const HEADER_NONCE     = 'X-Content-Sync-Nonce';
    public const HEADER_VERSION   = 'X-Content-Sync-Version';
    public const HEADER_RESPONSE  = 'X-Content-Sync-Response-Signature';

    /** Random shared key: 64 hex characters. */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Request nonce (hex, so it passes ctype_alnum on the other side). */
    public static function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function sign(string $method, string $url, string $body, int $timestamp, string $nonce, string $secret): string
    {
        $payload = strtoupper($method) . "\n" . $url . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);

        return hash_hmac('sha256', $payload, $secret);
    }

    public static function responseSignature(string $nonce, string $body, string $secret): string
    {
        return hash_hmac('sha256', $nonce . '|' . hash('sha256', $body), $secret);
    }

    /** True when the request reached PHP over https (directly or behind a proxy / Cloudflare). */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($proto === 'https') {
            return true;
        }

        return (bool) preg_match('/"scheme"\s*:\s*"https"/', (string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''));
    }

    /** The absolute URL of the current request exactly as the caller signed it (scheme + host + REQUEST_URI). */
    public static function currentUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

        return (self::isHttps() ? 'https' : 'http') . '://' . $host . (string) ($_SERVER['REQUEST_URI'] ?? '');
    }
}
