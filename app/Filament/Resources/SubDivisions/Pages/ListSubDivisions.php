<?php

namespace App\Filament\Resources\SubDivisions\Pages;

use App\Filament\Resources\SubDivisions\SubDivisionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubDivisions extends ListRecords
{
    protected static string $resource = SubDivisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
