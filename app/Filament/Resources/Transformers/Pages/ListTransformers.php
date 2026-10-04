<?php

namespace App\Filament\Resources\Transformers\Pages;

use App\Filament\Resources\Transformers\TransformerResource;
use Filament\Resources\Pages\ListRecords;

class ListTransformers extends ListRecords
{
    protected static string $resource = TransformerResource::class;
}
