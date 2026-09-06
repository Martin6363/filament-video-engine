<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Martin6363\FilamentVideoEngine\Actions\DeleteReplacedVideoPosterAction;
use Martin6363\FilamentVideoEngine\Actions\RestoreExtractedVideoPosterAction;
use Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Edit a video media record.
 */
final class EditVideoMedia extends EditRecord
{
    protected static string $resource = VideoMediaResource::class;

    private ?string $previousPosterPath = null;

    private bool $shouldRestoreExtractedPoster = false;

    /**
     * @return array<int, ViewAction|DeleteAction|RestoreAction|ForceDeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /**
     * Capture the outgoing poster and normalize upload state before persist.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var VideoMedia $record */
        $record = $this->getRecord();
        $this->previousPosterPath = is_string($record->poster_path) && $record->poster_path !== ''
            ? $record->poster_path
            : null;

        $posterPath = $data['poster_path'] ?? null;

        if (is_array($posterPath)) {
            $posterPath = array_values(array_filter(
                $posterPath,
                static fn (mixed $value): bool => filled($value),
            ))[0] ?? null;
            $data['poster_path'] = $posterPath;
        }

        $hadPoster = $this->previousPosterPath !== null;
        $clearedPoster = blank($posterPath);

        // Custom (or any) poster removed in the form → queue extracted-frame restore.
        $this->shouldRestoreExtractedPoster = $hadPoster && $clearedPoster;

        if ($this->shouldRestoreExtractedPoster) {
            // Keep the current path until the worker writes the extracted poster.
            unset($data['poster_path'], $data['poster_is_custom']);

            return $data;
        }

        if (filled($posterPath)) {
            $data['poster_is_custom'] = true;
        }

        unset($data['thumbnail_at_seconds']);

        return $data;
    }

    /**
     * Queue poster restore when cleared, or delete the previous file on replace.
     */
    protected function afterSave(): void
    {
        /** @var VideoMedia $record */
        $record = $this->getRecord()->refresh();

        $previous = $this->previousPosterPath;
        $restore = $this->shouldRestoreExtractedPoster;

        $this->previousPosterPath = null;
        $this->shouldRestoreExtractedPoster = false;

        if ($restore) {
            app(RestoreExtractedVideoPosterAction::class)->queue($record);

            Notification::make()
                ->title(__('filament-video-engine::messages.resource.notifications.poster_restore_queued'))
                ->body(__('filament-video-engine::messages.resource.notifications.poster_restore_queued_body'))
                ->success()
                ->send();

            return;
        }

        if ($previous === null) {
            return;
        }

        app(DeleteReplacedVideoPosterAction::class)->execute($record, $previous);
    }
}
