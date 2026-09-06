<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\DTOs;

use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;

/**
 * Single quality / rendition entry exposed via the API and player.
 */
readonly class QualityStreamDto
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public VideoQualityEnum $quality,
        public string $playlistUrl,
        public ?string $progressiveUrl = null,
        public int $bandwidth = 0,
        public int $width = 0,
        public int $height = 0,
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quality' => $this->quality->value,
            'playlist_url' => $this->playlistUrl,
            'progressive_url' => $this->progressiveUrl,
            'bandwidth' => $this->bandwidth,
            'width' => $this->width,
            'height' => $this->height,
            'meta' => $this->meta,
        ];
    }
}
