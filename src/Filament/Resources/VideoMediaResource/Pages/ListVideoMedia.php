<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Martin6363\FilamentVideoEngine\Filament\Resources\VideoMediaResource;

/**
 * List all video media records.
 */
final class ListVideoMedia extends ListRecords
{
    protected static string $resource = VideoMediaResource::class;

    /**
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
