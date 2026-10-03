<?php

namespace App\Filament\Resources\HtDataImports\Pages;

use App\Filament\Resources\HtDataImports\HtDataImportResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditHtDataImport extends EditRecord
{
    protected static string $resource = HtDataImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
