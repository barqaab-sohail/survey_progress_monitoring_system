<?php

namespace App\Filament\Resources\Feeders\Pages;

use App\Filament\Resources\Feeders\FeederResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeeders extends ListRecords
{
    protected static string $resource = FeederResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
