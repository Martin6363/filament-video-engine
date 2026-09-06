<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Contracts;

use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Contract for video transcoder implementations (FFmpeg by default).
 */
interface TranscoderContract
{
    /**
     * Transcode a VideoMedia record into configured HLS qualities and poster.
     */
    public function transcode(VideoMedia $video, TranscodingOptionsDto $options): VideoMedia;

    /**
     * Whether the underlying binary stack is available.
     */
    public function isAvailable(): bool;
}
