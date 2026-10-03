<?php

namespace App\Filament\Resources\HtDataImports\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class HtDataImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('project.name')
                    ->label('Project'),
                TextEntry::make('importer.name')
                    ->label('Imported by')
                    ->placeholder('-'),
                TextEntry::make('file_name'),
                TextEntry::make('stored_path')
                    ->placeholder('-'),
                TextEntry::make('file_sha256')
                    ->placeholder('-'),
                TextEntry::make('sheet_name'),
                TextEntry::make('status'),
                TextEntry::make('total_rows')
                    ->numeric(),
                TextEntry::make('created_rows')
                    ->numeric(),
                TextEntry::make('updated_rows')
                    ->numeric(),
                TextEntry::make('rejected_rows')
                    ->numeric(),
                TextEntry::make('errors')
                    ->formatStateUsing(fn ($state): string => $state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '-')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('imported_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
