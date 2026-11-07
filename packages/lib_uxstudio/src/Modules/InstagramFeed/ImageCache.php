<?php

namespace Ux1\UxStudio\Modules\InstagramFeed;

use Joomla\CMS\Uri\Uri;
use Ux1\UxStudio\Core\Http;

\defined('_JEXEC') or die;

/**
 * Local copies of the post images in images/uxstudio-instagram/ (Instagram CDN links expire and would leak the visitor's
 * IP to Meta). Only real images are kept: the bytes are sniffed, the file name and extension are generated here and never
 * come from the remote side, so nothing executable can land in the folder.
 */
final class ImageCache
{
    public const DIR = 'images/uxstudio-instagram';

    /** Longest accepted image in bytes. */
    private const MAX_BYTES = 8388608;

    private const EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private Http $http)
    {
    }

    public static function dir(): string
    {
        return JPATH_ROOT . '/' . self::DIR;
    }

    /** True for a file name this class could have generated. */
    public static function isValidName(string $file): bool
    {
        return (bool) preg_match('/^ig-[a-f0-9]{20}\.(jpg|png|webp|gif)$/', $file);
    }

    /** Public URL of a stored copy (site relative to the web root, like Joomla media). */
    public static function url(string $file): string
    {
        return self::isValidName($file) ? Uri::root(true) . '/' . self::DIR . '/' . $file : '';
    }

    public static function exists(string $file): bool
    {
        return self::isValidName($file) && is_file(self::dir() . '/' . $file);
    }

    /**
     * Downloads $url and stores it under a name derived from $key. Returns the file name, '' when the image could not be
     * fetched or is not an image (the caller keeps using the remote link then).
     */
    public function store(string $key, string $url): string
    {
        if (!preg_match('#^https://#i', $url)) {
            return '';
        }
        try {
            $res = $this->http->get($url, ['timeout' => 15, 'max_bytes' => self::MAX_BYTES, 'headers' => ['Accept' => 'image/*']]);
        } catch (\Throwable $e) {
            return '';
        }
        if ($res['status'] !== 200 || \strlen($res['body']) < 100) {
            return '';
        }
        $info = @getimagesizefromstring($res['body']);
        $ext  = \is_array($info) ? (self::EXT[(string) ($info['mime'] ?? '')] ?? '') : '';
        if ($ext === '' || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return '';
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return '';
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '<!DOCTYPE html><title></title>');
        }
        $name = 'ig-' . substr(sha1($key), 0, 20) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $res['body'], LOCK_EX) === false) {
            return '';
        }

        return $name;
    }

    /** Deletes a stored copy and the .webp / .avif siblings an image optimiser may have generated next to it. */
    public static function remove(string $file): void
    {
        if (!self::isValidName($file)) {
            return;
        }
        foreach (['', '.webp', '.avif'] as $suffix) {
            $path = self::dir() . '/' . $file . $suffix;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Deletes stored files (and their optimiser siblings) that no post refers to any more.
     *
     * @param string[] $keep
     * @return int number of removed files
     */
    public static function prune(array $keep): int
    {
        $removed = 0;
        $keep    = array_flip($keep);
        foreach (glob(self::dir() . '/ig-*') ?: [] as $path) {
            $name = basename($path);
            $base = (string) preg_replace('/\.(webp|avif)$/', '', $name);
            if (self::isValidName($base) && !isset($keep[$base]) && @unlink($path)) {
                ++$removed;
            }
        }

        return $removed;
    }
}
