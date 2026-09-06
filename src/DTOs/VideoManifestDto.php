<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\DTOs;

use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;

/**
 * Master HLS manifest payload for headless clients (Next.js / React / Mobile).
 */
readonly class VideoManifestDto
{
    /**
     * @param  list<QualityStreamDto>  $qualities
     * @param  list<string>  $skippedQualities
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $uuid,
        public string $masterPlaylistUrl,
        public TranscodingStatusEnum $status,
        public array $qualities = [],
        public ?string $posterUrl = null,
        public ?float $durationSeconds = null,
        public ?string $sourceResolution = null,
        public array $skippedQualities = [],
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'master_playlist_url' => $this->masterPlaylistUrl,
            'status' => $this->status->value,
            'poster_url' => $this->posterUrl,
            'duration_seconds' => $this->durationSeconds,
            'source_resolution' => $this->sourceResolution,
            'skipped_qualities' => $this->skippedQualities,
            'qualities' => array_map(
                static fn (QualityStreamDto $quality): array => $quality->toArray(),
                $this->qualities,
            ),
            'meta' => $this->meta,
        ];
    }
}
