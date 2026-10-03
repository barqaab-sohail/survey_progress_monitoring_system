<?php

namespace App\Filament\Resources\GridStations\Pages;

use App\Filament\Resources\GridStations\GridStationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditGridStation extends EditRecord
{
    protected static string $resource = GridStationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
