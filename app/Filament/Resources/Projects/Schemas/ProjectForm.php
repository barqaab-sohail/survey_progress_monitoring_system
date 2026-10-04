<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\RecordStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('timezone')
                    ->required()
                    ->default('Asia/Karachi'),
                Select::make('status')
                    ->options(RecordStatus::class)
                    ->default('active')
                    ->required(),
            ]);
    }
}
