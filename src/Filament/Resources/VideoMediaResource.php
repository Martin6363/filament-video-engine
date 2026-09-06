<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;
use Martin6363\FilamentVideoEngine\Actions\ReapplyWatermarkAction;
use Martin6363\FilamentVideoEngine\Actions\RegenerateQualityAction;
use Martin6363\FilamentVideoEngine\Actions\RetryFailedConversionAction;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource\Pages\EditVideoMedia;
use Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource\Pages\ListVideoMedia;
use Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource\Pages\ViewVideoMedia;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
/**
 * Filament resource for managing video conversions and retries.
 */
final class VideoMediaResource extends Resource
{
    protected static ?string $model = VideoMedia::class;

    protected static bool $isScopedToTenant = false;

    /**
     * {@inheritdoc}
     */
    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return config('filament-video-engine.filament.navigation_icon', 'heroicon-o-film');
    }

    /**
     * {@inheritdoc}
     */
    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return config('filament-video-engine.filament.navigation_group', 'Video Engine Demo');
    }

    /**
     * {@inheritdoc}
     */
    public static function getNavigationSort(): ?int
    {
        $sort = config('filament-video-engine.filament.navigation_sort');

        return is_numeric($sort) ? (int) $sort : 20;
    }

    /**
     * {@inheritdoc}
     */
    public static function getModelLabel(): string
    {
        return __('filament-video-engine::messages.resource.label');
    }

    /**
     * {@inheritdoc}
     */
    public static function getPluralModelLabel(): string
    {
        return __('filament-video-engine::messages.resource.plural');
    }

    /**
     * Include soft-deleted rows so TrashedFilter can show / restore / force-delete them.
     *
     * @return Builder<VideoMedia>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Configure the view infolist (includes progressive HLS preview).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament-video-engine::messages.resource.sections.preview'))
                ->schema([
                    ViewEntry::make('admin_preview')
                        ->hiddenLabel()
                        ->view('filament-video-engine::filament.admin-preview')
                        ->viewData(static fn (VideoMedia $record): array => [
                            'video' => $record,
                            'mode' => 'preview',
                        ])
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Section::make(__('filament-video-engine::messages.resource.sections.media'))
                ->schema([
                    TextEntry::make('uuid')
                        ->label('UUID')
                        ->copyable(),
                    TextEntry::make('title')
                        ->label(__('filament-video-engine::messages.resource.fields.title'))
                        ->placeholder('—'),
                    TextEntry::make('original_filename')
                        ->label(__('filament-video-engine::messages.resource.fields.filename'))
                        ->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->color(static fn (mixed $state): string => self::statusEnum($state)?->color() ?? 'gray')
                        ->formatStateUsing(static fn (mixed $state): string => self::statusLabel($state) ?? '—'),
                    TextEntry::make('source_quality')
                        ->label(__('filament-video-engine::messages.resource.fields.source_quality'))
                        ->state(static function (VideoMedia $record): string {
                            $badge = $record->sourceQualityBadge();

                            return $badge !== null
                                ? __('filament-video-engine::messages.resource.source_badge', ['quality' => $badge])
                                : ($record->sourceResolutionLabel() ?? '—');
                        })
                        ->helperText(static fn (VideoMedia $record): ?string => $record->skippedQualitiesNotice()),
                    TextEntry::make('progress_percent')
                        ->label(__('filament-video-engine::messages.resource.fields.progress'))
                        ->suffix('%'),
                    TextEntry::make('current_step')
                        ->placeholder('—'),
                    TextEntry::make('error_message')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('filament-video-engine::messages.resource.sections.poster'))
                ->schema([
                    TextEntry::make('poster_is_custom')
                        ->label(__('filament-video-engine::messages.resource.fields.custom_poster'))
                        ->formatStateUsing(static fn (mixed $state): string => $state ? __('Yes') : __('No')),
                    TextEntry::make('thumbnail_at_seconds')
                        ->label(__('filament-video-engine::messages.resource.fields.thumbnail_at'))
                        ->placeholder('—'),
                    ImageEntry::make('poster_path')
                        ->label(__('filament-video-engine::messages.resource.fields.poster'))
                        ->state(static fn (VideoMedia $record): ?string => $record->posterPublicUrl())
                        ->imageHeight(180)
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Configure the form schema.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament-video-engine::messages.resource.sections.media'))
                ->schema([
                    TextInput::make('uuid')
                        ->label('UUID')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('title')
                        ->label(__('filament-video-engine::messages.resource.fields.title'))
                        ->maxLength(255),
                    TextInput::make('original_filename')
                        ->disabled(),
                    TextInput::make('status')
                        ->disabled()
                        ->formatStateUsing(
                            static fn (mixed $state): ?string => self::statusLabel($state),
                        ),
                    Placeholder::make('source_quality')
                        ->label(__('filament-video-engine::messages.resource.fields.source_quality'))
                        ->content(static function (?VideoMedia $record): string {
                            if ($record === null) {
                                return '—';
                            }

                            $badge = $record->sourceQualityBadge();

                            return $badge !== null
                                ? __('filament-video-engine::messages.resource.source_badge', ['quality' => $badge])
                                : ($record->sourceResolutionLabel() ?? '—');
                        })
                        ->helperText(static fn (?VideoMedia $record): ?string => $record?->skippedQualitiesNotice()),
                    TextInput::make('progress_percent')
                        ->label(__('filament-video-engine::messages.resource.fields.progress'))
                        ->disabled()
                        ->suffix('%'),
                    TextInput::make('current_step')
                        ->disabled(),
                    TextInput::make('error_message')
                        ->disabled()
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('filament-video-engine::messages.resource.sections.poster'))
                ->schema([
                    FileUpload::make('poster_path')
                        ->label(__('filament-video-engine::messages.resource.fields.poster'))
                        ->image()
                        ->disk(fn (): string => (string) config('filament-video-engine.disks.output', 'public'))
                        ->directory(fn (): string => trim((string) config('filament-video-engine.paths.posters'), '/'))
                        ->visibility('public')
                        ->deleteUploadedFileUsing(function (string $file, ?VideoMedia $record): void {
                            // Keep the persisted poster until afterSave/observer replaces it.
                            // Only remove orphan uploads the admin discarded in the UI.
                            if ($record !== null && $record->poster_path === $file) {
                                return;
                            }

                            $disk = (string) config('filament-video-engine.disks.output', 'public');
                            Storage::disk($disk)->delete($file);
                        }),
                ])
                ->columns(1),
        ]);
    }

    /**
     * Configure the table.
     */
    public static function table(Table $table): Table
    {
        $polling = (string) config('filament-video-engine.filament.polling_interval', '3s');

        return $table
            ->poll($polling)
            ->columns([
                ImageColumn::make('poster_thumb')
                    ->label(__('filament-video-engine::messages.resource.fields.thumbnail'))
                    ->state(static fn (VideoMedia $record): ?string => $record->posterPublicUrl())
                    ->square()
                    ->imageHeight(44)
                    ->defaultImageUrl(static fn (): string => 'data:image/svg+xml,'.rawurlencode(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="88" height="88" viewBox="0 0 88 88"><rect width="88" height="88" fill="#e2e8f0"/><path d="M34 28v32l28-16z" fill="#94a3b8"/></svg>'
                    ))
                    ->extraImgAttributes([
                        'loading' => 'lazy',
                        'alt' => '',
                    ]),
                TextColumn::make('uuid')
                    ->searchable()
                    ->toggleable()
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state)
                    ->limit(8),
                TextColumn::make('title')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('original_filename')
                    ->label(__('filament-video-engine::messages.resource.fields.filename'))
                    ->searchable()
                    ->limit(30),
                TextColumn::make('status')
                    ->badge()
                    ->color(static fn (mixed $state): string => self::statusEnum($state)?->color() ?? 'gray')
                    ->formatStateUsing(static fn (mixed $state): string => self::statusLabel($state) ?? '—'),
                TextColumn::make('source_quality')
                    ->label(__('filament-video-engine::messages.resource.fields.source_quality'))
                    ->badge()
                    ->color('gray')
                    ->weight(FontWeight::Medium)
                    ->state(static function (VideoMedia $record): ?string {
                        $badge = $record->sourceQualityBadge();

                        return $badge !== null
                            ? __('filament-video-engine::messages.resource.source_badge', ['quality' => $badge])
                            : null;
                    })
                    ->tooltip(static fn (VideoMedia $record): ?string => $record->skippedQualitiesNotice())
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('progress_percent')
                    ->label('%')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('current_step')
                    ->toggleable(),
                TextColumn::make('duration_seconds')
                    ->label(__('filament-video-engine::messages.resource.fields.duration'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->label(__('filament-video-engine::messages.resource.fields.deleted_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TranscodingStatusEnum::cases())
                        ->mapWithKeys(static fn (TranscodingStatusEnum $s): array => [$s->value => $s->label()])
                        ->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('reapplyWatermark')
                        ->label(__('filament-video-engine::messages.resource.actions.reapply_watermark'))
                        ->icon('heroicon-o-shield-check')
                        ->color('warning')
                        ->visible(static fn (VideoMedia $record): bool => ! $record->trashed()
                            && app(ReapplyWatermarkAction::class)->canExecute($record))
                        ->requiresConfirmation()
                        ->modalHeading(__('filament-video-engine::messages.resource.actions.reapply_watermark'))
                        ->modalDescription(__('filament-video-engine::messages.resource.helpers.reapply_watermark'))
                        ->action(static function (VideoMedia $record): void {
                            $dispatched = app(ReapplyWatermarkAction::class)->execute($record);

                            Notification::make()
                                ->title(__('filament-video-engine::messages.picker.watermark_apply_streams_started'))
                                ->body(__('filament-video-engine::messages.picker.watermark_apply_streams_started_body', [
                                    'count' => $dispatched,
                                ]))
                                ->success()
                                ->send();
                        }),
                    Action::make('retry')
                        ->label(__('filament-video-engine::messages.resource.actions.retry'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(static fn (VideoMedia $record): bool => ! $record->trashed() && $record->status->canRetry())
                        ->requiresConfirmation()
                        ->action(static function (VideoMedia $record): void {
                            app(RetryFailedConversionAction::class)->execute($record);
                        }),
                    Action::make('regenerateQuality')
                        ->label(__('filament-video-engine::messages.resource.actions.regenerate'))
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->visible(static fn (VideoMedia $record): bool => ! $record->trashed())
                        ->form(function (VideoMedia $record): array {
                            $sourceBadge = $record->sourceQualityBadge();

                            return [
                                CheckboxList::make('qualities')
                                    ->label(__('filament-video-engine::messages.resource.fields.qualities'))
                                    ->options(collect(VideoQualityEnum::cases())
                                        ->mapWithKeys(static fn (VideoQualityEnum $quality): array => [
                                            $quality->value => $quality->value,
                                        ])
                                        ->all())
                                    ->disableOptionWhen(
                                        fn (string $value): bool => ! $record->canEncodeQuality($value),
                                    )
                                    ->descriptions(
                                        collect(VideoQualityEnum::cases())
                                            ->mapWithKeys(static function (VideoQualityEnum $quality) use ($record): array {
                                                if ($record->canEncodeQuality($quality)) {
                                                    return [$quality->value => null];
                                                }

                                                $source = $record->sourceQualityBadge()
                                                    ?? __('filament-video-engine::messages.resource.helpers.unknown_source_height');

                                                return [
                                                    $quality->value => __('filament-video-engine::messages.resource.helpers.regenerate_quality_disabled', [
                                                        'source' => $source,
                                                    ]),
                                                ];
                                            })
                                            ->all(),
                                    )
                                    ->bulkToggleable()
                                    ->columns(2)
                                    ->helperText($sourceBadge !== null
                                        ? __('filament-video-engine::messages.resource.helpers.regenerate_quality_limited', [
                                            'source' => $sourceBadge,
                                        ])
                                        : null)
                                    ->required(),
                            ];
                        })
                        ->action(static function (VideoMedia $record, array $data): void {
                            $qualities = is_array($data['qualities'] ?? null) ? $data['qualities'] : [];
                            $dispatched = app(RegenerateQualityAction::class)->executeMany($record, $qualities);

                            Notification::make()
                                ->title(__('filament-video-engine::messages.resource.notifications.regenerate_started'))
                                ->body(__('filament-video-engine::messages.resource.notifications.regenerate_started_body', [
                                    'count' => $dispatched,
                                ]))
                                ->success()
                                ->send();
                        }),
                    DeleteAction::make()
                        ->visible(static fn (VideoMedia $record): bool => ! $record->trashed()),
                    RestoreAction::make(),
                    ForceDeleteAction::make(),
                ])
                    ->label(__('filament-video-engine::messages.resource.actions.menu'))
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->iconButton()
                    ->tooltip(__('filament-video-engine::messages.resource.actions.menu')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('filament-video-engine::messages.resource.actions.trash_bulk'))
                        ->color('gray'),
                    ForceDeleteBulkAction::make()
                        ->label(__('filament-video-engine::messages.resource.actions.force_delete_bulk'))
                        ->color('danger')
                        ->hidden(false),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVideoMedia::route('/'),
            'view' => ViewVideoMedia::route('/{record}'),
            'edit' => EditVideoMedia::route('/{record}/edit'),
        ];
    }

    /**
     * Normalize Filament state (enum or raw string) into TranscodingStatusEnum.
     */
    private static function statusEnum(mixed $state): ?TranscodingStatusEnum
    {
        if ($state instanceof TranscodingStatusEnum) {
            return $state;
        }

        if (is_string($state) && $state !== '') {
            return TranscodingStatusEnum::tryFrom($state);
        }

        return null;
    }

    /**
     * Human-readable status label for forms and tables.
     */
    private static function statusLabel(mixed $state): ?string
    {
        return self::statusEnum($state)?->label() ?? (is_string($state) ? $state : null);
    }
}
