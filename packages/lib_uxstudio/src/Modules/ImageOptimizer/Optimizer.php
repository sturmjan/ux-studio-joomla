<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Ux1\UxStudio\Core\ApiException;

\defined('_JEXEC') or die;

/**
 * Compresses one JPEG or PNG (and writes <file>.webp / <file>.avif next to it).
 *
 * Rules: never compress twice (a file whose size equals the recorded result is skipped), the original is kept in
 * media/uxstudio-originals/ (optional), a result that is not smaller than the input is discarded, animated images are
 * left alone, metadata is stripped (the EXIF orientation is applied to the pixels first).
 */
final class Optimizer
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /** Result must be at least this much smaller (fraction) when nothing was resized. */
    private const MIN_GAIN = 0.02;

    /** @var string[]|null */
    private static ?array $roots = null;

    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings, private Store $store)
    {
    }

    // ------------------------------------------------------------------ capabilities

    /** @return array{gd:bool,imagick:bool,library:string,formats:array<string,bool>} */
    public static function capabilities(string $library = 'auto'): array
    {
        $gd      = \extension_loaded('gd') && \function_exists('imagecreatefromstring');
        $imagick = ImagickCodec::available();
        $use     = self::library($library);

        return [
            'gd'      => $gd,
            'imagick' => $imagick,
            'library' => $use,
            'formats' => [
                'webp' => $use === 'imagick' ? ImagickCodec::canEncode('webp') : ($use === 'gd' && GdCodec::canEncode('webp')),
                'avif' => $use === 'imagick' ? ImagickCodec::canEncode('avif') : ($use === 'gd' && GdCodec::canEncode('avif')),
            ],
        ];
    }

    /** Library actually used: 'gd', 'imagick' or 'none'. */
    public static function library(string $setting): string
    {
        $gd      = \extension_loaded('gd') && \function_exists('imagecreatefromstring');
        $imagick = ImagickCodec::available();
        if ($setting === 'imagick' && $imagick) {
            return 'imagick';
        }
        if ($setting === 'gd' && $gd) {
            return 'gd';
        }

        return $imagick ? 'imagick' : ($gd ? 'gd' : 'none');
    }

    // ------------------------------------------------------------------ paths

    /** Top-level directories the module may touch: images plus the directories of the local filesystem plugin. */
    public static function roots(DatabaseInterface $db): array
    {
        if (self::$roots !== null) {
            return self::$roots;
        }
        $roots = ['images'];
        try {
            $params = $db->setQuery(
                $db->createQuery()->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote('filesystem'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('local'))
            )->loadResult();
            $data = json_decode((string) $params, true);
            foreach ((array) ($data['directories'] ?? []) as $entry) {
                $dir = trim((string) (\is_array($entry) ? ($entry['directory'] ?? '') : ''), '/');
                if ($dir !== '' && preg_match('#^[A-Za-z0-9_-]+$#', $dir) && !\in_array($dir, ['administrator', 'api', 'cache', 'cli', 'components', 'includes', 'language', 'layouts', 'libraries', 'media', 'modules', 'plugins', 'templates', 'tmp'], true)) {
                    $roots[] = $dir;
                }
            }
        } catch (\Throwable) {
        }

        return self::$roots = array_values(array_unique($roots));
    }

    /**
     * Absolute path of a site-relative image path, or null when the path is not an image the module may touch
     * (traversal, unknown top directory, extension, symlink leaving the site).
     */
    public static function abs(DatabaseInterface $db, string $rel, bool $mustExist = true): ?string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $rel)) {
            return null;
        }
        $parts = explode('/', $rel);
        if (!\in_array($parts[0], self::roots($db), true) || \count($parts) < 2) {
            return null;
        }
        if (!\in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return null;
        }
        $abs  = JPATH_ROOT . '/' . $rel;
        $base = realpath(JPATH_ROOT . '/' . $parts[0]);
        $dir  = realpath(\dirname($abs));
        if ($base === false || $dir === false || !str_starts_with($dir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if ($mustExist && !is_file($abs)) {
            return null;
        }

        return $dir . DIRECTORY_SEPARATOR . basename($abs);
    }

    public static function backupPath(string $rel): string
    {
        return JPATH_ROOT . '/media/uxstudio-originals/' . ltrim($rel, '/');
    }

    /** Site-relative path of an absolute file below the site root (forward slashes), '' when outside. */
    public static function relative(string $abs): string
    {
        $root = rtrim(str_replace('\\', '/', (string) realpath(JPATH_ROOT)), '/') . '/';
        $abs  = str_replace('\\', '/', $abs);

        return str_starts_with($abs, $root) ? substr($abs, \strlen($root)) : '';
    }

    // ------------------------------------------------------------------ processing of bytes

    /**
     * Compresses image bytes in memory.
     *
     * @return array{data:string,changed:bool,note:string,width:int,height:int,variants:array<string,string>}
     */
    public function process(string $data, string $ext, bool $withVariants = true): array
    {
        $ext = strtolower($ext) === 'png' ? 'png' : 'jpeg';
        $res = ['data' => $data, 'changed' => false, 'note' => '', 'width' => 0, 'height' => 0, 'variants' => []];

        $info = @getimagesizefromstring($data);
        if (!$info) {
            return ['note' => 'decode_failed'] + $res;
        }
        $res['width']  = (int) $info[0];
        $res['height'] = (int) $info[1];
        if ($info[2] !== ($ext === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG)) {
            return ['note' => 'type_mismatch'] + $res;
        }
        if ($ext === 'png' && self::isAnimatedPng($data)) {
            return ['note' => 'animated'] + $res;
        }
        if (!self::fitsInMemory((int) $info[0], (int) $info[1])) {
            return ['note' => 'too_large'] + $res;
        }

        $codec = $this->decode($data);
        if ($codec === null) {
            return ['note' => 'decode_failed'] + $res;
        }

        $resized = false;
        if ($ext === 'jpeg') {
            $orientation = self::jpegOrientation($data);
            if ($orientation > 1) {
                $codec->orient($orientation);
                $resized = true;
            }
        }
        $fit = self::fit($codec->width(), $codec->height(), (int) ($this->settings['max_width'] ?? 0), (int) ($this->settings['max_height'] ?? 0));
        if ($fit !== null) {
            $codec->resize($fit[0], $fit[1]);
            $resized = true;
        }
        $res['width']  = $codec->width();
        $res['height'] = $codec->height();

        $quality = max(1, min(100, (int) ($this->settings['jpeg_quality'] ?? 82)));
        $out     = $codec->encode($ext, $quality);
        if ($out === null) {
            $res['note'] = 'encode_failed';
        } else {
            $limit = $resized ? \strlen($data) : (int) floor(\strlen($data) * (1 - self::MIN_GAIN));
            if (\strlen($out) <= $limit && \strlen($out) < \strlen($data)) {
                $res['data']    = $out;
                $res['changed'] = true;
            } else {
                $res['note'] = 'not_smaller';
            }
        }

        if ($withVariants) {
            $size = \strlen($res['data']);
            foreach (['webp' => 'convert_webp', 'avif' => 'convert_avif'] as $format => $flag) {
                if (empty($this->settings[$flag])) {
                    continue;
                }
                $bytes = $codec->encode($format, max(1, min(100, (int) ($this->settings[$format . '_quality'] ?? 75))));
                if ($bytes !== null && \strlen($bytes) < $size) {
                    $res['variants'][$format] = $bytes;
                }
            }
        }

        return $res;
    }

    private function decode(string $data): ?Codec
    {
        $use = self::library((string) ($this->settings['library'] ?? 'auto'));

        return match ($use) {
            'imagick' => ImagickCodec::fromBlob($data),
            'gd'      => GdCodec::fromBlob($data),
            default   => null,
        };
    }

    // ------------------------------------------------------------------ files

    /**
     * Optimizes one file on disk.
     *
     * @return array<string,mixed> status (optimized|skipped|failed|unchanged|ignored), original_size, file_size, note ...
     */
    public function optimizeFile(string $rel, string $source, bool $force = false, ?DatabaseInterface $db = null): array
    {
        $db  ??= $this->dbFromStore();
        $abs   = self::abs($db, $rel);
        if ($abs === null) {
            throw new ApiException(Text::_('UXS_IMAGE_OPTIMIZER_ERR_PATH'), 422, 'invalid_path');
        }
        $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $size = (int) filesize($abs);
        $row  = $this->store->get($rel);

        if ($size < max(0, (int) ($this->settings['min_size_kb'] ?? 0)) * 1024 && !$force) {
            return ['status' => 'ignored', 'note' => 'too_small', 'path' => $rel, 'file_size' => $size];
        }
        if ($row && (int) $row['file_size'] === $size && !$force) {
            return ['status' => 'unchanged'] + $row;
        }

        $backup     = self::backupPath($rel);
        $fromBackup = false;
        if ($force && is_file($backup)) {
            // Re-optimizing with new settings starts from the original, never from an already compressed file.
            $data       = (string) file_get_contents($backup);
            $fromBackup = true;
        } elseif ($force && $row && $row['status'] === 'optimized') {
            throw new ApiException(Text::_('UXS_IMAGE_OPTIMIZER_ERR_NO_BACKUP'), 409, 'no_backup');
        } else {
            $data = (string) file_get_contents($abs);
        }
        if ($data === '') {
            throw new ApiException(Text::_('UXS_IMAGE_OPTIMIZER_ERR_READ'), 500, 'read_failed');
        }

        if (!$force) {
            // Written before the heavy work: when PHP dies on the memory limit the file is not retried by every batch.
            $this->markFailed($rel, 'interrupted', $db);
        }
        $res = $this->process($data, $ext);

        return $this->persist($rel, $abs, $res, $data, $source, true, $fromBackup);
    }

    /**
     * Records that a file could not be handled. The recorded size equals the size on disk, so the file stops being
     * "pending" (bulk would otherwise return it in every batch) until it is replaced or optimized manually.
     */
    public function markFailed(string $rel, string $note, ?DatabaseInterface $db = null): void
    {
        $db  ??= $this->dbFromStore();
        $abs   = self::abs($db, $rel);
        if ($abs === null || \strlen($rel) > 700) {
            return;
        }
        $size = (int) filesize($abs);
        $this->store->save([
            'path' => $rel, 'status' => 'failed', 'original_size' => $size, 'file_size' => $size, 'webp_size' => 0, 'avif_size' => 0,
            'width' => 0, 'height' => 0, 'backup' => is_file(self::backupPath($rel)) ? 1 : 0, 'source' => 'bulk', 'note' => $note,
        ]);
    }

    /**
     * Writes the result of process(): backup of the original, the compressed file, the WebP/AVIF siblings and the row.
     *
     * @param array<string,mixed> $res        result of process()
     * @param string              $original   original bytes
     * @param bool                $writeMain  false when the file is already on disk in its final form (upload)
     * @param bool                $fromBackup the original comes from the backup, do not rewrite the backup
     *
     * @return array<string,mixed>
     */
    public function persist(string $rel, string $abs, array $res, string $original, string $source, bool $writeMain, bool $fromBackup = false): array
    {
        $backup    = self::backupPath($rel);
        $hasBackup = is_file($backup);
        $keep      = !empty($this->settings['keep_backup']);

        if ($res['changed']) {
            // The backup is written first (the main file is about to be overwritten) but removed again when that fails,
            // so a failed write leaves no orphaned backup behind.
            $newBackup = false;
            if ($keep && !$fromBackup) {
                $newBackup = !$hasBackup;
                $this->writeFile($backup, $original, true);
                $hasBackup = is_file($backup);
            }
            if ($writeMain && !$this->writeFile($abs, $res['data'], false)) {
                if ($newBackup) {
                    @unlink($backup);
                }
                throw new ApiException(Text::_('UXS_IMAGE_OPTIMIZER_ERR_WRITE'), 500, 'write_failed');
            }
        }

        $sizes = ['webp' => 0, 'avif' => 0];
        foreach (['webp', 'avif'] as $format) {
            $sibling = $abs . '.' . $format;
            if (isset($res['variants'][$format])) {
                if ($this->writeFile($sibling, $res['variants'][$format], false)) {
                    $sizes[$format] = \strlen($res['variants'][$format]);
                }
            } elseif (is_file($sibling)) {
                @unlink($sibling); // a stale sibling of an older version of the image
            }
        }

        clearstatcache(true, $abs);
        $fileSize = (int) filesize($abs);
        $failed   = \in_array($res['note'], ['decode_failed', 'type_mismatch', 'encode_failed', 'too_large'], true);
        $status   = $res['changed'] ? 'optimized' : ($failed ? 'failed' : 'skipped');
        $row      = [
            'path'          => $rel,
            'status'        => $status,
            'original_size' => $res['changed'] ? \strlen($original) : $fileSize,
            'file_size'     => $fileSize,
            'webp_size'     => $sizes['webp'],
            'avif_size'     => $sizes['avif'],
            'width'         => $res['width'],
            'height'        => $res['height'],
            'backup'        => $hasBackup && $res['changed'],
            'source'        => $source,
            'note'          => $res['note'],
        ];
        $this->store->save($row);

        return $row;
    }

    /** Puts the original back, removes the siblings and the backup. */
    public function restore(string $rel, ?DatabaseInterface $db = null): array
    {
        $db    ??= $this->dbFromStore();
        $abs     = self::abs($db, $rel);
        $backup  = self::backupPath($rel);
        if ($abs === null || !is_file($backup)) {
            throw new ApiException(Text::_('UXS_IMAGE_OPTIMIZER_ERR_NO_BACKUP'), 404, 'no_backup');
        }
        $data = (string) file_get_contents($backup);
        if ($data === '' || !$this->writeFile($abs, $data, false)) {
            throw new ApiException(Text::_('UXS_IMAGE_OPTIMIZER_ERR_WRITE'), 500, 'write_failed');
        }
        foreach (['webp', 'avif'] as $format) {
            if (is_file($abs . '.' . $format)) {
                @unlink($abs . '.' . $format);
            }
        }
        @unlink($backup);
        clearstatcache(true, $abs);
        $size = (int) filesize($abs);
        $info = @getimagesizefromstring($data);
        $row  = [
            'path' => $rel, 'status' => 'restored', 'original_size' => $size, 'file_size' => $size, 'webp_size' => 0, 'avif_size' => 0,
            'width' => (int) ($info[0] ?? 0), 'height' => (int) ($info[1] ?? 0), 'backup' => 0, 'source' => 'manual', 'note' => '',
        ];
        $this->store->save($row);

        return $row;
    }

    /** The file was deleted (Media Manager): remove siblings, backup and the row. */
    public function forget(string $rel, ?DatabaseInterface $db = null): void
    {
        $db  ??= $this->dbFromStore();
        $abs   = self::abs($db, $rel, false);
        if ($abs !== null) {
            foreach (['webp', 'avif'] as $format) {
                if (is_file($abs . '.' . $format)) {
                    @unlink($abs . '.' . $format);
                }
            }
        }
        $backup = self::backupPath($rel);
        if (is_file($backup)) {
            @unlink($backup);
        }
        $this->store->delete($rel);
    }

    /** A folder was deleted (Media Manager): rows and backups of the images that were inside. */
    public function forgetFolder(string $relFolder): void
    {
        $relFolder = trim(str_replace('\\', '/', $relFolder), '/');
        if ($relFolder === '' || !str_contains($relFolder, '/') || preg_match('#(^|/)\.\.?(/|$)#', $relFolder)) {
            return;
        }
        $this->store->deletePrefix($relFolder . '/');
        $dir = self::backupPath($relFolder);
        if (is_dir($dir) && !is_link($dir)) {
            self::removeTree($dir);
        }
    }

    private static function removeTree(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** Removes derivatives of files that no longer exist (moved / renamed outside our events) and stale WebP / AVIF siblings. */
    public function cleanupOrphans(DatabaseInterface $db): int
    {
        $n = 0;
        foreach ($this->store->paths() as $rel) {
            $top = explode('/', $rel)[0];
            if (\in_array($top, self::roots($db), true) && !preg_match('#(^|/)\.\.?(/|$)#', $rel) && !is_file(JPATH_ROOT . '/' . $rel)) {
                $this->forget($rel, $db);
                $n++;
            }
        }

        return $n;
    }

    /** Deletes a WebP / AVIF sibling that is older than the image it belongs to (the image was replaced outside the Media Manager). */
    public static function dropStaleSiblings(string $abs): void
    {
        $mtime = (int) @filemtime($abs);
        foreach (['webp', 'avif'] as $format) {
            $sibling = $abs . '.' . $format;
            if (is_file($sibling) && (int) @filemtime($sibling) < $mtime) {
                @unlink($sibling);
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Atomic write: temp file in the same folder, then rename. Creates the folder (backups are protected from the web). */
    private function writeFile(string $path, string $data, bool $backup): bool
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }
        if ($backup) {
            self::protectBackups();
        }
        $tmp = $path . '.uxs-tmp';
        if (@file_put_contents($tmp, $data) === false) {
            @unlink($tmp);

            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** Backups are copies of the originals; nobody should be able to list or download them. */
    public static function protectBackups(): void
    {
        $dir = JPATH_ROOT . '/media/uxstudio-originals';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# Originals of optimized images: not for the web\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\nOptions -Indexes\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '<!DOCTYPE html><title></title>');
        }
    }

    private function dbFromStore(): DatabaseInterface
    {
        return \Ux1\UxStudio\Core\Kernel::instance()->db();
    }

    /** Size inside the box (aspect kept, never an upscale) or null when it already fits. */
    public static function fit(int $w, int $h, int $maxW, int $maxH): ?array
    {
        if ($w < 1 || $h < 1 || ($maxW < 1 && $maxH < 1)) {
            return null;
        }
        $ratio = 1.0;
        if ($maxW > 0 && $w > $maxW) {
            $ratio = min($ratio, $maxW / $w);
        }
        if ($maxH > 0 && $h > $maxH) {
            $ratio = min($ratio, $maxH / $h);
        }
        if ($ratio >= 1.0) {
            return null;
        }

        return [max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio))];
    }

    /** Peak use is about 10 bytes per pixel: the decoded source, a rotated / resized copy, the flattened copy for JPEG and the WebP / AVIF encoders. */
    public static function fitsInMemory(int $w, int $h): bool
    {
        $limit = self::memoryLimit();
        if ($limit < 0) {
            return true;
        }

        return ($w * $h * 10) + memory_get_usage(true) < $limit * 0.9;
    }

    private static function memoryLimit(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }
        $n = (int) $raw;
        switch (strtolower(substr($raw, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }

        return $n;
    }

    /** APNG has an acTL chunk before the first IDAT chunk. */
    public static function isAnimatedPng(string $data): bool
    {
        $idat = strpos($data, 'IDAT');

        return strpos($idat === false ? $data : substr($data, 0, $idat), 'acTL') !== false;
    }

    /** EXIF orientation (1-8) of a JPEG, 1 when absent. */
    public static function jpegOrientation(string $blob): int
    {
        $len = \strlen($blob);
        if ($len < 12 || !str_starts_with($blob, "\xFF\xD8")) {
            return 1;
        }
        $pos = 2;
        while ($pos + 4 <= $len) {
            if ($blob[$pos] !== "\xFF") {
                break;
            }
            $marker = \ord($blob[$pos + 1]);
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0xFF) {
                $pos += $marker === 0xFF ? 1 : 2;
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size = (\ord($blob[$pos + 2]) << 8) | \ord($blob[$pos + 3]);
            if ($marker === 0xE1 && substr($blob, $pos + 4, 6) === "Exif\0\0") {
                $tiff = $pos + 10;
                $end  = min($len, $pos + 2 + $size);
                $le   = substr($blob, $tiff, 2) === 'II';
                $u16  = static function (int $o) use ($blob, $le, $end): int {
                    return $o + 2 <= $end ? (int) unpack($le ? 'v' : 'n', substr($blob, $o, 2))[1] : 0;
                };
                $u32 = static function (int $o) use ($blob, $le, $end): int {
                    return $o + 4 <= $end ? (int) unpack($le ? 'V' : 'N', substr($blob, $o, 4))[1] : 0;
                };
                $ifd   = $tiff + $u32($tiff + 4);
                $count = min(64, $u16($ifd));
                for ($i = 0; $i < $count; $i++) {
                    $entry = $ifd + 2 + 12 * $i;
                    if ($u16($entry) === 0x0112) {
                        $value = $u16($entry + 8);

                        return $value >= 1 && $value <= 8 ? $value : 1;
                    }
                }

                return 1;
            }
            $pos += 2 + $size;
        }

        return 1;
    }
}
