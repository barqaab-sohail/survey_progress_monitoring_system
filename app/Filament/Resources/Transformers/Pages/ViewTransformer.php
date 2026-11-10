<?php

namespace App\Filament\Resources\Transformers\Pages;

use App\Filament\Resources\Transformers\TransformerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTransformer extends ViewRecord
{
    protected static string $resource = TransformerResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('verification')->label('Reference verification / corrections')
            ->url(fn () => route('transformers.show', $this->record))];
    }
}
