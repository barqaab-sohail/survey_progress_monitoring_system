<?php

namespace App\Filament\Resources\Feeders\Pages;

use App\Filament\Resources\Feeders\FeederResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFeeder extends EditRecord
{
    protected static string $resource = FeederResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
