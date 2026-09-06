<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Exceptions;

use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;

/**
 * Raised when a requested quality exceeds the probed source resolution.
 */
final class QualityExceedsSourceException extends VideoEngineException
{
    /**
     * Create for a quality that would upscale the source.
     */
    public static function for(VideoQualityEnum $quality, ?int $sourceHeight): self
    {
        $sourceLabel = $sourceHeight !== null && $sourceHeight > 0
            ? (string) $sourceHeight.'px'
            : __('filament-video-engine::messages.resource.helpers.unknown_source_height');

        return new self(__('filament-video-engine::messages.resource.helpers.quality_exceeds_source', [
            'quality' => $quality->value,
            'source' => $sourceLabel,
        ]));
    }
}
