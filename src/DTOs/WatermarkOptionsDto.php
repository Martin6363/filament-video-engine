<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\DTOs;

use Martin6363\FilamentVideoEngine\Enums\WatermarkPositionEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Watermark overlay options applied during FFmpeg execution.
 */
readonly class WatermarkOptionsDto
{
    public function __construct(
        public bool $enabled = false,
        public ?string $path = null,
        public WatermarkPositionEnum $position = WatermarkPositionEnum::BottomRight,
        public float $opacity = 0.6,
        public int $margin = 20,
        public float $maxWidthPercent = 12.0,
    ) {}

    /**
     * Hydrate from package configuration.
     */
    public static function fromConfig(): self
    {
        $position = WatermarkPositionEnum::tryFrom(
            (string) config('filament-video-engine.watermark.position', 'bottom-right'),
        ) ?? WatermarkPositionEnum::BottomRight;

        return new self(
            enabled: (bool) config('filament-video-engine.watermark.enabled', false),
            path: config('filament-video-engine.watermark.path'),
            position: $position,
            opacity: (float) config('filament-video-engine.watermark.opacity', 0.6),
            margin: (int) config('filament-video-engine.watermark.margin', 20),
            maxWidthPercent: self::normalizeMaxWidthPercent(
                config('filament-video-engine.watermark.max_width_percent', 12),
            ),
        );
    }

    /**
     * Resolve effective watermark options for a specific video row.
     *
     * Per-video settings override the global config when enabled on the record.
     */
    public static function forVideo(VideoMedia $video): self
    {
        $config = self::fromConfig();

        if (! $video->watermark_enabled) {
            return new self(enabled: false);
        }

        $path = filled($video->watermark_path) ? (string) $video->watermark_path : $config->path;

        if ($path === null || $path === '') {
            return new self(enabled: false);
        }

        $position = WatermarkPositionEnum::tryFrom((string) ($video->watermark_position ?? ''))
            ?? $config->position;

        return new self(
            enabled: true,
            path: $path,
            position: $position,
            opacity: $video->watermark_opacity ?? $config->opacity,
            margin: $video->watermark_margin ?? $config->margin,
            maxWidthPercent: self::normalizeMaxWidthPercent(
                $video->watermark_scale_percent ?? $config->maxWidthPercent,
            ),
        );
    }

    /**
     * Clamp watermark width to a sensible percentage of the scaled video width.
     */
    public static function normalizeMaxWidthPercent(mixed $percent): float
    {
        if (! is_numeric($percent)) {
            return 12.0;
        }

        return max(1.0, min(50.0, (float) $percent));
    }

    /**
     * Whether a watermark file should be applied.
     */
    public function shouldApply(): bool
    {
        return $this->enabled && $this->path !== null && $this->path !== '';
    }
}
