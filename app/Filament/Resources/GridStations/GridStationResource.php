<?php

namespace App\Filament\Resources\GridStations;

use App\Filament\Resources\GridStations\Pages\CreateGridStation;
use App\Filament\Resources\GridStations\Pages\EditGridStation;
use App\Filament\Resources\GridStations\Pages\ListGridStations;
use App\Filament\Resources\GridStations\Pages\ViewGridStation;
use App\Filament\Resources\GridStations\Schemas\GridStationForm;
use App\Filament\Resources\GridStations\Schemas\GridStationInfolist;
use App\Filament\Resources\GridStations\Tables\GridStationsTable;
use App\Models\GridStation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GridStationResource extends Resource
{
    protected static ?string $model = GridStation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return GridStationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GridStationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GridStationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGridStations::route('/'),
            'create' => CreateGridStation::route('/create'),
            'view' => ViewGridStation::route('/{record}'),
            'edit' => EditGridStation::route('/{record}/edit'),
        ];
    }
}
