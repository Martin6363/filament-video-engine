<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Martin6363\FilamentVideoEngine\Actions\TranscodeVideoAction;
use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Support\LicenseGate;
use Throwable;

/**
 * Queue worker entry-point for the full FFmpeg → HLS pipeline.
 */
final class ProcessVideoTranscodingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 7200;

    /**
     * @param  array<string, mixed>|null  $optionsPayload  Serializable options bag (preferred over DTO in queues).
     */
    public function __construct(
        public readonly int $videoMediaId,
        public readonly ?TranscodingOptionsDto $options = null,
        public readonly ?array $optionsPayload = null,
    ) {}

    /**
     * Handle the job.
     */
    public function handle(TranscodeVideoAction $action): void
    {
        $video = VideoMedia::query()->find($this->videoMediaId);

        if ($video === null) {
            return;
        }

        if (! LicenseGate::allowOrFail($video)) {
            return;
        }

        $options = $this->options ?? $this->hydrateOptions($this->optionsPayload);

        try {
            $action->execute($video, $options);
        } catch (Throwable $exception) {
            // Transcoder already marks Failed + cleans temp files; ensure status stuck if DB write raced.
            if ($video->fresh()?->status !== TranscodingStatusEnum::Failed) {
                $video->forceFill([
                    'status' => TranscodingStatusEnum::Failed,
                    'error_message' => $exception->getMessage(),
                    'current_step' => 'failed',
                ])->save();
            }

            Log::error('ProcessVideoTranscodingJob failed.', [
                'video_media_id' => $this->videoMediaId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Tags for Horizon / queue monitoring.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'filament-video-engine',
            'video:'.$this->videoMediaId,
        ];
    }

    /**
     * Rebuild a DTO from a plain array payload when needed.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function hydrateOptions(?array $payload): ?TranscodingOptionsDto
    {
        if ($payload === null) {
            return null;
        }

        // Reserved for future explicit serialization; DTO instance is used today.
        return null;
    }
}
