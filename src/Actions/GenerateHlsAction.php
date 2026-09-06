<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Martin6363\FilamentVideoEngine\DTOs\HlsOptionsDto;
use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Explicit action entry-point for HLS generation (wraps full transcode).
 */
final class GenerateHlsAction
{
    public function __construct(
        private readonly TranscodeVideoAction $transcode,
    ) {}

    /**
     * Generate HLS master + renditions for the given video.
     */
    public function execute(VideoMedia $video, ?HlsOptionsDto $hls = null): VideoMedia
    {
        $options = TranscodingOptionsDto::fromConfig(
            qualities: $hls?->qualities,
        );

        if ($hls !== null) {
            $options = new TranscodingOptionsDto(
                qualities: $hls->qualities !== [] ? $hls->qualities : $options->qualities,
                thumbnail: $options->thumbnail,
                watermark: $options->watermark,
                hls: $hls,
                skipUpscale: $options->skipUpscale,
            );
        }

        return $this->transcode->execute($video, $options);
    }
}
