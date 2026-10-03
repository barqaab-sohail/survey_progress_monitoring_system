<?php

namespace App\Filament\Resources\SubDivisions\Pages;

use App\Filament\Resources\SubDivisions\SubDivisionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSubDivision extends EditRecord
{
    protected static string $resource = SubDivisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
