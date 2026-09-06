<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Enums;

/**
 * Types of derived assets produced by the transcoding pipeline.
 */
enum ConversionTypeEnum: string
{
    case HlsRendition = 'hls_rendition';
    case HlsMaster = 'hls_master';
    case Progressive = 'progressive';
    case Poster = 'poster';
    case Thumbnail = 'thumbnail';
    case PreviewSprite = 'preview_sprite';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return __('filament-video-engine::messages.conversion_type.'.$this->value);
    }
}
