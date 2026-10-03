<?php

namespace App\Filament\Resources\Divisions\Pages;

use App\Filament\Resources\Divisions\DivisionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDivisions extends ListRecords
{
    protected static string $resource = DivisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
