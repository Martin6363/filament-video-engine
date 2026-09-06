<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\DTOs;

/**
 * Poster / thumbnail strategy: custom upload path or frame extraction timestamp.
 */
readonly class ThumbnailOptionsDto
{
    public function __construct(
        public ?string $customPosterPath = null,
        public ?float $extractAtSeconds = null,
        public ?string $format = null,
    ) {}

    /**
     * Build from package defaults with optional overrides.
     */
    public static function fromConfig(?string $customPosterPath = null, ?float $extractAtSeconds = null): self
    {
        return new self(
            customPosterPath: $customPosterPath,
            extractAtSeconds: $extractAtSeconds ?? (float) config(
                'filament-video-engine.thumbnail.default_timestamp',
                5.0,
            ),
            format: (string) config('filament-video-engine.thumbnail.format', 'jpg'),
        );
    }

    /**
     * Prefer a custom admin-uploaded poster when present.
     */
    public function usesCustomPoster(): bool
    {
        return $this->customPosterPath !== null && $this->customPosterPath !== '';
    }
}
