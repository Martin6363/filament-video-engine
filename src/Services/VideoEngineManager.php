<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services;

use Martin6363\FilamentVideoEngine\DTOs\QualityStreamDto;
use Martin6363\FilamentVideoEngine\DTOs\TranscodingProgressDto;
use Martin6363\FilamentVideoEngine\DTOs\VideoManifestDto;
use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Exceptions\VideoNotReadyException;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager;

/**
 * High-level facade for manifests, progress, and URL helpers.
 */
final class VideoEngineManager
{
    public function __construct(
        private readonly VideoStorageManager $storage,
    ) {}

    /**
     * Build a VideoManifestDto for API / player consumption.
     */
    public function manifest(VideoMedia $video, bool $requireReady = true): VideoManifestDto
    {
        if ($requireReady && ! $video->hasHlsStream()) {
            throw VideoNotReadyException::forUuid($video->uuid, $video->status->value);
        }

        $qualities = [];

        foreach ($video->conversions as $conversion) {
            if ($conversion->type !== ConversionTypeEnum::HlsRendition || $conversion->playlist_path === null) {
                continue;
            }

            $quality = $conversion->qualityEnum();

            if ($quality === null) {
                continue;
            }

            $qualities[] = new QualityStreamDto(
                quality: $quality,
                playlistUrl: $this->storage->publicUrl(
                    $conversion->disk ?? (string) $video->disk_output,
                    $conversion->playlist_path,
                ),
                bandwidth: (int) ($conversion->bandwidth ?? $quality->bandwidth()),
                width: (int) ($conversion->width ?? $quality->maxWidth()),
                height: (int) ($conversion->height ?? $quality->height()),
            );
        }

        $masterUrl = $video->master_playlist_path !== null
            ? $this->storage->publicUrl((string) $video->disk_output, $video->master_playlist_path)
            : '';

        $posterUrl = $video->poster_path !== null
            ? $this->storage->publicUrl((string) $video->disk_output, $video->poster_path)
            : null;

        return new VideoManifestDto(
            uuid: $video->uuid,
            masterPlaylistUrl: $masterUrl,
            status: $video->status,
            qualities: $qualities,
            posterUrl: $posterUrl,
            durationSeconds: $video->duration_seconds,
            sourceResolution: $video->sourceResolutionLabel(),
            skippedQualities: $video->skippedQualities(),
            meta: $video->meta ?? [],
        );
    }

    /**
     * Resolve a single quality stream DTO.
     */
    public function qualityStream(VideoMedia $video, VideoQualityEnum|string $quality): QualityStreamDto
    {
        $enum = $quality instanceof VideoQualityEnum ? $quality : VideoQualityEnum::fromLabel($quality);
        $conversion = $video->conversionForQuality($enum);

        if ($conversion === null || $conversion->playlist_path === null) {
            throw VideoNotReadyException::forUuid($video->uuid, 'missing:'.$enum->value);
        }

        return new QualityStreamDto(
            quality: $enum,
            playlistUrl: $this->storage->publicUrl(
                $conversion->disk ?? (string) $video->disk_output,
                $conversion->playlist_path,
            ),
            bandwidth: (int) ($conversion->bandwidth ?? $enum->bandwidth()),
            width: (int) ($conversion->width ?? $enum->maxWidth()),
            height: (int) ($conversion->height ?? $enum->height()),
        );
    }

    /**
     * Live polling progress payload.
     */
    public function progress(VideoMedia $video): TranscodingProgressDto
    {
        $errors = [];

        if ($video->error_message !== null && $video->error_message !== '') {
            $errors[] = $video->error_message;
        }

        foreach ($video->conversions as $conversion) {
            if ($conversion->error_message) {
                $errors[] = sprintf('%s: %s', $conversion->quality ?? $conversion->type->value, $conversion->error_message);
            }
        }

        return new TranscodingProgressDto(
            uuid: $video->uuid,
            status: $video->status,
            progressPercent: (float) $video->progress_percent,
            currentStep: $video->current_step,
            queueStatus: $video->status->value,
            errors: $errors,
        );
    }

    /**
     * Unsigned API URL for the manifest endpoint.
     */
    public function manifestApiUrl(VideoMedia $video): string
    {
        return url(sprintf(
            '%s/videos/%s/manifest',
            trim((string) config('filament-video-engine.api.prefix', 'api/v1'), '/'),
            $video->uuid,
        ));
    }
}
