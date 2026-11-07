<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

\defined('_JEXEC') or die;

/**
 * Filesystem microcache of rendered HTML for throttled BOT requests (guests only). Lives in cache/uxs-bot-cache, locked
 * down with a deny-all .htaccess and an empty index.html (on nginx deny the folder in the server config). Files are named by
 * a hash and carry an expiry; nothing here is ever served to a human visitor.
 */
final class Microcache
{
    /** Hard caps: a crawler hitting endless ?x=N variants must not fill the disk. */
    public const MAX_FILES = 2000;
    public const MAX_BYTES = 104857600;

    private string $dir;

    public function __construct(?string $dir = null, private int $defaultTtl = 3600)
    {
        $this->dir = $dir ?: JPATH_ROOT . '/cache/uxs-bot-cache';
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }
        if (!@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            return false;
        }
        @file_put_contents($this->dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n");
        @file_put_contents($this->dir . '/index.html', '');

        return true;
    }

    public function key(string $host, string $uri, string $category): string
    {
        return md5(strtolower($host) . '|' . self::normalizeUri($uri) . '|' . $category);
    }

    /** Path plus the query parameters in sorted order (a different parameter order is the same page). */
    public static function normalizeUri(string $uri): string
    {
        $parts = explode('?', $uri, 2);
        $path  = $parts[0];
        if (!isset($parts[1]) || $parts[1] === '') {
            return $path;
        }
        $pairs = array_filter(explode('&', $parts[1]), static fn (string $p): bool => $p !== '');
        sort($pairs, SORT_STRING);

        return $path . '?' . implode('&', $pairs);
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        $parts = explode("\n---END-META---\n", $data, 2);
        if (\count($parts) !== 2) {
            return null;
        }
        $meta = json_decode($parts[0], true);
        if (!\is_array($meta)) {
            return null;
        }
        if ((int) ($meta['expires'] ?? 0) < time()) {
            @unlink($path);

            return null;
        }

        return $parts[1];
    }

    public function set(string $key, string $html, ?int $ttl = null): void
    {
        if (!$this->ensureDir() || \strlen($html) > 2097152) {
            return;
        }
        // The directory scan is not free: the caps are checked on a sample of the writes and a "full" marker (60 s) stops the rest.
        $marker = $this->dir . '/.full';
        if (is_file($marker) && (int) @filemtime($marker) > time() - 60) {
            return;
        }
        if (random_int(1, 20) === 1 && !is_file($this->path($key))) {
            $size = $this->size();
            if ($size['count'] >= self::MAX_FILES || $size['bytes'] + \strlen($html) > self::MAX_BYTES) {
                // Full: drop expired files once, and store nothing if it still does not fit.
                $this->purgeExpired();
                $size = $this->size();
                if ($size['count'] >= self::MAX_FILES || $size['bytes'] + \strlen($html) > self::MAX_BYTES) {
                    @touch($marker);

                    return;
                }
            }
        }
        $ttl  = $ttl ?? $this->defaultTtl;
        $meta = json_encode(['created' => time(), 'expires' => time() + max(60, $ttl)]);
        @file_put_contents($this->path($key), $meta . "\n---END-META---\n" . $html, LOCK_EX);
    }

    public function clear(): int
    {
        $count = 0;
        @unlink($this->dir . '/.full');
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    public function purgeExpired(): int
    {
        $count = 0;
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            $head = @file_get_contents($file, false, null, 0, 200);
            $meta = \is_string($head) ? json_decode((string) strstr($head, '---END-META---', true), true) : null;
            if (!\is_array($meta) || (int) ($meta['expires'] ?? 0) < time()) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** @return array{count:int,bytes:int} */
    public function size(): array
    {
        $bytes = 0;
        $files = glob($this->dir . '/*.cache') ?: [];
        foreach ($files as $f) {
            $bytes += (int) @filesize($f);
        }

        return ['count' => \count($files), 'bytes' => $bytes];
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . preg_replace('/[^a-f0-9]/', '', $key) . '.cache';
    }
}
