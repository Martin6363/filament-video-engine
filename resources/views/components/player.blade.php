{{-- Blade alias: <x-video-engine-player :video="$movie" /> --}}
@props([
    'video' => null,
    'uuid' => null,
    'mode' => 'default',
])

@php
    use Martin6363\FilamentVideoEngine\Support\PackageAssets;

    $mount = $video ?? $uuid;
    $keySeed = match (true) {
        is_object($mount) && method_exists($mount, 'getKey') => (string) $mount->getKey(),
        is_string($mount) => $mount,
        default => md5(serialize($mount)),
    };

    $cssHref = PackageAssets::playerCssHref();
    $jsSrc = PackageAssets::playerJsSrc();
@endphp

@once('filament-video-engine-player-assets')
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

<div {{ $attributes->class(['fi-video-engine-player-host']) }}>
    @livewire('filament-video-engine.player', ['video' => $mount, 'mode' => $mode], key('ve-player-'.$keySeed.'-'.$mode))
</div>
