<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Illuminate\Support\Collection;
use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Exceptions\NoHlsRenditionsException;
use Martin6363\FilamentVideoEngine\Models\VideoConversion;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TranscodingProgressTracker;

/**
 * Re-encode existing HLS renditions so updated watermark settings are baked in.
 *
 * Uses one GenerateQualityStreamJob per quality (parallel on the queue) instead of
 * a full re-transcode, which is faster and avoids re-uploading the source file.
 */
final class ReapplyWatermarkAction
{
    public function __construct(
        private readonly RegenerateQualityAction $regenerateQuality,
        private readonly TranscodingProgressTracker $tracker,
    ) {}

    /**
     * Queue regeneration for every existing HLS rendition of the video.
     *
     * @return int Number of quality jobs dispatched
     */
    public function execute(VideoMedia $video): int
    {
        if ($video->original_path === null || $video->original_path === '') {
            throw NoHlsRenditionsException::missingSource($video->uuid);
        }

        $qualities = $this->resolveRenditionQualities($video);

        if ($qualities->isEmpty()) {
            throw NoHlsRenditionsException::forVideo($video->uuid);
        }

        $dispatched = 0;

        foreach ($qualities as $quality) {
            if (! $video->canEncodeQuality($quality)) {
                continue;
            }

            VideoConversion::query()->updateOrCreate(
                [
                    'video_media_id' => $video->id,
                    'type' => ConversionTypeEnum::HlsRendition,
                    'quality' => $quality,
                ],
                [
                    'status' => TranscodingStatusEnum::Processing,
                    'progress_percent' => 0,
                    'error_message' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                ],
            );

            $this->regenerateQuality->execute($video, $quality);
            $dispatched++;
        }

        if ($dispatched === 0) {
            throw NoHlsRenditionsException::forVideo($video->uuid);
        }

        $video->forceFill([
            'status' => TranscodingStatusEnum::Processing,
            'progress_percent' => 0,
            'current_step' => 'watermark:reapply',
            'error_message' => null,
        ])->save();

        $this->tracker->syncVideoFromHlsConversions($video->refresh());

        return $dispatched;
    }

    /**
     * Whether watermark settings can be applied to existing streams.
     */
    public function canExecute(VideoMedia $video): bool
    {
        if ($video->original_path === null || $video->original_path === '') {
            return false;
        }

        if ($video->status->isInProgress()) {
            return false;
        }

        return $this->resolveRenditionQualities($video)->contains(
            fn (string $quality): bool => $video->canEncodeQuality($quality),
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function resolveRenditionQualities(VideoMedia $video): Collection
    {
        return $video->conversions()
            ->where('type', ConversionTypeEnum::HlsRendition)
            ->whereNotNull('quality')
            ->pluck('quality')
            ->unique()
            ->values();
    }
}

