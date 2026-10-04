<?php

namespace App\Filament\Resources\Feeders\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeedersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.name')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('circle.name')
                    ->searchable(),
                TextColumn::make('division.name')
                    ->searchable(),
                TextColumn::make('subDivision.name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gridStation.name')
                    ->searchable(),
                TextColumn::make('feeder_code')
                    ->searchable(),
                TextColumn::make('feeder_name')
                    ->searchable(),
                TextColumn::make('source_serial')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_feeder_code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('load_kw')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('number_of_consumers')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('nature')
                    ->searchable(),
                TextColumn::make('total_transformers')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('baseline_pending')
                    ->boolean(),
                IconColumn::make('demo_baseline')
                    ->label('Sample')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_file')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_sheet')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('imported_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
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
                SelectFilter::make('circle_id')->relationship('circle', 'name')->searchable()->preload(),
                SelectFilter::make('division_id')->relationship('division', 'name')->searchable()->preload(),
                SelectFilter::make('grid_station_id')->relationship('gridStation', 'name')->searchable()->preload(),
                SelectFilter::make('baseline_pending')->options(['1' => 'Pending', '0' => 'Verified']),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('source_serial');
    }
}
