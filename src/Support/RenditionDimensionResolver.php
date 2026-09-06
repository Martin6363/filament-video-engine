<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Support;

use Martin6363\FilamentVideoEngine\DTOs\MediaProbeDto;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;

/**
 * Predicts encoded HLS rendition dimensions from source probe data.
 */
final class RenditionDimensionResolver
{
    /**
     * Even output width for a target quality, capped by the source aspect ratio.
     */
    public static function targetWidth(VideoQualityEnum $quality, MediaProbeDto $probe): int
    {
        $width = (int) (round($quality->height() * 16 / 9 / 2) * 2);

        if ($probe->width !== null && $probe->height !== null && $probe->height > 0) {
            $scaled = (int) (round(($probe->width / $probe->height) * $quality->height() / 2) * 2);
            $width = min($width, max(2, $scaled));
        }

        return min($width, $quality->maxWidth());
    }

    /**
     * Even watermark width for a rendition based on a percentage of the output frame.
     */
    public static function watermarkWidth(
        VideoQualityEnum $quality,
        MediaProbeDto $probe,
        float $maxWidthPercent,
    ): int {
        $targetWidth = self::targetWidth($quality, $probe);
        $percent = max(1.0, min(50.0, $maxWidthPercent));
        $width = max(2, (int) round($targetWidth * $percent / 100));

        return $width - ($width % 2);
    }
}
