<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Re-queue a failed or partial video for another transcoding attempt.
 */
final class RetryFailedConversionAction
{
    public function __construct(
        private readonly DispatchTranscodingAction $dispatch,
    ) {}

    /**
     * Reset error state and dispatch again when retryable.
     */
    public function execute(VideoMedia $video, ?TranscodingOptionsDto $options = null): VideoMedia
    {
        if (! $video->status->canRetry() && $video->status !== TranscodingStatusEnum::Pending) {
            return $video;
        }

        $video->conversions()->update([
            'status' => TranscodingStatusEnum::Pending,
            'error_message' => null,
            'progress_percent' => 0,
        ]);

        return $this->dispatch->execute($video, $options);
    }
}
