<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Martin6363\FilamentVideoEngine\Actions\ProcessVideoEnginePickerUploadAction;
use Martin6363\FilamentVideoEngine\Actions\ReapplyWatermarkAction;
use Martin6363\FilamentVideoEngine\Actions\SyncVideoWatermarkAction;
use Martin6363\FilamentVideoEngine\Concerns\HasVideoEngine;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Enums\WatermarkPositionEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\LicenseManager;
use Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager;
use Martin6363\FilamentVideoEngine\Services\VideoEngineManager;
use Martin6363\FilamentVideoEngine\Support\VideoTimestampFormatter;

/**
 * All-in-one Filament field: upload source video, optional poster, live progress.
 *
 * State is the VideoMedia UUID (string|null). Uploads are processed automatically
 * on form save when the record exists (create/edit resource pages).
 */
class VideoEnginePicker extends Field
{
    protected string $view = 'filament-video-engine::filament.forms.components.video-engine-picker';

    /**
     * @var list<string>|Closure|null
     */
    protected array|Closure|null $qualities = null;

    protected bool|Closure $showPosterControls = true;

    protected bool|Closure $showWatermarkControls = false;

    /**
     * @var list<string|null>|Closure|null
     */
    protected array|Closure|null $watermarkEditorAspectRatios = null;

    protected string|Closure|null $pollingInterval = null;

    protected Closure|null $titleFromRecord = null;

    /**
     * @var list<string>
     */
    private const UPLOAD_SUFFIX = '__upload';

    private const POSTER_SUFFIX = '__poster';

    private const THUMBNAIL_SUFFIX = '__thumbnail_at';

    private const WATERMARK_ENABLED_SUFFIX = '__watermark_enabled';

    private const WATERMARK_IMAGE_SUFFIX = '__watermark_image';

    private const WATERMARK_POSITION_SUFFIX = '__watermark_position';

    private const WATERMARK_OPACITY_SUFFIX = '__watermark_opacity';

    private const WATERMARK_MARGIN_SUFFIX = '__watermark_margin';

    private const WATERMARK_SCALE_SUFFIX = '__watermark_scale';

    private const UUID_STATE_KEY = 'uuid';

    /**
     * Cached Livewire upload state captured before Filament dehydrates temp files.
     *
     * @var array<string, mixed>
     */
    private array $pendingUploadCache = [];

    /**
     * Configure upload sub-fields, hydration, and save processing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrated(false);
        $this->saved();
        $this->columnSpanFull();
        $this->extraFieldWrapperAttributes([
            'class' => 'fi-fo-video-engine-picker-field',
        ]);

        $this->registerUploadSchema();
        $this->registerRelationshipHooks();
    }

    /**
     * Keep upload controls in a full-width stacked layout.
     *
     * Filament marks aboveContent schemas as inline flex by default, which
     * prevents FileUpload / TimePicker from stretching to the section width.
     */
    protected function configureChildSchema(Schema $schema, string $key): Schema
    {
        $schema = parent::configureChildSchema($schema, $key);

        if ($key === static::ABOVE_CONTENT_SCHEMA_KEY) {
            $schema
                ->inline(false)
                ->columns(1)
                ->extraAttributes([
                    'class' => 'fi-ve-picker-controls',
                ]);
        }

        return $schema;
    }

    /**
     * Restrict which qualities will be requested on dispatch.
     *
     * @param  list<string>|Closure  $qualities
     */
    public function qualities(array|Closure $qualities): static
    {
        $this->qualities = $qualities;

        return $this;
    }

    /**
     * Toggle custom poster upload + timestamp extraction controls.
     */
    public function posterControls(bool|Closure $condition = true): static
    {
        $this->showPosterControls = $condition;

        return $this;
    }

    /**
     * Toggle per-video watermark upload and overlay settings.
     *
     * @param  list<string|null>|Closure|null  $editorAspectRatios  Optional crop ratios for the image editor (null = free crop).
     */
    public function watermarkControls(
        bool|Closure $condition = true,
        array|Closure|null $editorAspectRatios = null,
    ): static {
        $this->showWatermarkControls = $condition;
        $this->watermarkEditorAspectRatios = $editorAspectRatios;

        return $this;
    }

    /**
     * Livewire polling interval for status refresh (e.g. "3s").
     */
    public function pollingInterval(string|Closure|null $interval): static
    {
        $this->pollingInterval = $interval;

        return $this;
    }

    /**
     * Resolve the VideoMedia title from the owning record on upload.
     */
    public function titleFromRecord(Closure|null $callback): static
    {
        $this->titleFromRecord = $callback;

        return $this;
    }

    /**
     * Resolved qualities for the view / dispatch payload.
     *
     * @return list<string>
     */
    public function getQualities(): array
    {
        $resolved = $this->evaluate($this->qualities);

        if (is_array($resolved) && $resolved !== []) {
            return array_values(array_map(static fn (mixed $q): string => (string) $q, $resolved));
        }

        return array_map(
            static fn (VideoQualityEnum $q): string => $q->value,
            VideoQualityEnum::defaults(),
        );
    }

    /**
     * Whether poster controls should render.
     */
    public function shouldShowPosterControls(): bool
    {
        return (bool) $this->evaluate($this->showPosterControls);
    }

    /**
     * Whether the Lemon Squeezy license is valid for this install.
     */
    public function isLicensed(): bool
    {
        return LicenseManager::isLicensed();
    }

    /**
     * Whether watermark controls should render.
     */
    public function shouldShowWatermarkControls(): bool
    {
        return (bool) $this->evaluate($this->showWatermarkControls);
    }

    /**
     * Aspect ratio options passed to the watermark image editor.
     *
     * @return list<string|null>
     */
    public function getWatermarkEditorAspectRatios(): array
    {
        $resolved = $this->evaluate($this->watermarkEditorAspectRatios);

        if (is_array($resolved) && $resolved !== []) {
            return array_values($resolved);
        }

        /** @var list<string|null>|mixed $configured */
        $configured = config('filament-video-engine.watermark.editor_aspect_ratios', [
            null,
            '1:1',
            '4:3',
            '16:9',
            '3:1',
        ]);

        return is_array($configured) ? array_values($configured) : [null, '1:1', '4:3', '16:9'];
    }

    /**
     * Polling interval for Livewire wire:poll.
     */
    public function getPollingInterval(): string
    {
        return (string) ($this->evaluate($this->pollingInterval)
            ?? config('filament-video-engine.filament.polling_interval', '3s'));
    }

    /**
     * Form state key for the pending source video upload.
     */
    public function getUploadFieldName(): string
    {
        return $this->getName().self::UPLOAD_SUFFIX;
    }

    /**
     * Form state key for the optional custom poster upload.
     */
    public function getPosterFieldName(): string
    {
        return $this->getName().self::POSTER_SUFFIX;
    }

    /**
     * Form state key for the frame extraction timestamp.
     */
    public function getThumbnailFieldName(): string
    {
        return $this->getName().self::THUMBNAIL_SUFFIX;
    }

    /**
     * Form state key for the watermark enabled toggle.
     */
    public function getWatermarkEnabledFieldName(): string
    {
        return $this->getName().self::WATERMARK_ENABLED_SUFFIX;
    }

    /**
     * Form state key for the optional watermark image upload.
     */
    public function getWatermarkImageFieldName(): string
    {
        return $this->getName().self::WATERMARK_IMAGE_SUFFIX;
    }

    /**
     * Form state key for watermark position.
     */
    public function getWatermarkPositionFieldName(): string
    {
        return $this->getName().self::WATERMARK_POSITION_SUFFIX;
    }

    /**
     * Form state key for watermark opacity.
     */
    public function getWatermarkOpacityFieldName(): string
    {
        return $this->getName().self::WATERMARK_OPACITY_SUFFIX;
    }

    /**
     * Form state key for watermark margin.
     */
    public function getWatermarkMarginFieldName(): string
    {
        return $this->getName().self::WATERMARK_MARGIN_SUFFIX;
    }

    /**
     * Form state key for watermark width relative to the encoded video.
     */
    public function getWatermarkScaleFieldName(): string
    {
        return $this->getName().self::WATERMARK_SCALE_SUFFIX;
    }

    /**
     * Whether the field should keep Livewire polling active.
     */
    public function shouldPoll(): bool
    {
        if (! $this->isLicensed()) {
            return false;
        }

        if ($this->hasPendingLocalUpload()) {
            return false;
        }

        $payload = $this->getProgressPayload();

        if ($payload === null) {
            return false;
        }

        if (($payload['is_in_progress'] ?? false) === true) {
            return true;
        }

        $status = (string) ($payload['status'] ?? '');

        return in_array($status, [
            TranscodingStatusEnum::Pending->value,
            TranscodingStatusEnum::Queued->value,
            TranscodingStatusEnum::Uploading->value,
            TranscodingStatusEnum::Processing->value,
        ], true);
    }

    /**
     * Progress payload for the bound video UUID, if any.
     *
     * @return array<string, mixed>|null
     */
    public function getProgressPayload(): ?array
    {
        $video = $this->resolveVideoMedia();

        if ($video === null) {
            return null;
        }

        return app(VideoEngineManager::class)->progress($video)->toArray();
    }

    /**
     * Public URL for the uploaded or extracted poster image.
     */
    public function getPosterPreviewUrl(): ?string
    {
        $video = $this->resolveVideoMedia();

        if ($video === null || $video->poster_path === null || $video->poster_path === '') {
            return null;
        }

        return app(VideoStorageManager::class)->publicUrl(
            (string) ($video->disk_output ?: config('filament-video-engine.disks.output', 'public')),
            $video->poster_path,
        );
    }

    /**
     * Whether a poster preview can be shown.
     */
    public function hasPosterPreview(): bool
    {
        return $this->getPosterPreviewUrl() !== null;
    }

    /**
     * Original filename when a source video is already attached.
     */
    public function getSourceFilename(): ?string
    {
        $media = $this->resolveVideoMedia();

        if ($media === null || $media->original_filename === null || $media->original_filename === '') {
            return null;
        }

        return (string) $media->original_filename;
    }

    /**
     * Human-readable source file size when available.
     */
    public function getSourceSizeLabel(): ?string
    {
        $media = $this->resolveVideoMedia();

        if ($media === null || $media->size_bytes === null || $media->size_bytes <= 0) {
            return null;
        }

        return Number::fileSize($media->size_bytes, precision: 1);
    }

    /**
     * Source resolution badge for the attached video, e.g. "480p".
     */
    public function getSourceQualityLabel(): ?string
    {
        return $this->resolveVideoMedia()?->sourceQualityBadge();
    }

    /**
     * Whether a VideoMedia UUID is already bound.
     */
    public function hasExistingVideo(): bool
    {
        return $this->getBoundUuid() !== null;
    }

    /**
     * Bound VideoMedia UUID (scalar or nested under upload sub-field state).
     */
    public function getBoundUuid(): ?string
    {
        $state = $this->getRawState();

        if (is_string($state) && $state !== '') {
            return $state;
        }

        if (is_array($state)) {
            $uuid = $state[self::UUID_STATE_KEY] ?? null;

            return is_string($uuid) && $uuid !== '' ? $uuid : null;
        }

        return null;
    }

    /**
     * Persist the bound VideoMedia UUID without disturbing embedded upload state.
     */
    public function assignBoundUuid(?string $uuid): void
    {
        $raw = $this->getRawState();

        if (is_array($raw)) {
            if ($uuid === null || $uuid === '') {
                unset($raw[self::UUID_STATE_KEY]);
            } else {
                $raw[self::UUID_STATE_KEY] = $uuid;
            }

            $this->rawState($raw);

            return;
        }

        $this->state($uuid);
    }

    /**
     * Register built-in upload sub-fields (config-driven disks, limits, mime types).
     */
    private function registerUploadSchema(): void
    {
        $this->aboveContent(function (VideoEnginePicker $component): array {
            if (! $component->isLicensed()) {
                return [];
            }

            $maxVideoKb = (int) config('filament-video-engine.uploads.max_upload_kilobytes', 5242880);
            $videoMimes = config('filament-video-engine.uploads.accepted_mimetypes', ['video/mp4']);
            $posterMimes = config('filament-video-engine.thumbnail.accepted_mimetypes', ['image/jpeg', 'image/png', 'image/webp']);

            $fields = [
                FileUpload::make($component->getUploadFieldName())
                    ->label(__('filament-video-engine::messages.picker.upload_video'))
                    ->acceptedFileTypes(is_array($videoMimes) ? $videoMimes : ['video/mp4'])
                    ->maxSize($maxVideoKb)
                    ->helperText($component->hasExistingVideo()
                        ? __('filament-video-engine::messages.picker.upload_replace_help')
                        : __('filament-video-engine::messages.picker.upload_help'))
                    ->disk(fn (): string => (string) (
                        $component->resolveVideoMedia()?->disk_input
                        ?? config('filament-video-engine.disks.input', 'local')
                    ))
                    ->visibility('private')
                    ->downloadable()
                    ->openable()
                    ->previewable(false)
                    ->storeFiles(false)
                    ->dehydrated(false)
                    ->getUploadedFileUsing(
                        function (FileUpload $upload, string $file, string|array|null $storedFileNames) use ($component): ?array {
                            return $component->resolveStoredUploadMetadata(
                                $upload,
                                $file,
                                preferOriginalFilename: true,
                            );
                        },
                    )
                    ->afterStateUpdated(function (mixed $state) use ($component): void {
                        $component->cachePendingField($component->getUploadFieldName(), $state);
                    })
                    ->columnSpanFull(),
            ];

            if ($component->shouldShowPosterControls()) {
                $fields[] = FileUpload::make($component->getPosterFieldName())
                    ->label(__('filament-video-engine::messages.picker.poster_upload'))
                    ->image()
                    ->acceptedFileTypes(is_array($posterMimes) ? $posterMimes : ['image/jpeg', 'image/png', 'image/webp'])
                    ->imagePreviewHeight('180')
                    ->helperText(__('filament-video-engine::messages.picker.poster_help'))
                    ->disk(fn (): string => (string) (
                        $component->resolveVideoMedia()?->disk_output
                        ?? config('filament-video-engine.disks.output', 'public')
                    ))
                    ->visibility('public')
                    ->downloadable()
                    ->openable()
                    ->storeFiles(false)
                    ->dehydrated(false)
                    ->getUploadedFileUsing(
                        function (FileUpload $upload, string $file, string|array|null $storedFileNames) use ($component): ?array {
                            return $component->resolveStoredUploadMetadata(
                                $upload,
                                $file,
                                preferOriginalFilename: false,
                            );
                        },
                    )
                    ->afterStateUpdated(function (mixed $state) use ($component): void {
                        $component->cachePendingField($component->getPosterFieldName(), $state);
                    })
                    ->columnSpanFull();

                $defaultTimestamp = (float) config('filament-video-engine.thumbnail.default_timestamp', 5.0);

                $fields[] = TimePicker::make($component->getThumbnailFieldName())
                    ->label(__('filament-video-engine::messages.picker.thumbnail_at'))
                    ->default(VideoTimestampFormatter::toTimeString($defaultTimestamp))
                    ->native(false)
                    ->seconds(true)
                    ->hoursStep(1)
                    ->minutesStep(1)
                    ->secondsStep(1)
                    ->format('H:i:s')
                    ->displayFormat('H:i:s')
                    ->helperText(__('filament-video-engine::messages.picker.thumbnail_at_help'))
                    ->dehydrated(false)
                    ->columnSpanFull()
                    ->afterStateUpdated(function (mixed $state) use ($component): void {
                        $component->cachePendingField($component->getThumbnailFieldName(), $state);
                    });
            }

            if ($component->shouldShowWatermarkControls()) {
                $watermarkMimes = config('filament-video-engine.watermark.accepted_mimetypes', ['image/png', 'image/webp', 'image/jpeg']);
                $defaultPosition = (string) config('filament-video-engine.watermark.position', 'bottom-right');
                $defaultOpacity = (float) config('filament-video-engine.watermark.opacity', 0.6);
                $defaultMargin = (int) config('filament-video-engine.watermark.margin', 20);
                $defaultScale = (float) config('filament-video-engine.watermark.max_width_percent', 12);
                $watermarkEnabledField = $component->getWatermarkEnabledFieldName();

                $fields[] = Section::make(__('filament-video-engine::messages.picker.watermark_section'))
                    ->description(__('filament-video-engine::messages.picker.watermark_enabled_help'))
                    ->icon('heroicon-o-shield-check')
                    ->compact()
                    ->contained()
                    ->columnSpanFull()
                    ->footerActions([
                        Action::make('reapplyWatermark')
                            ->label(__('filament-video-engine::messages.picker.watermark_apply_streams'))
                            ->icon('heroicon-o-arrow-path')
                            ->color('warning')
                            ->requiresConfirmation()
                            ->modalHeading(__('filament-video-engine::messages.picker.watermark_apply_streams'))
                            ->modalDescription(__('filament-video-engine::messages.picker.watermark_apply_streams_help'))
                            ->visible(fn (): bool => $component->canReapplyWatermarkStreams())
                            ->action(function () use ($component): void {
                                $component->reapplyWatermarkStreams();
                            }),
                    ])
                    ->schema([
                        Toggle::make($watermarkEnabledField)
                            ->label(__('filament-video-engine::messages.picker.watermark_enabled'))
                            ->default((bool) config('filament-video-engine.watermark.enabled', false))
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (mixed $state) use ($component, $watermarkEnabledField): void {
                                $component->cachePendingField($watermarkEnabledField, $state);
                            })
                            ->columnSpanFull(),

                        FileUpload::make($component->getWatermarkImageFieldName())
                            ->label(__('filament-video-engine::messages.picker.watermark_image'))
                            ->image()
                            ->acceptedFileTypes(is_array($watermarkMimes) ? $watermarkMimes : ['image/png', 'image/webp'])
                            ->imagePreviewHeight('120')
                            ->helperText(__('filament-video-engine::messages.picker.watermark_image_help'))
                            ->imageEditor()
                            ->imageEditorMode(2)
                            ->imageEditorAspectRatioOptions($component->getWatermarkEditorAspectRatios())
                            ->disk(fn (): string => (string) (
                                $component->resolveVideoMedia()?->disk_output
                                ?? config('filament-video-engine.disks.output', 'public')
                            ))
                            ->visibility('public')
                            ->downloadable()
                            ->openable()
                            ->storeFiles(false)
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => (bool) $get($watermarkEnabledField))
                            ->getUploadedFileUsing(
                                function (FileUpload $upload, string $file, string|array|null $storedFileNames) use ($component): ?array {
                                    return $component->resolveStoredUploadMetadata(
                                        $upload,
                                        $file,
                                        preferOriginalFilename: false,
                                    );
                                },
                            )
                            ->afterStateUpdated(function (mixed $state) use ($component): void {
                                $component->cachePendingField($component->getWatermarkImageFieldName(), $state);
                            })
                            ->columnSpanFull(),

                        Grid::make(12)
                            ->visible(fn (Get $get): bool => (bool) $get($watermarkEnabledField))
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'fi-ve-watermark-settings'])
                            ->schema([
                                Select::make($component->getWatermarkPositionFieldName())
                                    ->label(__('filament-video-engine::messages.picker.watermark_position'))
                                    ->options(collect(WatermarkPositionEnum::cases())
                                        ->mapWithKeys(static fn (WatermarkPositionEnum $position): array => [
                                            $position->value => __('filament-video-engine::messages.picker.watermark_positions.'.$position->value),
                                        ])
                                        ->all())
                                    ->default($defaultPosition)
                                    ->native(false)
                                    ->selectablePlaceholder(false)
                                    ->dehydrated(false)
                                    ->columnSpan(['default' => 12, 'md' => 6])
                                    ->afterStateUpdated(function (mixed $state) use ($component): void {
                                        $component->cachePendingField($component->getWatermarkPositionFieldName(), $state);
                                    }),

                                TextInput::make($component->getWatermarkOpacityFieldName())
                                    ->label(__('filament-video-engine::messages.picker.watermark_opacity'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(1)
                                    ->step(0.05)
                                    ->default($defaultOpacity)
                                    ->placeholder('0.6')
                                    ->dehydrated(false)
                                    ->columnSpan(['default' => 12, 'md' => 6])
                                    ->afterStateUpdated(function (mixed $state) use ($component): void {
                                        $component->cachePendingField($component->getWatermarkOpacityFieldName(), $state);
                                    }),

                                TextInput::make($component->getWatermarkMarginFieldName())
                                    ->label(__('filament-video-engine::messages.picker.watermark_margin'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->default($defaultMargin)
                                    ->suffix('px')
                                    ->dehydrated(false)
                                    ->columnSpan(['default' => 12, 'md' => 6])
                                    ->afterStateUpdated(function (mixed $state) use ($component): void {
                                        $component->cachePendingField($component->getWatermarkMarginFieldName(), $state);
                                    }),

                                TextInput::make($component->getWatermarkScaleFieldName())
                                    ->label(__('filament-video-engine::messages.picker.watermark_scale'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(50)
                                    ->step(1)
                                    ->default($defaultScale)
                                    ->suffix('%')
                                    ->dehydrated(false)
                                    ->columnSpan(['default' => 12, 'md' => 6])
                                    ->afterStateUpdated(function (mixed $state) use ($component): void {
                                        $component->cachePendingField($component->getWatermarkScaleFieldName(), $state);
                                    }),

                                Placeholder::make($component->getName().'__watermark_settings_hint')
                                    ->label('')
                                    ->content(__('filament-video-engine::messages.picker.watermark_scale_help'))
                                    ->columnSpanFull(),
                            ]),
                    ]);
            }

            return $fields;
        });
    }

    /**
     * Hydrate UUID from HasVideoEngine models and process uploads on save.
     */
    private function registerRelationshipHooks(): void
    {
        $this->afterStateHydrated(function (VideoEnginePicker $component): void {
            $record = $component->getRecord();

            if (! $record instanceof Model) {
                return;
            }

            if (! in_array(HasVideoEngine::class, class_uses_recursive($record), true)) {
                return;
            }

            if (! method_exists($record, 'getPrimaryVideoMedia')) {
                return;
            }

            $uuid = $record->getPrimaryVideoMedia()?->uuid;

            if (is_string($uuid) && $uuid !== '') {
                $component->assignBoundUuid($uuid);
            }

            $component->hydrateStoredUploadStates();
        });

        $this->saveRelationshipsUsing(function (VideoEnginePicker $component): void {
            $component->processPendingUpload();
            $component->syncWatermarkSettings();
        });
    }

    /**
     * Cache pending upload control state before Livewire temp files are cleared.
     */
    public function cachePendingField(string $fieldName, mixed $state): void
    {
        if ($this->isWatermarkScalarField($fieldName) || $fieldName === $this->getThumbnailFieldName()) {
            if ($this->isFilledFieldState($state)) {
                $this->pendingUploadCache[$fieldName] = $state;
            }

            return;
        }

        if ($this->isPendingUploadState($state)) {
            $this->pendingUploadCache[$fieldName] = $state;
        }
    }

    /**
     * Upload, attach, and queue transcoding when a pending file exists.
     */
    public function processPendingUpload(): void
    {
        if (! $this->isLicensed()) {
            return;
        }

        $upload = $this->resolvePendingFieldState($this->getUploadFieldName());

        if (! $this->isPendingUploadState($upload)) {
            return;
        }

        $poster = $this->resolvePendingFieldState($this->getPosterFieldName());

        if (! $this->isPendingUploadState($poster)) {
            $poster = null;
        }

        $thumbnailAt = $this->resolvePendingFieldState($this->getThumbnailFieldName());
        $thumbnailSeconds = VideoTimestampFormatter::toSeconds($thumbnailAt);

        $media = app(ProcessVideoEnginePickerUploadAction::class)->execute(
            videoable: $this->getRecord(),
            upload: $upload,
            customPoster: $poster,
            thumbnailAtSeconds: $thumbnailSeconds,
            qualities: $this->getQualities(),
            title: $this->resolveUploadTitle(),
            watermarkSettings: $this->shouldShowWatermarkControls() ? $this->resolveWatermarkSettings() : null,
        );

        if ($media === null) {
            return;
        }

        $this->assignBoundUuid($media->uuid);
        $this->clearPendingUploadState();
        $this->hydrateStoredUploadStates();
    }

    /**
     * Build FilePond metadata for an already stored upload path.
     *
     * @return array{name: string, size: int, type: ?string, url: ?string}|null
     */
    public function resolveStoredUploadMetadata(
        FileUpload $upload,
        string $file,
        bool $preferOriginalFilename,
    ): ?array {
        $storage = Storage::disk($upload->getDiskName());

        try {
            if (! $storage->exists($file)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $media = $this->resolveVideoMedia();
        $displayName = basename($file);

        if (
            $preferOriginalFilename
            && $media !== null
            && $media->original_path === $file
            && filled($media->original_filename)
        ) {
            $displayName = (string) $media->original_filename;
        }

        $url = null;

        try {
            $url = $upload->getVisibility() === 'private'
                ? $storage->temporaryUrl($file, now()->addMinutes((int) config('filament.temporary_file_url_expiry_minutes', 30)))
                : $storage->url($file);
        } catch (\Throwable) {
            // Local disks may not support temporary URLs.
        }

        try {
            return [
                'name' => $displayName,
                'size' => $storage->size($file),
                'type' => $storage->mimeType($file),
                'url' => $url,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Show stored source/poster paths in upload controls after save.
     */
    public function hydrateStoredUploadStates(): void
    {
        if ($this->hasPendingLocalUpload()) {
            return;
        }

        $media = $this->resolveVideoMedia();

        if ($media === null) {
            return;
        }

        if (filled($media->original_path)) {
            $this->setChildFieldState($this->getUploadFieldName(), [(string) $media->original_path]);
        }

        if ($media->poster_is_custom && filled($media->poster_path)) {
            $this->setChildFieldState($this->getPosterFieldName(), [(string) $media->poster_path]);
        }

        if ($media->thumbnail_at_seconds !== null) {
            $this->setChildFieldState(
                $this->getThumbnailFieldName(),
                VideoTimestampFormatter::toTimeString((float) $media->thumbnail_at_seconds),
            );
        }

        $this->hydrateWatermarkStates($media);
    }

    /**
     * Hydrate watermark controls from the stored VideoMedia row.
     */
    public function hydrateWatermarkStates(?VideoMedia $media = null): void
    {
        if (! $this->shouldShowWatermarkControls()) {
            return;
        }

        $media ??= $this->resolveVideoMedia();

        if ($media === null) {
            return;
        }

        $this->setChildFieldState($this->getWatermarkEnabledFieldName(), $media->watermark_enabled);

        if (filled($media->watermark_path)) {
            $this->setChildFieldState($this->getWatermarkImageFieldName(), [(string) $media->watermark_path]);
        }

        if (filled($media->watermark_position)) {
            $this->setChildFieldState($this->getWatermarkPositionFieldName(), (string) $media->watermark_position);
        }

        if ($media->watermark_opacity !== null) {
            $this->setChildFieldState($this->getWatermarkOpacityFieldName(), (string) $media->watermark_opacity);
        }

        if ($media->watermark_margin !== null) {
            $this->setChildFieldState($this->getWatermarkMarginFieldName(), (string) $media->watermark_margin);
        }

        $scalePercent = $media->watermark_scale_percent ?? (float) config('filament-video-engine.watermark.max_width_percent', 12);
        $this->setChildFieldState($this->getWatermarkScaleFieldName(), (string) $scalePercent);
    }

    /**
     * Persist watermark settings for an existing attached video.
     */
    public function syncWatermarkSettings(): void
    {
        if (! $this->shouldShowWatermarkControls()) {
            return;
        }

        $media = $this->resolveVideoMedia();

        if ($media === null) {
            return;
        }

        app(SyncVideoWatermarkAction::class)->execute($media, $this->resolveWatermarkSettings());
    }

    /**
     * Whether saved watermark settings can be baked into existing HLS streams.
     */
    public function canReapplyWatermarkStreams(): bool
    {
        if (! $this->shouldShowWatermarkControls()) {
            return false;
        }

        $media = $this->resolveVideoMedia();

        if ($media === null) {
            return false;
        }

        return app(ReapplyWatermarkAction::class)->canExecute($media);
    }

    /**
     * Sync current watermark form state and queue HLS re-encode for all renditions.
     */
    public function reapplyWatermarkStreams(): void
    {
        if (! $this->isLicensed()) {
            return;
        }

        $this->syncWatermarkSettings();

        $media = $this->resolveVideoMedia();

        if ($media === null) {
            return;
        }

        $dispatched = app(ReapplyWatermarkAction::class)->execute($media->refresh());

        Notification::make()
            ->title(__('filament-video-engine::messages.picker.watermark_apply_streams_started'))
            ->body(__('filament-video-engine::messages.picker.watermark_apply_streams_started_body', [
                'count' => $dispatched,
            ]))
            ->success()
            ->send();
    }

    /**
     * @return array{
     *     enabled: bool,
     *     image: mixed,
     *     position: ?string,
     *     opacity: ?float,
     *     margin: ?int,
     *     scale_percent: ?float,
     * }
     */
    public function resolveWatermarkSettings(): array
    {
        $enabled = (bool) $this->resolvePendingFieldState($this->getWatermarkEnabledFieldName());
        $opacity = $this->resolvePendingFieldState($this->getWatermarkOpacityFieldName());
        $margin = $this->resolvePendingFieldState($this->getWatermarkMarginFieldName());
        $scale = $this->resolvePendingFieldState($this->getWatermarkScaleFieldName());

        return [
            'enabled' => $enabled,
            'image' => $this->resolvePendingFieldState($this->getWatermarkImageFieldName()),
            'position' => $this->resolvePendingScalarState($this->getWatermarkPositionFieldName()),
            'opacity' => is_numeric($opacity) ? (float) $opacity : null,
            'margin' => is_numeric($margin) ? (int) $margin : null,
            'scale_percent' => is_numeric($scale) ? (float) $scale : null,
        ];
    }

    /**
     * Write state to an embedded upload sub-field.
     */
    private function setChildFieldState(string $fieldName, mixed $state): void
    {
        foreach ($this->getChildSchemas(withHidden: true) as $childSchema) {
            foreach ($childSchema->getFlatComponents(withHidden: true) as $component) {
                if (! $component instanceof Field || $component->getName() !== $fieldName) {
                    continue;
                }

                $component->state($state);

                if ($component instanceof FileUpload) {
                    $component->hydrateFiles();
                }

                return;
            }
        }

        $raw = $this->getRawState();

        if (is_array($raw)) {
            $raw[$fieldName] = $state;
            $this->rawState($raw);
        }
    }

    /**
     * Resolve pending field state from cache, child components, or raw form data.
     */
    private function resolvePendingFieldState(string $fieldName): mixed
    {
        if (array_key_exists($fieldName, $this->pendingUploadCache)
            && $this->isFilledFieldState($this->pendingUploadCache[$fieldName])) {
            return $this->pendingUploadCache[$fieldName];
        }

        $childState = $this->resolveChildFieldState($fieldName);

        if ($this->isFilledFieldState($childState)) {
            return $childState;
        }

        $raw = $this->getRootContainer()->getRawState();

        if (is_array($raw)) {
            foreach ($this->candidateStatePaths($fieldName) as $path) {
                $state = data_get($raw, $path);

                if ($this->isFilledFieldState($state)) {
                    return $state;
                }
            }
        }

        $livewire = $this->getLivewire();

        foreach ($this->candidateStatePaths($fieldName) as $path) {
            $state = data_get($livewire, 'data.'.$path) ?? data_get($livewire, $path);

            if ($this->isFilledFieldState($state)) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Read state directly from an embedded upload sub-field.
     */
    private function resolveChildFieldState(string $fieldName): mixed
    {
        foreach ($this->getChildSchemas(withHidden: true) as $childSchema) {
            foreach ($childSchema->getFlatComponents(withHidden: true) as $component) {
                if (! $component instanceof Field || $component->getName() !== $fieldName) {
                    continue;
                }

                return $component->getRawState();
            }
        }

        return null;
    }

    /**
     * Possible dot-paths for a pending upload control in Livewire state.
     *
     * @return list<string>
     */
    private function candidateStatePaths(string $fieldName): array
    {
        $paths = [
            $fieldName,
            $this->getStatePath().'.'.$fieldName,
        ];

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * Whether a local file is still selected in an upload control (not yet saved).
     */
    private function hasPendingLocalUpload(): bool
    {
        foreach ([
            $this->getUploadFieldName(),
            $this->getPosterFieldName(),
            $this->getWatermarkImageFieldName(),
        ] as $fieldName) {
            if ($this->isPendingUploadState($this->resolvePendingFieldState($fieldName))) {
                return true;
            }
        }

        return $this->pendingUploadCache !== [];
    }

    /**
     * Whether the value represents a fresh Livewire upload (not a stored disk path).
     */
    private function isPendingUploadState(mixed $state): bool
    {
        if ($state instanceof TemporaryUploadedFile || $state instanceof UploadedFile) {
            return true;
        }

        if (is_array($state)) {
            foreach ($state as $item) {
                if ($this->isPendingUploadState($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a pending upload / poster field holds a usable value.
     */
    private function isFilledFieldState(mixed $state): bool
    {
        if ($state === null || $state === '' || $state === []) {
            return false;
        }

        return true;
    }

    /**
     * Whether the field stores scalar watermark settings rather than uploads.
     */
    private function isWatermarkScalarField(string $fieldName): bool
    {
        return in_array($fieldName, [
            $this->getWatermarkEnabledFieldName(),
            $this->getWatermarkPositionFieldName(),
            $this->getWatermarkOpacityFieldName(),
            $this->getWatermarkMarginFieldName(),
            $this->getWatermarkScaleFieldName(),
        ], true);
    }

    /**
     * Read a scalar child field value as a string when present.
     */
    private function resolvePendingScalarState(string $fieldName): ?string
    {
        $state = $this->resolvePendingFieldState($fieldName);

        if ($state === null || $state === '') {
            return null;
        }

        return is_scalar($state) ? (string) $state : null;
    }

    /**
     * Resolve title for the VideoMedia row from the record or callback.
     */
    private function resolveUploadTitle(): ?string
    {
        if ($this->titleFromRecord instanceof Closure) {
            $title = $this->evaluate($this->titleFromRecord, [
                'record' => $this->getRecord(),
            ]);

            return is_string($title) && $title !== '' ? $title : null;
        }

        $record = $this->getRecord();

        if ($record instanceof Model && isset($record->title) && is_string($record->title) && $record->title !== '') {
            return $record->title;
        }

        return null;
    }

    /**
     * Reset pending upload controls after a successful attach.
     */
    private function clearPendingUploadState(): void
    {
        unset(
            $this->pendingUploadCache[$this->getUploadFieldName()],
            $this->pendingUploadCache[$this->getPosterFieldName()],
        );

        foreach ([$this->getUploadFieldName(), $this->getPosterFieldName()] as $fieldName) {
            foreach ($this->getChildSchemas(withHidden: true) as $childSchema) {
                foreach ($childSchema->getFlatComponents(withHidden: true) as $component) {
                    if ($component instanceof Field && $component->getName() === $fieldName) {
                        $component->state(null);
                    }
                }
            }
        }

        $raw = $this->getRootContainer()->getRawState();

        if (is_array($raw)) {
            data_set($raw, $this->getUploadFieldName(), null);
            data_set($raw, $this->getPosterFieldName(), null);
            $this->getRootContainer()->rawState($raw);
        }
    }

    /**
     * Resolve the VideoMedia row for the current field state.
     */
    protected function resolveVideoMedia(): ?VideoMedia
    {
        $uuid = $this->getBoundUuid();

        if ($uuid === null) {
            return null;
        }

        return VideoMedia::query()
            ->where('uuid', $uuid)
            ->with('conversions')
            ->first();
    }
}
