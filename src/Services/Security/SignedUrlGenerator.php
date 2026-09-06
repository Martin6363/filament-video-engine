<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Security;

use Illuminate\Support\Facades\URL;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Generates temporary signed URLs for private / paid video API endpoints.
 */
final class SignedUrlGenerator
{
    /**
     * Signed URL for the master manifest API endpoint.
     */
    public function manifestUrl(VideoMedia $video): string
    {
        return URL::temporarySignedRoute(
            'filament-video-engine.api.manifest',
            now()->addMinutes($this->ttlMinutes()),
            ['uuid' => $video->uuid],
        );
    }

    /**
     * Signed URL for a specific quality stream API endpoint.
     */
    public function qualityUrl(VideoMedia $video, string $quality): string
    {
        return URL::temporarySignedRoute(
            'filament-video-engine.api.quality',
            now()->addMinutes($this->ttlMinutes()),
            ['uuid' => $video->uuid, 'quality' => $quality],
        );
    }

    /**
     * Resolve signed URL TTL from security config (with legacy auth fallback).
     */
    private function ttlMinutes(): int
    {
        return (int) (
            config('filament-video-engine.api.security.signed_ttl_minutes')
            ?? config('filament-video-engine.api.auth.signed_ttl_minutes', 60)
        );
    }
}
