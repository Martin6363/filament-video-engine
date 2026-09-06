<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Exceptions\QualityExceedsSourceException;
use Martin6363\FilamentVideoEngine\Jobs\GenerateQualityStreamJob;
use Martin6363\FilamentVideoEngine\Models\VideoConversion;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TranscodingProgressTracker;

/**
 * Regenerate one or more HLS resolution streams without a full re-transcode.
 */
final class RegenerateQualityAction
{
    public function __construct(
        private readonly TranscodingProgressTracker $tracker,
    ) {}

    /**
     * Dispatch a job to rebuild one quality ladder step.
     */
    public function execute(VideoMedia $video, VideoQualityEnum|string $quality): void
    {
        $this->executeMany($video, [$quality]);
    }

    /**
     * Dispatch jobs to rebuild multiple quality ladder steps in parallel.
     *
     * @param  list<VideoQualityEnum|string>  $qualities
     * @return int Number of quality jobs dispatched
     */
    public function executeMany(VideoMedia $video, array $qualities): int
    {
        $labels = $this->normalizeQualities($qualities);

        if ($labels === []) {
            return 0;
        }

        foreach ($labels as $label) {
            $enum = VideoQualityEnum::fromLabel($label);

            if (! $video->canEncodeQuality($enum)) {
                throw QualityExceedsSourceException::for($enum, $video->height);
            }
        }

        foreach ($labels as $label) {
            VideoConversion::query()->updateOrCreate(
                [
                    'video_media_id' => $video->id,
                    'type' => ConversionTypeEnum::HlsRendition,
                    'quality' => $label,
                ],
                [
                    'status' => TranscodingStatusEnum::Processing,
                    'progress_percent' => 0,
                    'error_message' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                ],
            );

            $this->dispatchJob($video, $label);
        }

        if (! $video->status->isInProgress()) {
            $video->forceFill([
                'status' => TranscodingStatusEnum::Processing,
                'progress_percent' => 0,
                'current_step' => 'hls:regen',
                'error_message' => null,
            ])->save();
        }

        $this->tracker->syncVideoFromHlsConversions($video->refresh());

        return count($labels);
    }

    /**
     * @param  list<VideoQualityEnum|string>  $qualities
     * @return list<string>
     */
    private function normalizeQualities(array $qualities): array
    {
        $labels = [];

        foreach ($qualities as $quality) {
            $label = $quality instanceof VideoQualityEnum ? $quality->value : trim((string) $quality);

            if ($label === '') {
                continue;
            }

            $labels[$label] = VideoQualityEnum::fromLabel($label)->value;
        }

        return array_values($labels);
    }

    /**
     * Queue a single quality regeneration job.
     */
    private function dispatchJob(VideoMedia $video, string $label): void
    {
        $connection = config('filament-video-engine.queue.connection');
        $queue = config('filament-video-engine.queue.queue', 'video-engine');

        $job = new GenerateQualityStreamJob($video->id, $label);

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);
    }
}
