<?php

namespace App\Filament\Resources\HtDataImports\Pages;

use App\Filament\Resources\HtDataImports\HtDataImportResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewHtDataImport extends ViewRecord
{
    protected static string $resource = HtDataImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
