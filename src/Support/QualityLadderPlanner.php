<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Support;

use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;

/**
 * Plans which HLS qualities to encode vs skip (no upscale).
 */
final class QualityLadderPlanner
{
    public const SKIP_REASON_SOURCE_RESOLUTION_LOWER = 'source_resolution_lower';

    /**
     * Filter the requested ladder against source height when skip-upscale is on.
     *
     * @param  list<VideoQualityEnum>  $requested
     * @return array{
     *     planned: list<VideoQualityEnum>,
     *     skipped: list<VideoQualityEnum>,
     *     skip_reason: string|null
     * }
     */
    public function plan(array $requested, ?int $sourceHeight, bool $skipUpscale): array
    {
        $planned = [];
        $skipped = [];

        foreach ($requested as $quality) {
            if (! $this->isQualityAllowedForSource($quality, $sourceHeight, $skipUpscale)) {
                $skipped[] = $quality;

                continue;
            }

            $planned[] = $quality;
        }

        return [
            'planned' => $planned,
            'skipped' => $skipped,
            'skip_reason' => $skipped !== [] ? self::SKIP_REASON_SOURCE_RESOLUTION_LOWER : null,
        ];
    }

    /**
     * Whether a ladder step fits within the probed source height (no upscale).
     */
    public function isQualityAllowedForSource(
        VideoQualityEnum $quality,
        ?int $sourceHeight,
        bool $skipUpscale = true,
    ): bool {
        if (! $skipUpscale || $sourceHeight === null || $sourceHeight <= 0) {
            return true;
        }

        return $sourceHeight >= $quality->height();
    }

    /**
     * Human-readable source label, e.g. "480p (854x480)".
     */
    public function sourceResolutionLabel(?int $width, ?int $height): ?string
    {
        if ($height === null || $height <= 0) {
            return null;
        }

        $label = $this->nearestQualityLabel($height);

        if ($width !== null && $width > 0) {
            return sprintf('%s (%dx%d)', $label, $width, $height);
        }

        return $label;
    }

    /**
     * Short badge label, e.g. "480p".
     */
    public function sourceQualityBadge(?int $height): ?string
    {
        if ($height === null || $height <= 0) {
            return null;
        }

        return $this->nearestQualityLabel($height);
    }

    /**
     * Map a pixel height to the closest named ladder step.
     */
    public function nearestQualityLabel(int $height): string
    {
        $best = VideoQualityEnum::P240;

        foreach (VideoQualityEnum::cases() as $quality) {
            if (abs($quality->height() - $height) < abs($best->height() - $height)) {
                $best = $quality;
            }
        }

        return $best->value;
    }

    /**
     * Decide final status after encoding planned qualities.
     *
     * Skipped (upscale-prevented) qualities never force Partial.
     */
    public function resolveStatus(int $plannedCount, int $producedCount, int $failedCount): TranscodingStatusEnum
    {
        if ($producedCount <= 0) {
            return TranscodingStatusEnum::Failed;
        }

        if ($failedCount > 0 || ($plannedCount > 0 && $producedCount < $plannedCount)) {
            return TranscodingStatusEnum::Partial;
        }

        return TranscodingStatusEnum::Completed;
    }
}
