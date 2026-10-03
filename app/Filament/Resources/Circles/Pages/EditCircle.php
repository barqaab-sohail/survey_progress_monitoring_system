<?php

namespace App\Filament\Resources\Circles\Pages;

use App\Filament\Resources\Circles\CircleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCircle extends EditRecord
{
    protected static string $resource = CircleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
