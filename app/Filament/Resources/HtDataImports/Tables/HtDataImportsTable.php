<?php

namespace App\Filament\Resources\HtDataImports\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HtDataImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.name')
                    ->searchable(),
                TextColumn::make('importer.name')
                    ->label('Imported by'),
                TextColumn::make('file_name')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('total_rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rejected_rows')
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('imported_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
