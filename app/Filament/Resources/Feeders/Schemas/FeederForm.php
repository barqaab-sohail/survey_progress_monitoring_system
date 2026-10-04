<?php

namespace App\Filament\Resources\Feeders\Schemas;

use App\Enums\RecordStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FeederForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('project_id')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('circle_id')
                    ->relationship('circle', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('division_id')
                    ->relationship('division', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('sub_division_id')
                    ->relationship('subDivision', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('grid_station_id')
                    ->relationship('gridStation', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('feeder_code')
                    ->required(),
                TextInput::make('feeder_name')
                    ->required(),
                TextInput::make('source_serial')
                    ->numeric()
                    ->default(null),
                TextInput::make('source_feeder_code')
                    ->default(null),
                TextInput::make('load_kw')
                    ->numeric()
                    ->default(null),
                TextInput::make('number_of_consumers')
                    ->numeric()
                    ->default(null),
                TextInput::make('nature')
                    ->default(null),
                TextInput::make('total_transformers')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Enter the verified transformer baseline; use 0 only while the baseline is pending.'),
                Toggle::make('baseline_pending')
                    ->default(true)
                    ->helperText('Turn off after the transformer baseline has been verified.'),
                Toggle::make('demo_baseline')
                    ->label('Sample dashboard baseline')
                    ->helperText('Turn off when this feeder receives its verified live baseline.'),
                TextInput::make('source_file')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('source_sheet')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('imported_at')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('survey_drive_url')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('mdb_drive_url')
                    ->default(null)
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(RecordStatus::class)
                    ->default('active')
                    ->required(),
            ]);
    }
}
