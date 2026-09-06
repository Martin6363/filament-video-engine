<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Filament\Livewire;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Martin6363\FilamentVideoEngine\Concerns\HasVideoEngine;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager;
use Martin6363\FilamentVideoEngine\Services\VideoEngineManager;

/**
 * Livewire-powered HLS video player (Plyr + hls.js).
 */
final class VideoEnginePlayer extends Component
{
    public ?string $uuid = null;

    public ?string $posterUrl = null;

    public ?string $manifestUrl = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $qualities = [];

    public string $theme = 'filament';

    /**
     * Player mode: default (full playback) or preview (lazy, low-buffer admin watch).
     */
    public string $mode = 'default';

    /**
     * Mount from VideoMedia, a HasVideoEngine model, UUID, or options array.
     *
     * @param  VideoMedia|Model|string|array<string, mixed>|null  $video
     */
    public function mount(mixed $video = null, ?string $uuid = null, string $mode = 'default'): void
    {
        $this->theme = (string) config('filament-video-engine.player.theme', 'filament');
        $this->mode = in_array($mode, ['default', 'preview'], true) ? $mode : 'default';

        if (is_array($video)) {
            $this->uuid = $video['uuid'] ?? $uuid;
            $this->manifestUrl = $video['manifest_url'] ?? null;
            $this->posterUrl = $video['poster_url'] ?? null;
            $this->qualities = array_values($video['qualities'] ?? []);
            $this->mode = in_array(($video['mode'] ?? $this->mode), ['default', 'preview'], true)
                ? (string) ($video['mode'] ?? $this->mode)
                : $this->mode;

            return;
        }

        if ($video instanceof VideoMedia) {
            $this->hydrateFromMedia($video);

            return;
        }

        if ($video instanceof Model && $this->modelUsesHasVideoEngine($video)) {
            /** @var Model&object{getPrimaryVideoMedia(): ?VideoMedia} $video */
            $media = $video->getPrimaryVideoMedia();

            if ($media !== null) {
                $this->hydrateFromMedia($media->loadMissing('conversions'));
            }

            return;
        }

        $resolvedUuid = is_string($video) ? $video : $uuid;

        if ($resolvedUuid !== null && $resolvedUuid !== '') {
            $media = VideoMedia::query()->where('uuid', $resolvedUuid)->with('conversions')->first();

            if ($media !== null) {
                $this->hydrateFromMedia($media);
            }
        }
    }

    /**
     * Detect the HasVideoEngine trait on an Eloquent model.
     */
    private function modelUsesHasVideoEngine(Model $model): bool
    {
        return in_array(HasVideoEngine::class, class_uses_recursive($model), true);
    }

    /**
     * Populate player props from a VideoMedia record.
     */
    private function hydrateFromMedia(VideoMedia $media): void
    {
        $this->uuid = $media->uuid;
        $this->posterUrl = $media->posterPublicUrl() ?? $this->resolvePosterUrl($media);

        if (! $media->hasHlsStream()) {
            return;
        }

        $manifest = app(VideoEngineManager::class)->manifest($media);
        $this->manifestUrl = $manifest->masterPlaylistUrl;
        $this->posterUrl = $manifest->posterUrl ?? $this->posterUrl;
        $this->qualities = collect($manifest->qualities)
            ->map(static fn ($q) => $q->toArray())
            ->sortByDesc(static fn (array $q): int => (int) ($q['height'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * Public poster URL when available.
     */
    private function resolvePosterUrl(VideoMedia $media): ?string
    {
        if ($media->poster_path === null || $media->poster_path === '') {
            return null;
        }

        return app(VideoStorageManager::class)->publicUrl(
            (string) ($media->disk_output ?: config('filament-video-engine.disks.output', 'public')),
            $media->poster_path,
        );
    }

    /**
     * Render the player view.
     */
    public function render(): \Illuminate\Contracts\View\View
    {
        return view('filament-video-engine::livewire.player', [
            'theme' => $this->theme,
            'mode' => $this->mode,
            'speeds' => config('filament-video-engine.player.speeds', [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]),
            'enablePip' => (bool) config('filament-video-engine.player.pip', true),
            'enableKeyboard' => (bool) config('filament-video-engine.player.keyboard', true),
            'driver' => (string) config('filament-video-engine.player.driver', 'plyr'),
        ]);
    }
}
