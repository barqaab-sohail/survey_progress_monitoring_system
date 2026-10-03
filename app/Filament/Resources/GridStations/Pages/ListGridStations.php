<?php

namespace App\Filament\Resources\GridStations\Pages;

use App\Filament\Resources\GridStations\GridStationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGridStations extends ListRecords
{
    protected static string $resource = GridStationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
