<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource;
use Martin6363\FilamentVideoEngine\Support\PackageAssets;
use Martin6363\FilamentVideoEngine\Support\PackageVersion;

/**
 * Filament panel plugin for Filament Video Engine.
 */
final class FilamentVideoEnginePlugin implements Plugin
{
    public const ID = 'filament-video-engine';

    private ?bool $registerResource = null;

    /**
     * Resolve the plugin from the container for fluent panel registration.
     */
    public static function make(): static
    {
        return app(self::class);
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * Toggle registration of the built-in VideoMedia resource.
     */
    public function registerResource(bool $enabled = true): static
    {
        $this->registerResource = $enabled;

        return $this;
    }

    /**
     * Whether the VideoMedia Filament resource should be registered.
     */
    public function shouldRegisterResource(): bool
    {
        return $this->registerResource ?? (bool) config(
            'filament-video-engine.filament.register_resource',
            true,
        );
    }

    /**
     * Return the installed package version for UI/debug surfaces.
     */
    public function version(): string
    {
        return PackageVersion::current();
    }

    /**
     * Register panel-specific plugin services and resources.
     */
    public function register(Panel $panel): void
    {
        FilamentAsset::register([
            Css::make(
                'filament-video-engine-picker',
                PackageAssets::packagePath('resources/css/filament-video-engine-picker.css'),
            )->relativePublicPath('vendor/filament-video-engine/css/filament-video-engine-picker.css'),
        ], self::ID);

        if ($this->shouldRegisterResource()) {
            $panel->resources([
                VideoMediaResource::class,
            ]);
        }
    }

    /**
     * Boot panel-specific plugin behavior.
     */
    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * Apply a configuration callback and return the plugin.
     *
     * @param  Closure(self): void  $callback
     */
    public function configureUsing(Closure $callback): static
    {
        $callback($this);

        return $this;
    }
}
