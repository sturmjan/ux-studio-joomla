<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

\defined('_JEXEC') or die;

/** GD based codec. */
final class GdCodec implements Codec
{
    private function __construct(private \GdImage $im)
    {
    }

    public static function fromBlob(string $blob): ?self
    {
        $im = @imagecreatefromstring($blob);
        if (!$im instanceof \GdImage) {
            return null;
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);

        return new self($im);
    }

    public static function canEncode(string $format): bool
    {
        return match ($format) {
            'jpeg'  => \function_exists('imagejpeg'),
            'png'   => \function_exists('imagepng'),
            'webp'  => \function_exists('imagewebp'),
            'avif'  => \function_exists('imageavif'),
            default => false,
        };
    }

    public function width(): int
    {
        return imagesx($this->im);
    }

    public function height(): int
    {
        return imagesy($this->im);
    }

    public function orient(int $orientation): void
    {
        if ($orientation < 2 || $orientation > 8) {
            return;
        }
        $transparent = imagecolorallocatealpha($this->im, 0, 0, 0, 127);
        switch ($orientation) {
            case 2:
                imageflip($this->im, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $this->swap(imagerotate($this->im, 180, $transparent));
                break;
            case 4:
                imageflip($this->im, IMG_FLIP_VERTICAL);
                break;
            case 5: // transpose: rotate 90 clockwise, then mirror
                $this->swap(imagerotate($this->im, -90, $transparent));
                imageflip($this->im, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $this->swap(imagerotate($this->im, -90, $transparent));
                break;
            case 7: // transverse: rotate 90 counter-clockwise, then mirror
                $this->swap(imagerotate($this->im, 90, $transparent));
                imageflip($this->im, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $this->swap(imagerotate($this->im, 90, $transparent));
                break;
        }
        imagealphablending($this->im, false);
        imagesavealpha($this->im, true);
    }

    public function resize(int $width, int $height): void
    {
        if (!imageistruecolor($this->im)) {
            imagepalettetotruecolor($this->im);
        }
        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $this->im, 0, 0, 0, 0, $width, $height, imagesx($this->im), imagesy($this->im));
        $this->swap($dst);
    }

    public function encode(string $format, int $quality): ?string
    {
        if (!self::canEncode($format)) {
            return null;
        }
        $im = $this->im;
        if (($format === 'webp' || $format === 'avif') && !imageistruecolor($im)) {
            imagepalettetotruecolor($im);
            imagealphablending($im, false);
            imagesavealpha($im, true);
        }

        ob_start();
        try {
            $ok = match ($format) {
                'jpeg'  => $this->encodeJpeg($im, $quality),
                'png'   => imagepng($im, null, 9, PNG_ALL_FILTERS),
                'webp'  => imagewebp($im, null, $quality),
                // speed 6 (0 slowest .. 9 fastest) keeps the request time reasonable
                'avif'  => imageavif($im, null, $quality, 6),
                default => false,
            };
        } catch (\Throwable) {
            $ok = false;
        }
        $out = (string) ob_get_clean();

        return $ok && $out !== '' ? $out : null;
    }

    private function encodeJpeg(\GdImage $im, int $quality): bool
    {
        // A JPEG has no alpha: flatten a transparent source on white. Progressive JPEG is usually smaller.
        $flat = imagecreatetruecolor(imagesx($im), imagesy($im));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
        imageinterlace($flat, true);

        return imagejpeg($flat, null, $quality);
    }

    private function swap(\GdImage|false $next): void
    {
        if ($next instanceof \GdImage) {
            $this->im = $next;
        }
    }
}
