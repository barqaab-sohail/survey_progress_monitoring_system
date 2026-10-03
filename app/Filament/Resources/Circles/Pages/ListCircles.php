<?php

namespace App\Filament\Resources\Circles\Pages;

use App\Filament\Resources\Circles\CircleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCircles extends ListRecords
{
    protected static string $resource = CircleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
