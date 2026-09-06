<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Martin6363\FilamentVideoEngine\Actions\DeleteVideoMediaFilesAction;
use Martin6363\FilamentVideoEngine\Actions\ProcessVideoEnginePickerUploadAction;
use Martin6363\FilamentVideoEngine\Contracts\TranscoderContract;
use Martin6363\FilamentVideoEngine\Contracts\VideoStorageContract;
use Martin6363\FilamentVideoEngine\Filament\Livewire\VideoEnginePlayer;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Observers\VideoMediaObserver;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegBinaryResolver;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegCommander;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegProcessRunner;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegProgressParser;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegTranscoder;
use Martin6363\FilamentVideoEngine\Services\Hls\HlsEncryptor;
use Martin6363\FilamentVideoEngine\Services\Hls\HlsPlaylistBuilder;
use Martin6363\FilamentVideoEngine\Services\Security\SignedUrlGenerator;
use Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TranscodingProgressTracker;
use Martin6363\FilamentVideoEngine\Services\VideoEngineManager;
use Martin6363\FilamentVideoEngine\Support\PackageAssets;
use Martin6363\FilamentVideoEngine\Support\QualityLadderPlanner;

/**
 * Registers configuration, routes, views, Livewire, and core bindings.
 */
final class FilamentVideoEngineServiceProvider extends ServiceProvider
{
    /**
     * Register package bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-video-engine.php',
            'filament-video-engine',
        );

        $this->app->singleton(FilamentVideoEnginePlugin::class);
        $this->app->singleton(FfmpegBinaryResolver::class);
        $this->app->singleton(FfmpegProgressParser::class);
        $this->app->singleton(FfmpegProcessRunner::class);
        $this->app->singleton(FfmpegCommander::class);
        $this->app->singleton(HlsPlaylistBuilder::class);
        $this->app->singleton(HlsEncryptor::class);
        $this->app->singleton(SignedUrlGenerator::class);
        $this->app->singleton(TranscodingProgressTracker::class);
        $this->app->singleton(VideoEngineManager::class);
        $this->app->singleton(\Martin6363\FilamentVideoEngine\Services\LicenseManager::class);
        $this->app->singleton(DeleteVideoMediaFilesAction::class);
        $this->app->singleton(QualityLadderPlanner::class);
        $this->app->singleton(ProcessVideoEnginePickerUploadAction::class);
        $this->app->singleton(\Martin6363\FilamentVideoEngine\Services\Watermark\WatermarkPathResolver::class);
        $this->app->singleton(\Martin6363\FilamentVideoEngine\Actions\SyncVideoWatermarkAction::class);
        $this->app->singleton(\Martin6363\FilamentVideoEngine\Actions\ReapplyWatermarkAction::class);

        $this->app->bind(VideoStorageContract::class, VideoStorageManager::class);
        $this->app->bind(TranscoderContract::class, FfmpegTranscoder::class);
    }

    /**
     * Bootstrap package resources.
     */
    public function boot(): void
    {
        if (! (bool) config('filament-video-engine.enabled', true)) {
            return;
        }

        VideoMedia::observe(VideoMediaObserver::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-video-engine');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-video-engine');

        $this->registerRoutes();
        $this->registerBladeComponents();
        $this->registerLivewireComponents();
        $this->registerPublishing();
        $this->ensurePublicAssetsLinked();
    }

    /**
     * Symlink / copy player assets into public/ when missing (local path packages).
     */
    private function ensurePublicAssetsLinked(): void
    {
        $targets = [
            'vendor/filament-video-engine/css/video-engine-player.css' => PackageAssets::packagePath('resources/css/video-engine-player.css'),
            'vendor/filament-video-engine/css/filament-video-engine-picker.css' => PackageAssets::packagePath('resources/css/filament-video-engine-picker.css'),
            'css/filament-video-engine/filament-video-engine-picker.css' => PackageAssets::packagePath('resources/css/filament-video-engine-picker.css'),
            'vendor/filament-video-engine/js/video-engine-player.js' => PackageAssets::packagePath('resources/js/video-engine-player.js'),
        ];

        foreach ($targets as $relative => $source) {
            if (! is_file($source)) {
                continue;
            }

            $destination = public_path($relative);
            $directory = dirname($destination);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            if (is_file($destination) && filemtime($destination) >= filemtime($source)) {
                continue;
            }

            copy($source, $destination);
        }
    }

    /**
     * Register API routes when enabled.
     */
    private function registerRoutes(): void
    {
        if (! (bool) config('filament-video-engine.api.enabled', true)) {
            return;
        }

        Route::middleware(config('filament-video-engine.api.middleware', ['api']))
            ->prefix((string) config('filament-video-engine.api.prefix', 'api/v1'))
            ->group(__DIR__.'/../routes/api.php');
    }

    /**
     * Register Blade aliases for the player component.
     */
    private function registerBladeComponents(): void
    {
        Blade::componentNamespace(
            'Martin6363\\FilamentVideoEngine\\View\\Components',
            'video-engine',
        );

        Blade::component('filament-video-engine::components.player', 'video-engine-player');
    }

    /**
     * Register Livewire components used by Filament forms and the player.
     */
    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('filament-video-engine.player', VideoEnginePlayer::class);
    }

    /**
     * Publish config, views, translations, and assets.
     */
    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/filament-video-engine.php' => config_path('filament-video-engine.php'),
        ], 'filament-video-engine-config');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/filament-video-engine'),
        ], 'filament-video-engine-translations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/filament-video-engine'),
        ], 'filament-video-engine-views');

        $this->publishes([
            __DIR__.'/../resources/css' => public_path('vendor/filament-video-engine/css'),
            __DIR__.'/../resources/js' => public_path('vendor/filament-video-engine/js'),
        ], 'filament-video-engine-assets');
    }
}
