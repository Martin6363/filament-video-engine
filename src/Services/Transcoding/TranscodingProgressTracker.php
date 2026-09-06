<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Transcoding;

use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Models\VideoConversion;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Persists live transcoding progress onto video_media and video_conversions.
 */
final class TranscodingProgressTracker
{
    /**
     * Update the parent video media row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateVideo(VideoMedia $video, array $attributes): void
    {
        $video->forceFill($attributes)->save();
    }

    /**
     * Mark the video as processing at a given overall percentage.
     */
    public function videoProgress(
        VideoMedia $video,
        float $percent,
        string $step,
        TranscodingStatusEnum $status = TranscodingStatusEnum::Processing,
    ): void {
        $this->updateVideo($video, [
            'status' => $status,
            'progress_percent' => round(min(100.0, max(0.0, $percent)), 2),
            'current_step' => $step,
        ]);
    }

    /**
     * Update a conversion row's live progress.
     */
    public function conversionProgress(VideoConversion $conversion, float $percent, TranscodingStatusEnum $status = TranscodingStatusEnum::Processing): void
    {
        $conversion->forceFill([
            'status' => $status,
            'progress_percent' => round(min(100.0, max(0.0, $percent)), 2),
        ])->save();
    }

    /**
     * Map a quality-local 0–100 encode percent into an overall band.
     */
    public function mapBand(float $localPercent, float $bandStart, float $bandEnd): float
    {
        $local = min(100.0, max(0.0, $localPercent)) / 100.0;

        return $bandStart + (($bandEnd - $bandStart) * $local);
    }

    /**
     * Derive parent video progress from active HLS rendition conversions.
     */
    public function syncVideoFromHlsConversions(VideoMedia $video): void
    {
        $video->loadMissing('conversions');

        $renditions = $video->conversions->filter(
            static fn (VideoConversion $conversion): bool => $conversion->type === ConversionTypeEnum::HlsRendition,
        );

        if ($renditions->isEmpty()) {
            return;
        }

        $active = $renditions->filter(
            static fn (VideoConversion $conversion): bool => in_array($conversion->status, [
                TranscodingStatusEnum::Pending,
                TranscodingStatusEnum::Queued,
                TranscodingStatusEnum::Processing,
            ], true),
        );

        if ($active->isNotEmpty()) {
            $percent = (float) $active->avg(
                static fn (VideoConversion $conversion): float => (float) $conversion->progress_percent,
            );

            $currentQuality = $active->sortByDesc(
                static fn (VideoConversion $conversion): int => $conversion->updated_at?->getTimestamp() ?? 0,
            )->first()?->quality;

            $step = $video->current_step;

            if (! is_string($step) || $step === '') {
                $step = is_string($currentQuality) && $currentQuality !== ''
                    ? 'hls:'.$currentQuality.':regen'
                    : 'hls:regen';
            }

            $this->videoProgress($video, $percent, $step, TranscodingStatusEnum::Processing);

            return;
        }

        if ($renditions->contains(
            static fn (VideoConversion $conversion): bool => $conversion->status === TranscodingStatusEnum::Failed,
        )) {
            $this->updateVideo($video, [
                'status' => TranscodingStatusEnum::Partial,
                'current_step' => 'hls:regen:partial',
            ]);

            return;
        }

        if ($renditions->every(
            static fn (VideoConversion $conversion): bool => $conversion->status === TranscodingStatusEnum::Completed,
        )) {
            $this->videoProgress($video, 100, 'done', TranscodingStatusEnum::Completed);
        }
    }
}
