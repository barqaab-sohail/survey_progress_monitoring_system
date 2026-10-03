<?php

namespace App\Filament\Resources\HtDataImports\Pages;

use App\Filament\Resources\HtDataImports\HtDataImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHtDataImports extends ListRecords
{
    protected static string $resource = HtDataImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
