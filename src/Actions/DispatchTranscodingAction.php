<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Jobs\ProcessVideoTranscodingJob;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Support\LicenseGate;

/**
 * Queue a full transcoding pipeline for a VideoMedia record.
 */
final class DispatchTranscodingAction
{
    /**
     * Mark the video as queued and dispatch {@see ProcessVideoTranscodingJob}.
     */
    public function execute(VideoMedia $video, ?TranscodingOptionsDto $options = null): VideoMedia
    {
        if (! LicenseGate::allowOrFail($video)) {
            return $video->refresh();
        }

        $video->update([
            'status' => TranscodingStatusEnum::Queued,
            'current_step' => 'queued',
            'error_message' => null,
            'progress_percent' => 0,
        ]);

        $connection = config('filament-video-engine.queue.connection');
        $queue = config('filament-video-engine.queue.queue', 'video-engine');

        $job = new ProcessVideoTranscodingJob($video->id, $options);

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);

        return $video->refresh();
    }
}
