<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Martin6363\FilamentVideoEngine\Actions\TranscodeVideoAction;
use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;

/**
 * Backward-compatible alias for {@see ProcessVideoTranscodingJob}.
 *
 * @deprecated Use ProcessVideoTranscodingJob directly.
 */
final class TranscodeVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 7200;

    public function __construct(
        public readonly int $videoMediaId,
        public readonly ?TranscodingOptionsDto $options = null,
    ) {}

    /**
     * Delegate to the canonical processing job handler.
     */
    public function handle(TranscodeVideoAction $action): void
    {
        (new ProcessVideoTranscodingJob($this->videoMediaId, $this->options))->handle($action);
    }
}
