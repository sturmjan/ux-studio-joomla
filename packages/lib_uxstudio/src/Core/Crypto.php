<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Symmetric encryption for secrets stored in settings (API keys, SMTP passwords).
 * The key is derived from the site $secret in configuration.php. Uses libsodium secretbox
 * (native extension or the polyfill that ships with Joomla); falls back to AES-256-GCM via OpenSSL.
 */
final class Crypto
{
    private const PREFIX_SODIUM = 'enc1:';
    private const PREFIX_OPENSSL = 'enc2:';

    private static function key(): string
    {
        $secret = (string) Factory::getApplication()->get('secret', '');
        if ($secret === '') {
            throw new \RuntimeException('Site secret is empty, cannot derive an encryption key.');
        }

        return hash('sha256', 'uxstudio|secret|' . $secret, true);
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX_SODIUM) || str_starts_with($value, self::PREFIX_OPENSSL);
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();

        if (\function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return self::PREFIX_SODIUM . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }

        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::PREFIX_OPENSSL . base64_encode($iv . $tag . $cipher);
    }

    /** Returns the plain text, or '' when the value cannot be decrypted (for example the site secret changed). */
    public static function decrypt(string $value): string
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }

        $key = self::key();

        if (str_starts_with($value, self::PREFIX_SODIUM)) {
            $raw = base64_decode(substr($value, \strlen(self::PREFIX_SODIUM)), true);
            if ($raw === false || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key
            );

            return $plain === false ? '' : $plain;
        }

        $raw = base64_decode(substr($value, \strlen(self::PREFIX_OPENSSL)), true);
        if ($raw === false || \strlen($raw) <= 28) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? '' : $plain;
    }
}
