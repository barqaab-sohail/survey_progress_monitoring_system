<?php

namespace App\Filament\Resources\Feeders\Pages;

use App\Filament\Resources\Feeders\FeederResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFeeder extends ViewRecord
{
    protected static string $resource = FeederResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
