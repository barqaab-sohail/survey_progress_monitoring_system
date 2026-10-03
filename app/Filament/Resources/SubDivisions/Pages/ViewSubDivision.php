<?php

namespace App\Filament\Resources\SubDivisions\Pages;

use App\Filament\Resources\SubDivisions\SubDivisionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSubDivision extends ViewRecord
{
    protected static string $resource = SubDivisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
