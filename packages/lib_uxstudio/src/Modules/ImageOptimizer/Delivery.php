<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

\defined('_JEXEC') or die;

/**
 * Serves the WebP / AVIF sibling (<file>.webp, <file>.avif) instead of the JPEG / PNG to browsers that accept it.
 * Works through a marked block in images/.htaccess (Apache and LiteSpeed, needs AllowOverride FileInfo); the rest of the
 * file is never touched. The block is removed when the module is switched off.
 */
final class Delivery
{
    private const BEGIN = '# BEGIN UX Studio image-optimizer';
    private const END   = '# END UX Studio image-optimizer';

    private static function file(): string
    {
        return JPATH_ROOT . '/images/.htaccess';
    }

    public static function block(bool $webp, bool $avif): string
    {
        $lines = [self::BEGIN, '<IfModule mod_rewrite.c>', "\tRewriteEngine On"];
        if ($avif) {
            $lines[] = "\tRewriteCond %{HTTP_ACCEPT} image/avif";
            $lines[] = "\tRewriteCond %{REQUEST_FILENAME}.avif -f";
            $lines[] = "\tRewriteRule \\.(jpe?g|png)$ %{REQUEST_URI}.avif [NC,T=image/avif,L]";
        }
        if ($webp) {
            $lines[] = "\tRewriteCond %{HTTP_ACCEPT} image/webp";
            $lines[] = "\tRewriteCond %{REQUEST_FILENAME}.webp -f";
            $lines[] = "\tRewriteRule \\.(jpe?g|png)$ %{REQUEST_URI}.webp [NC,T=image/webp,L]";
        }
        $lines[] = '</IfModule>';
        $lines[] = '<IfModule mod_headers.c>';
        $lines[] = "\t<FilesMatch \"(?i)\\.(jpe?g|png)$\">";
        $lines[] = "\t\tHeader append Vary Accept";
        $lines[] = "\t</FilesMatch>";
        $lines[] = '</IfModule>';
        $lines[] = '<IfModule mod_mime.c>';
        $lines[] = "\tAddType image/webp .webp";
        $lines[] = "\tAddType image/avif .avif";
        $lines[] = '</IfModule>';
        $lines[] = self::END;

        return implode("\n", $lines) . "\n";
    }

    public static function isActive(): bool
    {
        $file = self::file();

        return is_file($file) && str_contains((string) file_get_contents($file), self::BEGIN);
    }

    /** Writes (or replaces) the block. Returns false when images/.htaccess is not writable. */
    public static function write(bool $webp, bool $avif): bool
    {
        $file    = self::file();
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $block   = self::block($webp, $avif);
        $next    = self::strip($current);
        $next    = ($next === '' ? '' : rtrim($next) . "\n\n") . $block;
        if ($next === $current) {
            return true;
        }

        return @file_put_contents($file, $next) !== false;
    }

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
        $next = trim(self::strip($current));
        if ($next === '') {
            return @unlink($file);
        }

        return @file_put_contents($file, $next . "\n") !== false;
    }

    private static function strip(string $content): string
    {
        return (string) preg_replace('/\R?' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\R?/s', "\n", $content);
    }

    /** Brings the file in line with the settings (called when the admin opens the site / the module is switched on). */
    public static function sync(bool $enabled, bool $webp, bool $avif): void
    {
        if ($enabled && ($webp || $avif)) {
            // Cheap check first: the block is rewritten only when it is missing or differs.
            $file = self::file();
            if (is_file($file) && str_contains((string) file_get_contents($file), self::block($webp, $avif))) {
                return;
            }
            self::write($webp, $avif);
        } elseif (self::isActive()) {
            self::remove();
        }
    }
}
