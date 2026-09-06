<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\DTOs;

use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;

/**
 * Full options bag passed into the transcoding pipeline.
 */
readonly class TranscodingOptionsDto
{
    /**
     * @param  list<VideoQualityEnum>  $qualities
     */
    public function __construct(
        public array $qualities = [],
        public ThumbnailOptionsDto $thumbnail = new ThumbnailOptionsDto,
        public WatermarkOptionsDto $watermark = new WatermarkOptionsDto,
        public HlsOptionsDto $hls = new HlsOptionsDto,
        public bool $skipUpscale = true,
    ) {}

    /**
     * Build a complete options object from config with optional overrides.
     *
     * @param  list<VideoQualityEnum>|null  $qualities
     */
    public static function fromConfig(
        ?array $qualities = null,
        ?ThumbnailOptionsDto $thumbnail = null,
        ?WatermarkOptionsDto $watermark = null,
    ): self {
        $resolvedQualities = $qualities ?? VideoQualityEnum::defaults();

        return new self(
            qualities: $resolvedQualities,
            thumbnail: $thumbnail ?? ThumbnailOptionsDto::fromConfig(),
            watermark: $watermark ?? WatermarkOptionsDto::fromConfig(),
            hls: HlsOptionsDto::fromConfig($resolvedQualities),
        );
    }
}
