{{-- Filament Video Engine — VideoEnginePicker (upload + live progress) --}}
@php
    /** @var \Martin6363\FilamentVideoEngine\Filament\Forms\Components\VideoEnginePicker $field */
    use Martin6363\FilamentVideoEngine\Support\PackageAssets;

    $progress = $field->getProgressPayload();
    $polling = (string) $field->getPollingInterval();
    $shouldPoll = $field->shouldPoll();
    $qualities = $field->getQualities();
    $posterUrl = $field->getPosterPreviewUrl();
    $uuid = $field->getBoundUuid() ?? 'none';
    $percent = min(100, max(0, (float) ($progress['progress_percent'] ?? 0)));
    $status = (string) ($progress['status'] ?? '');
    $video = $field->hasExistingVideo();
    $sourceFilename = $field->getSourceFilename();
    $sourceSize = $field->getSourceSizeLabel();
    $sourceQuality = $field->getSourceQualityLabel();

    $statusColor = match (true) {
        in_array($status, ['pending', 'queued'], true) => 'gray',
        ($progress['is_in_progress'] ?? false) === true => 'info',
        $status === 'completed' => 'success',
        $status === 'failed' => 'danger',
        $status === 'partial' => 'warning',
        default => 'gray',
    };
@endphp

@once('filament-video-engine-picker-styles')
    @php
        $pickerCssPath = PackageAssets::packagePath('resources/css/filament-video-engine-picker.css');
    @endphp

    @if (is_file($pickerCssPath))
        <style data-ve-picker-style>{!! file_get_contents($pickerCssPath) !!}</style>
    @endif
@endonce

<x-dynamic-component
    :component="$field->getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:key="video-engine-picker-{{ $field->getStatePath() }}-{{ $uuid }}"
        @if($shouldPoll)
            wire:poll.{{ $polling }}
        @endif
        x-data="{
            percent: {{ \Illuminate\Support\Js::from($percent) }},
            status: {{ \Illuminate\Support\Js::from($status) }},
            syncFromDom() {
                const bar = this.$refs.bar
                if (! bar) return
                bar.style.width = `${this.percent}%`
            }
        }"
        x-init="syncFromDom()"
        class="fi-fo-video-engine-picker{{ $field->isLicensed() ? '' : ' fi-fo-video-engine-picker--unlicensed' }}"
    >
        @unless($field->isLicensed())
            <div
                class="fi-ve-picker-license-warning"
                role="alert"
            >
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="fi-ve-picker-license-warning__icon"
                />
                <p>
                    {{ __('filament-video-engine::messages.license.invalid_banner') }}
                </p>
            </div>
        @else
        @if(! $video)
            <x-filament::section
                compact
                contained
                :heading="__('filament-video-engine::messages.picker.no_video')"
                :description="__('filament-video-engine::messages.picker.no_video_description')"
                icon="heroicon-o-video-camera"
                icon-color="primary"
            >
                <x-filament::fieldset
                    :label="__('filament-video-engine::messages.picker.qualities_ladder')"
                    contained
                >
                    <div class="fi-ve-picker-ladder__badges">
                        @foreach($qualities as $quality)
                            <x-filament::badge color="gray" size="sm">
                                {{ $quality }}
                            </x-filament::badge>
                        @endforeach
                    </div>
                </x-filament::fieldset>
            </x-filament::section>
        @else
            @if(filled($posterUrl))
                <x-filament::section
                    compact
                    contained
                    :heading="__('filament-video-engine::messages.picker.poster_preview')"
                    icon="heroicon-o-photo"
                    icon-color="primary"
                >
                    <div class="fi-ve-picker-poster">
                        <img
                            src="{{ $posterUrl }}"
                            alt="{{ __('filament-video-engine::messages.picker.poster_preview') }}"
                            loading="lazy"
                        />
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section
                compact
                contained
                :heading="__('filament-video-engine::messages.picker.attached_media')"
                icon="heroicon-o-film"
                icon-color="gray"
                divided
            >
                @if(filled($sourceFilename))
                    @component('filament-video-engine::filament.forms.components.partials.video-engine-picker-row', [
                        'label' => __('filament-video-engine::messages.picker.attached_source'),
                    ])
                        <div class="fi-ve-picker-inline-badges">
                            <span class="fi-ve-picker-filename">{{ $sourceFilename }}</span>

                            @if(filled($sourceQuality))
                                <x-filament::badge color="gray" size="sm">
                                    {{ $sourceQuality }}
                                </x-filament::badge>
                            @endif

                            @if(filled($sourceSize))
                                <x-filament::badge color="gray" size="sm">
                                    {{ $sourceSize }}
                                </x-filament::badge>
                            @endif
                        </div>
                    @endcomponent
                @endif

                @if($progress)
                    @component('filament-video-engine::filament.forms.components.partials.video-engine-picker-row', [
                        'label' => __('filament-video-engine::messages.picker.status'),
                    ])
                        <x-filament::badge :color="$statusColor" size="sm">
                            {{ $status !== '' ? __('filament-video-engine::messages.status.'.$status) : '—' }}
                        </x-filament::badge>
                    @endcomponent

                    @component('filament-video-engine::filament.forms.components.partials.video-engine-picker-row', [
                        'label' => __('filament-video-engine::messages.picker.progress'),
                    ])
                        <div class="fi-ve-picker-progress-stack">
                            <div
                                class="fi-ve-picker-progress"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ (int) $percent }}"
                            >
                                <div
                                    x-ref="bar"
                                    class="fi-ve-picker-progress__bar"
                                    style="width: {{ $percent }}%"
                                ></div>
                            </div>

                            <div class="fi-ve-picker-meta">
                                <span class="fi-ve-picker-meta__percent">
                                    {{ number_format($percent, 1) }}%
                                </span>

                                @if(! empty($progress['current_step']))
                                    <span>{{ $progress['current_step'] }}</span>
                                @endif

                                @if(! empty($progress['queue_status']))
                                    <span>
                                        {{ __('filament-video-engine::messages.picker.queue') }}:
                                        {{ $progress['queue_status'] }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endcomponent
                @endif

                @component('filament-video-engine::filament.forms.components.partials.video-engine-picker-row', [
                    'label' => __('filament-video-engine::messages.picker.media_id'),
                ])
                    <code class="fi-ve-picker-code">{{ $uuid }}</code>
                @endcomponent

                @if(! empty($progress['errors']))
                    <x-filament::callout
                        color="danger"
                        icon="heroicon-o-exclamation-triangle"
                        :heading="__('filament-video-engine::messages.picker.errors')"
                    >
                        <x-slot name="footer">
                            <ul class="list-disc space-y-1 ps-4 text-sm">
                                @foreach($progress['errors'] as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-slot>
                    </x-filament::callout>
                @endif
            </x-filament::section>

            <x-filament::section
                compact
                contained
                :heading="__('filament-video-engine::messages.picker.qualities_ladder')"
                icon="heroicon-m-signal"
                icon-color="gray"
            >
                <div class="fi-ve-picker-ladder__badges">
                    @foreach($qualities as $quality)
                        <x-filament::badge color="gray" size="sm">
                            {{ $quality }}
                        </x-filament::badge>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
        @endunless
    </div>
</x-dynamic-component>
