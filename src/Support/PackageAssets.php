<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Support;

/**
 * Resolves published or package-local player assets for Blade rendering.
 */
final class PackageAssets
{
    /**
     * Absolute path to the package root.
     */
    public static function packagePath(string $relative = ''): string
    {
        $base = dirname(__DIR__, 2);

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
    }

    /**
     * Public URL for a published asset, or null when unpublished.
     */
    public static function publishedUrl(string $relativePublicPath): ?string
    {
        $absolute = public_path($relativePublicPath);

        return is_file($absolute) ? asset($relativePublicPath) : null;
    }

    /**
     * Stylesheet href or null when falling back to inline CSS.
     */
    public static function playerCssHref(): ?string
    {
        return self::publishedUrl('vendor/filament-video-engine/css/video-engine-player.css');
    }

    /**
     * Script src or null when falling back to inline JS.
     */
    public static function playerJsSrc(): ?string
    {
        return self::publishedUrl('vendor/filament-video-engine/js/video-engine-player.js');
    }

    /**
     * Raw CSS for inline fallback.
     */
    public static function playerCssContents(): string
    {
        $path = self::packagePath('resources/css/video-engine-player.css');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Raw JS for inline fallback.
     */
    public static function playerJsContents(): string
    {
        $path = self::packagePath('resources/js/video-engine-player.js');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
