{{-- Filament Video Engine — Livewire player shell --}}
@php
    $isPreview = ($mode ?? 'default') === 'preview';
    $preload = $isPreview ? 'none' : 'metadata';
@endphp
<div
    wire:ignore.self
    class="fi-ve-player-root{{ $isPreview ? ' fi-ve-player-root--preview' : '' }}"
>
    <div
        wire:ignore
        class="fi-ve-player theme-{{ $theme }}{{ $isPreview ? ' fi-ve-player--preview' : '' }}"
        data-ve-player
        data-ve-mode="{{ $isPreview ? 'preview' : 'default' }}"
        data-manifest-url="{{ $manifestUrl }}"
        data-poster-url="{{ $posterUrl }}"
        data-driver="{{ $driver }}"
        data-enable-pip="{{ $enablePip ? '1' : '0' }}"
        data-enable-keyboard="{{ $enableKeyboard ? '1' : '0' }}"
        data-qualities='@json($qualities)'
        data-speeds='@json($speeds)'
        data-auto-label="{{ __('filament-video-engine::messages.player.auto') }}"
        data-quality-label="{{ __('filament-video-engine::messages.player.quality') }}"
        data-speed-label="{{ __('filament-video-engine::messages.player.speed') }}"
        data-buffering-label="{{ __('filament-video-engine::messages.player.buffering') }}"
        data-plyr-css="https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.css"
        data-plyr-js="https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.polyfilled.min.js"
        data-hls-js="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js"
    >
        @if(filled($manifestUrl))
            <video
                class="fi-ve-player__video"
                playsinline
                webkit-playsinline
                preload="{{ $preload }}"
                @if(filled($posterUrl)) poster="{{ $posterUrl }}" @endif
                crossorigin="anonymous"
            ></video>
        @else
            <div class="fi-ve-player__empty">
                <p>{{ __('filament-video-engine::messages.player.not_ready') }}</p>
            </div>
        @endif
    </div>
</div>

@script
<script>
    const bootPlayer = () => {
        const root = $wire.$el?.querySelector?.('[data-ve-player]') ?? null

        if (! root || ! window.FilamentVideoEnginePlayer) {
            return
        }

        if (document.querySelector('[data-ve-script]') && ! window.Plyr) {
            document.querySelector('[data-ve-script]')?.addEventListener('load', () => {
                window.FilamentVideoEnginePlayer.boot(root)
            }, { once: true })

            return
        }

        window.FilamentVideoEnginePlayer.boot(root)
    }

    bootPlayer()
</script>
@endscript
