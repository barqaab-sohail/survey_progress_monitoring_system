<?php

namespace App\Filament\Resources\Divisions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DivisionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('project.name')
                    ->label('Project'),
                TextEntry::make('circle.name')
                    ->label('Circle'),
                TextEntry::make('code'),
                TextEntry::make('name'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
