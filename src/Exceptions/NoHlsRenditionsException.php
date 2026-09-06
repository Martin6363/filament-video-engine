<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Exceptions;

use RuntimeException;

/**
 * Thrown when watermark re-apply is requested but no HLS renditions exist.
 */
final class NoHlsRenditionsException extends RuntimeException
{
    /**
     * Build an exception for a video without stored renditions.
     */
    public static function forVideo(string $uuid): self
    {
        return new self(sprintf(
            'No HLS renditions are available to re-apply watermark settings for video [%s].',
            $uuid,
        ));
    }

    /**
     * Build an exception for a video without a source file.
     */
    public static function missingSource(string $uuid): self
    {
        return new self(sprintf(
            'Cannot re-apply watermark because video [%s] has no source file.',
            $uuid,
        ));
    }
}
