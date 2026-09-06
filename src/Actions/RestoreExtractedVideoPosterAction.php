<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Martin6363\FilamentVideoEngine\DTOs\ThumbnailOptionsDto;
use Martin6363\FilamentVideoEngine\Jobs\RestoreExtractedVideoPosterJob;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Clear a custom poster and restore an extracted frame from the source video.
 *
 * Prefer {@see queue()} from Filament/API so FFmpeg runs on the video-engine worker.
 * {@see execute()} performs the actual extract (called by the job).
 */
final class RestoreExtractedVideoPosterAction
{
    public function __construct(
        private readonly ExtractThumbnailAction $extractThumbnail,
        private readonly DeleteReplacedVideoPosterAction $deleteReplacedPoster,
    ) {}

    /**
     * Whether the video can fall back to an FFmpeg-extracted poster.
     */
    public function canRestore(VideoMedia $video): bool
    {
        return filled($video->original_path)
            && filled($video->uuid)
            && ! $video->trashed();
    }

    /**
     * Mark poster restore as queued and dispatch {@see RestoreExtractedVideoPosterJob}.
     */
    public function queue(VideoMedia $video): VideoMedia
    {
        $video->forceFill([
            'poster_is_custom' => false,
            'current_step' => 'poster:restoring',
            'error_message' => null,
        ])->save();

        $connection = config('filament-video-engine.queue.connection');
        $queue = config('filament-video-engine.queue.queue', 'video-engine');

        $job = new RestoreExtractedVideoPosterJob($video->id);

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);

        return $video->refresh();
    }

    /**
     * Delete the current custom poster (if any) and extract a fresh frame (sync).
     */
    public function execute(VideoMedia $video): VideoMedia
    {
        if (! $this->canRestore($video)) {
            $previous = is_string($video->poster_path) ? $video->poster_path : null;

            $video->forceFill([
                'poster_path' => null,
                'poster_is_custom' => false,
            ])->save();

            if ($previous !== null) {
                $this->deleteReplacedPoster->execute($video->refresh(), $previous);
            }

            return $video->refresh();
        }

        $previous = is_string($video->poster_path) ? $video->poster_path : null;

        $options = ThumbnailOptionsDto::fromConfig(
            customPosterPath: null,
            extractAtSeconds: $video->thumbnail_at_seconds,
        );

        $video = $this->extractThumbnail->execute($video, $options);

        // ExtractThumbnailAction writes a stable uuid/poster.jpg path. If the
        // previous custom upload used a different path, remove that orphan.
        if ($previous !== null && $previous !== $video->poster_path) {
            $this->deleteReplacedPoster->execute($video, $previous);
        }

        return $video->refresh();
    }
}
