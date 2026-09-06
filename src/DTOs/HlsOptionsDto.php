<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\DTOs;

use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;

/**
 * HLS packaging options for master playlist and segment generation.
 */
readonly class HlsOptionsDto
{
    /**
     * @param  list<VideoQualityEnum>  $qualities
     */
    public function __construct(
        public array $qualities = [],
        public int $segmentDuration = 6,
        public string $playlistType = 'vod',
        public bool $encryptionEnabled = false,
        public ?string $encryptionKeyPath = null,
    ) {}

    /**
     * Hydrate from package configuration and optional quality overrides.
     *
     * @param  list<VideoQualityEnum>|null  $qualities
     */
    public static function fromConfig(?array $qualities = null): self
    {
        return new self(
            qualities: $qualities ?? VideoQualityEnum::defaults(),
            segmentDuration: (int) config('filament-video-engine.hls.segment_duration', 6),
            playlistType: (string) config('filament-video-engine.hls.playlist_type', 'vod'),
            encryptionEnabled: (bool) config('filament-video-engine.hls.encryption.enabled', false),
            encryptionKeyPath: config('filament-video-engine.hls.encryption.key_path'),
        );
    }
}
