<?php

namespace Ux1\UxStudio\Core;

\defined('_JEXEC') or die;

/**
 * Safe handling of a marked block inside an .htaccess file: strict removal of the block (damaged markers throw instead of
 * guessing), a backup into a folder nobody can download, and a write that never leaves a half-written file for Apache.
 */
final class HtaccessBlock
{
    /**
     * Content without the block found line by line between the two markers.
     *
     * @throws \RuntimeException when the end marker is missing - the file must then be left alone
     */
    public static function strip(string $content, string $begin, string $end): string
    {
        $out    = [];
        $inside = false;
        foreach (explode("\n", $content) as $line) {
            $mark = trim($line);
            if (!$inside && $mark === $begin) {
                $inside = true;
            } elseif ($inside && $mark === $end) {
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

    /**
     * Copies $content into the protected backup folder (deny-all .htaccess + empty index.html) and keeps the newest $keep.
     *
     * @throws \RuntimeException when the backup cannot be written
     */
    public static function backup(string $content, string $dir, string $prefix, int $keep = 5): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('The backup folder cannot be created.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\nOptions -Indexes\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        $name = $prefix . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.bak';
        if (@file_put_contents($dir . '/' . $name, $content) === false) {
            throw new \RuntimeException('The backup could not be written.');
        }
        $all = glob($dir . '/' . $prefix . '-*.bak') ?: [];
        rsort($all);
        foreach (\array_slice($all, $keep) as $old) {
            @unlink($old);
        }

        return $name;
    }

    /**
     * Atomic replacement: a temporary file in the same folder and rename(), so a concurrent request reads either the old or
     * the new file. When the folder is not writable (only the file itself is) it falls back to an exclusive in-place write.
     */
    public static function write(string $file, string $content): bool
    {
        $dir = \dirname($file);
        $tmp = is_dir($dir) && is_writable($dir) ? $dir . '/.uxs-' . bin2hex(random_bytes(4)) . '.tmp' : '';
        if ($tmp !== '' && @file_put_contents($tmp, $content, LOCK_EX) !== false) {
            if (is_file($file)) {
                @chmod($tmp, fileperms($file) & 0777);
            }
            if (@rename($tmp, $file)) {
                return true;
            }
            @unlink($tmp);
        }

        return @file_put_contents($file, $content, LOCK_EX) !== false;
    }
}
