<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Martin6363\FilamentVideoEngine\Actions\RestoreExtractedVideoPosterAction;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Support\LicenseGate;
use Throwable;

/**
 * Re-extract a poster frame on the video-engine queue after a custom poster is cleared.
 */
final class RestoreExtractedVideoPosterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $videoMediaId,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function uniqueId(): string
    {
        return 'restore-poster-'.$this->videoMediaId;
    }

    /**
     * Handle the job.
     */
    public function handle(RestoreExtractedVideoPosterAction $action): void
    {
        $video = VideoMedia::query()->find($this->videoMediaId);

        if ($video === null || $video->trashed()) {
            return;
        }

        if (! LicenseGate::allowOrFail($video)) {
            return;
        }

        try {
            $action->execute($video);

            $video->refresh()->forceFill([
                'current_step' => null,
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $video->refresh()->forceFill([
                'current_step' => 'poster:restore_failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            Log::error('RestoreExtractedVideoPosterJob failed.', [
                'video_media_id' => $this->videoMediaId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
