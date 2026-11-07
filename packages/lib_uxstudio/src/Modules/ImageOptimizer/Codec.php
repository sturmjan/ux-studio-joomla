<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

\defined('_JEXEC') or die;

/**
 * One decoded image. Implemented on top of GD and Imagick; both strip metadata (EXIF, ICC) on encode,
 * the EXIF orientation is applied to the pixels first.
 */
interface Codec
{
    public function width(): int;

    public function height(): int;

    /** Applies an EXIF orientation value (1-8) to the pixels. */
    public function orient(int $orientation): void;

    /** Scales to the exact size (never called for an upscale). */
    public function resize(int $width, int $height): void;

    /**
     * @param string $format jpeg|png|webp|avif
     *
     * @return string|null encoded bytes, null when the format cannot be encoded
     */
    public function encode(string $format, int $quality): ?string;
}
