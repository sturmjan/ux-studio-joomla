<?php

namespace Ux1\UxStudio\Modules\PerformanceOptimization;

use Ux1\UxStudio\Core\HtaccessBlock;

\defined('_JEXEC') or die;

/**
 * Browser cache headers for static files through a marked block in the site's .htaccess (Apache / LiteSpeed).
 * Never written without an explicit request from an administrator; the original file is copied to a protected backup
 * folder first, and the block can be removed again at any time (it is also removed when the module is switched off).
 * Nothing outside the marked block is touched.
 */
final class CacheHeaders
{
    public const BEGIN = '# BEGIN UX1 Studio performance-optimization';
    public const END   = '# END UX1 Studio performance-optimization';

    /** setting value => seconds */
    public const TTLS = ['week' => 604800, 'month' => 2592000, 'halfyear' => 15552000, 'year' => 31536000];

    private const KEEP_BACKUPS = 5;

    public static function file(): string
    {
        return JPATH_ROOT . '/.htaccess';
    }

    public static function backupDir(): string
    {
        return JPATH_ADMINISTRATOR . '/logs/uxs-performance';
    }

    public static function block(string $ttl): string
    {
        $seconds = self::TTLS[$ttl] ?? self::TTLS['month'];
        $types   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon',
            'font/woff', 'font/woff2', 'font/ttf', 'font/otf', 'application/font-woff', 'application/font-woff2', 'text/css', 'text/javascript', 'application/javascript'];
        $lines   = [self::BEGIN, '# Browser caching of static files (' . $seconds . ' seconds). Remove this block to undo.', '<IfModule mod_expires.c>', "\tExpiresActive On"];
        foreach ($types as $type) {
            $lines[] = "\tExpiresByType " . $type . ' "access plus ' . $seconds . ' seconds"';
        }
        $lines[] = '</IfModule>';
        $lines[] = '<IfModule mod_headers.c>';
        $lines[] = "\t<FilesMatch \"\\.(?:css|js|mjs|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf)$\">";
        $lines[] = "\t\tHeader set Cache-Control \"public, max-age=" . $seconds . '"';
        $lines[] = "\t</FilesMatch>";
        $lines[] = '</IfModule>';
        $lines[] = self::END;

        return implode("\n", $lines) . "\n";
    }

    public static function isApache(): bool
    {
        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        return $software === '' || str_contains($software, 'apache') || str_contains($software, 'litespeed') || str_contains($software, 'lsws');
    }

    /** @return array{exists:bool,writable:bool,active:bool,ttl:string,backups:string[],apache:bool,server:string} */
    public static function status(): array
    {
        $file    = self::file();
        $exists  = is_file($file);
        $content = $exists ? (string) @file_get_contents($file) : '';
        $active  = $exists && str_contains($content, self::BEGIN);
        $ttl     = '';
        if ($active && preg_match('/\((\d+) seconds\)/', $content, $m)) {
            $ttl = (string) array_search((int) $m[1], self::TTLS, true);
        }

        return [
            'exists'   => $exists,
            'writable' => $exists ? is_writable($file) : is_writable(JPATH_ROOT),
            'active'   => $active,
            'ttl'      => $ttl,
            'backups'  => self::backups(),
            'apache'   => self::isApache(),
            'server'   => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        ];
    }

    /** @return string[] file names, newest first */
    public static function backups(): array
    {
        $files = glob(self::backupDir() . '/htaccess-*.bak') ?: [];
        rsort($files);

        return array_map('basename', $files);
    }

    /**
     * Writes (or replaces) the block. Returns the backup file name ('' when there was no .htaccess to back up).
     *
     * @throws \RuntimeException when the file cannot be backed up or written
     */
    public static function apply(string $ttl): string
    {
        $file    = self::file();
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $base    = trim(self::strip($current)); // throws before anything is written when the markers are damaged
        $backup  = $current !== '' ? self::backup($current) : '';
        $next = ($base === '' ? '' : $base . "\n\n") . self::block($ttl);
        if (!HtaccessBlock::write($file, $next)) {
            throw new \RuntimeException('The .htaccess file is not writable.');
        }

        return $backup;
    }

    /** Removes the block (and deletes .htaccess when nothing else is left in it). */
    public static function remove(): bool
    {
        $file = self::file();
        if (!is_file($file)) {
            return true;
        }
        $current = (string) file_get_contents($file);
        if (!str_contains($current, self::BEGIN)) {
            return true;
        }
        try {
            $base = trim(self::strip($current));
            self::backup($current);
        } catch (\RuntimeException $e) {
            return false;
        }
        if ($base === '') {
            return @unlink($file);
        }

        return HtaccessBlock::write($file, $base . "\n");
    }

    /**
     * Content without our block, found line by line between the two markers (no regex, so nothing can fail silently).
     *
     * @throws \RuntimeException when a marker is unbalanced - the file is then left alone
     */
    private static function strip(string $content): string
    {
        $out    = [];
        $inside = false;
        foreach (explode("\n", $content) as $line) {
            $mark = trim($line);
            if (!$inside && $mark === self::BEGIN) {
                $inside = true;
            } elseif ($inside && $mark === self::END) {
                $inside = false;
            } elseif (!$inside) {
                $out[] = $line;
            }
        }
        if ($inside) {
            throw new \RuntimeException('The marked block in .htaccess has no end marker.');
        }

        return implode("\n", $out);
    }

    /** Copies the current file into the protected backup folder and keeps only the newest few. */
    private static function backup(string $content): string
    {
        $dir = self::backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('The backup folder cannot be created.');
        }
        // The folder must never be downloadable: deny rule for Apache plus an empty index page.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\nOptions -Indexes\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        $name = 'htaccess-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.bak';
        if (@file_put_contents($dir . '/' . $name, $content) === false) {
            throw new \RuntimeException('The backup could not be written.');
        }
        foreach (\array_slice(self::backups(), self::KEEP_BACKUPS) as $old) {
            @unlink($dir . '/' . $old);
        }

        return $name;
    }
}
