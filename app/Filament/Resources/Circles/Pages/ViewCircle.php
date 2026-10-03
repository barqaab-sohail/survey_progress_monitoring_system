<?php

namespace App\Filament\Resources\Circles\Pages;

use App\Filament\Resources\Circles\CircleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCircle extends ViewRecord
{
    protected static string $resource = CircleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
