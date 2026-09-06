{{-- Admin HLS preview host (View page / list modal) --}}
@php
    use Martin6363\FilamentVideoEngine\Models\VideoMedia;
    use Martin6363\FilamentVideoEngine\Support\PackageAssets;

    /** @var VideoMedia|null $video */
    $video = $video ?? ($record instanceof VideoMedia ? $record : null);
    $mode = ($mode ?? 'preview') === 'default' ? 'default' : 'preview';
    $ready = $video instanceof VideoMedia && $video->hasHlsStream();
    $key = $video instanceof VideoMedia ? (string) $video->getKey() : 'none';

    $cssHref = PackageAssets::playerCssHref();
    $jsSrc = PackageAssets::playerJsSrc();
@endphp

@once('filament-video-engine-admin-player-assets')
    @if($cssHref)
        <link rel="stylesheet" href="{{ $cssHref }}" data-ve-style>
    @else
        <style data-ve-style>{!! PackageAssets::playerCssContents() !!}</style>
    @endif

    @if($jsSrc)
        <script src="{{ $jsSrc }}" data-ve-script defer></script>
    @else
        <script data-ve-script>{!! PackageAssets::playerJsContents() !!}</script>
    @endif
@endonce

<div class="fi-ve-admin-preview">
    @if($ready)
        <p class="fi-ve-admin-preview__hint">
            {{ __('filament-video-engine::messages.player.preview_hint') }}
        </p>

        @livewire(
            'filament-video-engine.player',
            ['video' => $video, 'mode' => $mode],
            key('ve-admin-preview-'.$key.'-'.$mode)
        )
    @else
        <div class="fi-ve-admin-preview__empty">
            @if($video instanceof VideoMedia && filled($video->posterPublicUrl()))
                <img
                    src="{{ $video->posterPublicUrl() }}"
                    alt="{{ $video->title ?? $video->uuid }}"
                    class="fi-ve-admin-preview__poster"
                    loading="lazy"
                />
            @endif

            <p>{{ __('filament-video-engine::messages.player.not_ready') }}</p>
        </div>
    @endif
</div>
