<?php

namespace App\Filament\Resources\Divisions\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DivisionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('project_id')
                    ->relationship('project', 'name')
                    ->required(),
                Select::make('circle_id')
                    ->relationship('circle', 'name')
                    ->required(),
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
            ]);
    }
}
