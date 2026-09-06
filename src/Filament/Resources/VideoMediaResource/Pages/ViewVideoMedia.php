<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource;

/**
 * View a single video media record.
 */
final class ViewVideoMedia extends ViewRecord
{
    protected static string $resource = VideoMediaResource::class;

    /**
     * @return array<int, EditAction|DeleteAction|RestoreAction|ForceDeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
