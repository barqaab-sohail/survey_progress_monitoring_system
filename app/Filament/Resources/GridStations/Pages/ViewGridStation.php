<?php

namespace App\Filament\Resources\GridStations\Pages;

use App\Filament\Resources\GridStations\GridStationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGridStation extends ViewRecord
{
    protected static string $resource = GridStationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
