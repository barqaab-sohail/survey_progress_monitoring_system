<?php

namespace App\Filament\Resources\Feeders\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FeederInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('project.name')
                    ->label('Project'),
                TextEntry::make('circle.name')
                    ->label('Circle'),
                TextEntry::make('division.name')
                    ->label('Division'),
                TextEntry::make('subDivision.name')
                    ->label('Sub division'),
                TextEntry::make('gridStation.name')
                    ->label('Grid station'),
                TextEntry::make('feeder_code'),
                TextEntry::make('feeder_name'),
                TextEntry::make('source_serial')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('source_feeder_code')
                    ->placeholder('-'),
                TextEntry::make('load_kw')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('number_of_consumers')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('nature')
                    ->placeholder('-'),
                TextEntry::make('total_transformers')
                    ->numeric(),
                IconEntry::make('baseline_pending')
                    ->boolean(),
                IconEntry::make('demo_baseline')
                    ->label('Sample dashboard baseline')
                    ->boolean(),
                TextEntry::make('source_file')
                    ->placeholder('-'),
                TextEntry::make('source_sheet')
                    ->placeholder('-'),
                TextEntry::make('imported_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('survey_drive_url')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('mdb_drive_url')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
