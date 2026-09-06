<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Security\SignedUrlGenerator;
use Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager;
use Martin6363\FilamentVideoEngine\Services\VideoEngineManager;

/**
 * Attach Filament Video Engine media to any Eloquent model.
 *
 * @phpstan-ignore-next-line trait.unused
 */
trait HasVideoEngine
{
    /**
     * All videos attached to this model.
     *
     * @return MorphMany<VideoMedia, $this>
     */
    public function videoMedia(): MorphMany
    {
        return $this->morphMany(VideoMedia::class, 'videoable')->latest('id');
    }

    /**
     * Primary / latest video attachment.
     *
     * @return MorphOne<VideoMedia, $this>
     */
    public function primaryVideo(): MorphOne
    {
        return $this->morphOne(VideoMedia::class, 'videoable')->latestOfMany();
    }

    /**
     * Resolve the primary VideoMedia instance.
     */
    public function getPrimaryVideoMedia(): ?VideoMedia
    {
        return $this->relationLoaded('primaryVideo')
            ? $this->getRelation('primaryVideo')
            : $this->primaryVideo()->first();
    }

    /**
     * Absolute or signed master HLS playlist URL.
     */
    public function getVideoManifestUrl(bool $signed = true): ?string
    {
        $video = $this->getPrimaryVideoMedia();

        if ($video === null || ! $video->hasHlsStream()) {
            return null;
        }

        if ($signed && (bool) (
            config('filament-video-engine.api.security.enabled')
            ?? ((string) config('filament-video-engine.api.auth.driver') === 'signed')
        ) && (string) (
            config('filament-video-engine.api.security.driver')
            ?? config('filament-video-engine.api.auth.driver')
        ) === 'signed') {
            return app(SignedUrlGenerator::class)->manifestUrl($video);
        }

        return app(VideoEngineManager::class)->manifestApiUrl($video);
    }

    /**
     * Direct quality stream / playlist URL.
     */
    public function getVideoQuality(VideoQualityEnum|string $quality, bool $signed = true): ?string
    {
        $video = $this->getPrimaryVideoMedia();

        if ($video === null) {
            return null;
        }

        $label = $quality instanceof VideoQualityEnum ? $quality->value : $quality;
        $conversion = $video->conversionForQuality($label);

        if ($conversion === null || $conversion->playlist_path === null) {
            return null;
        }

        if ($signed && (bool) (
            config('filament-video-engine.api.security.enabled')
            ?? ((string) config('filament-video-engine.api.auth.driver') === 'signed')
        ) && (string) (
            config('filament-video-engine.api.security.driver')
            ?? config('filament-video-engine.api.auth.driver')
        ) === 'signed') {
            return app(SignedUrlGenerator::class)->qualityUrl($video, $label);
        }

        return app(VideoStorageManager::class)->publicUrl(
            $conversion->disk ?? (string) $video->disk_output,
            $conversion->playlist_path,
        );
    }

    /**
     * Poster / thumbnail URL (custom upload or extracted frame).
     */
    public function getPosterUrl(): ?string
    {
        $video = $this->getPrimaryVideoMedia();

        if ($video === null || $video->poster_path === null || $video->poster_path === '') {
            return null;
        }

        return app(VideoStorageManager::class)->publicUrl(
            (string) $video->disk_output,
            $video->poster_path,
        );
    }

    /**
     * Whether the primary video has a ready HLS master playlist.
     */
    public function hasHlsStream(): bool
    {
        $video = $this->getPrimaryVideoMedia();

        return $video !== null && $video->hasHlsStream();
    }
}
