<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Illuminate\Support\Facades\Log;
use Martin6363\FilamentVideoEngine\Contracts\TranscoderContract;
use Martin6363\FilamentVideoEngine\DTOs\ThumbnailOptionsDto;
use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;
use Martin6363\FilamentVideoEngine\DTOs\WatermarkOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Throwable;

/**
 * Orchestrates a full synchronous transcoding run for a VideoMedia record.
 */
final class TranscodeVideoAction
{
    public function __construct(
        private readonly TranscoderContract $transcoder,
    ) {}

    /**
     * Execute transcoding with options (defaults from config + video metadata).
     *
     * @param  list<VideoQualityEnum|string>|null  $qualities
     */
    public function execute(
        VideoMedia $video,
        ?TranscodingOptionsDto $options = null,
        ?array $qualities = null,
    ): VideoMedia {
        $resolved = $options ?? $this->defaultOptions($video, $qualities);

        try {
            return $this->transcoder->transcode($video, $resolved);
        } catch (Throwable $exception) {
            Log::error('TranscodeVideoAction failed.', [
                'video_media_id' => $video->id,
                'uuid' => $video->uuid,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Build default pipeline options from config and the video row.
     *
     * @param  list<VideoQualityEnum|string>|null  $qualities
     */
    public function defaultOptions(VideoMedia $video, ?array $qualities = null): TranscodingOptionsDto
    {
        $resolvedQualities = $this->normalizeQualities($qualities);

        $customPoster = null;

        if ($video->poster_is_custom && $video->poster_path !== null && $video->poster_path !== '') {
            $customPoster = $video->poster_path;
        }

        return TranscodingOptionsDto::fromConfig(
            qualities: $resolvedQualities,
            thumbnail: ThumbnailOptionsDto::fromConfig(
                customPosterPath: $customPoster,
                extractAtSeconds: $video->thumbnail_at_seconds,
            ),
            watermark: $video->watermarkOptions(),
        );
    }

    /**
     * @param  list<VideoQualityEnum|string>|null  $qualities
     * @return list<VideoQualityEnum>|null
     */
    private function normalizeQualities(?array $qualities): ?array
    {
        if ($qualities === null) {
            return null;
        }

        $normalized = [];

        foreach ($qualities as $quality) {
            $normalized[] = $quality instanceof VideoQualityEnum
                ? $quality
                : VideoQualityEnum::fromLabel((string) $quality);
        }

        return $normalized;
    }
}
