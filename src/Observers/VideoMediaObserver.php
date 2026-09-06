<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Observers;

use Martin6363\FilamentVideoEngine\Actions\DeleteReplacedVideoPosterAction;
use Martin6363\FilamentVideoEngine\Actions\DeleteVideoMediaFilesAction;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Cleans storage artifacts for VideoMedia lifecycle events.
 */
final class VideoMediaObserver
{
    public function __construct(
        private readonly DeleteVideoMediaFilesAction $deleteFiles,
        private readonly DeleteReplacedVideoPosterAction $deleteReplacedPoster,
    ) {}

    /**
     * Delete the previous poster file after a successful poster_path change.
     *
     * Uses getOriginal() during the updated event (before syncOriginal in
     * finishSave). Avoids observer instance state — Laravel resolves a fresh
     * observer per event listener invocation.
     */
    public function updated(VideoMedia $video): void
    {
        if (! $video->wasChanged('poster_path')) {
            return;
        }

        $previous = $video->getOriginal('poster_path');

        if (! is_string($previous) || $previous === '') {
            return;
        }

        $this->deleteReplacedPoster->execute($video, $previous);
    }

    /**
     * Force delete — remove originals, posters, HLS, and keys from disks.
     *
     * Soft deletes intentionally leave storage intact so Restore can bring
     * the stream back.
     */
    public function forceDeleting(VideoMedia $video): void
    {
        if (! (bool) config('filament-video-engine.cleanup.delete_files_on_force_delete', true)) {
            return;
        }

        $this->deleteFiles->execute($video);
    }
}
