<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

\defined('_JEXEC') or die;

/** Imagick based codec (used when the extension is loaded and the library setting is auto or imagick). */
final class ImagickCodec implements Codec
{
    private function __construct(private \Imagick $im)
    {
    }

    public static function available(): bool
    {
        return \extension_loaded('imagick') && class_exists('\Imagick');
    }

    public static function canEncode(string $format): bool
    {
        if (!self::available()) {
            return false;
        }
        try {
            return !empty(\Imagick::queryFormats(strtoupper($format)));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function fromBlob(string $blob): ?self
    {
        if (!self::available()) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->readImageBlob($blob);
            if ($im->getNumberImages() > 1) {
                return null; // animated
            }

            return new self($im);
        } catch (\Throwable) {
            return null;
        }
    }

    public function width(): int
    {
        return $this->im->getImageWidth();
    }

    public function height(): int
    {
        return $this->im->getImageHeight();
    }

    public function orient(int $orientation): void
    {
        if ($orientation < 2 || $orientation > 8) {
            return;
        }
        try {
            if (method_exists($this->im, 'autoOrient')) {
                $this->im->autoOrient();
            } elseif (method_exists($this->im, 'autoOrientImage')) {
                $this->im->autoOrientImage();
            } else {
                // Older Imagick: apply the EXIF orientation to the pixels by hand (stripImage() drops the tag later).
                $bg = new \ImagickPixel('none');
                switch ($orientation) {
                    case 2:
                        $this->im->flopImage();
                        break;
                    case 3:
                        $this->im->rotateImage($bg, 180);
                        break;
                    case 4:
                        $this->im->flipImage();
                        break;
                    case 5:
                        $this->im->rotateImage($bg, 90);
                        $this->im->flopImage();
                        break;
                    case 6:
                        $this->im->rotateImage($bg, 90);
                        break;
                    case 7:
                        $this->im->rotateImage($bg, 270);
                        $this->im->flopImage();
                        break;
                    case 8:
                        $this->im->rotateImage($bg, 270);
                        break;
                }
            }
            $this->im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        } catch (\Throwable) {
        }
    }

    public function resize(int $width, int $height): void
    {
        $this->im->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
    }

    public function encode(string $format, int $quality): ?string
    {
        try {
            $copy = clone $this->im;
            $copy->stripImage();
            if ($format === 'jpeg') {
                $copy->setImageBackgroundColor('white');
                $copy->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $copy->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
            }
            $copy->setImageFormat($format);
            $copy->setImageCompressionQuality($quality);
            if ($format === 'png') {
                $copy->setOption('png:compression-level', '9');
            }
            $blob = $copy->getImageBlob();
            $copy->clear();

            return $blob !== '' ? $blob : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
