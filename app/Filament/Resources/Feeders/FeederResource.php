<?php

namespace App\Filament\Resources\Feeders;

use App\Filament\Resources\Feeders\Pages\CreateFeeder;
use App\Filament\Resources\Feeders\Pages\EditFeeder;
use App\Filament\Resources\Feeders\Pages\ListFeeders;
use App\Filament\Resources\Feeders\Pages\ViewFeeder;
use App\Filament\Resources\Feeders\Schemas\FeederForm;
use App\Filament\Resources\Feeders\Schemas\FeederInfolist;
use App\Filament\Resources\Feeders\Tables\FeedersTable;
use App\Models\Feeder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FeederResource extends Resource
{
    protected static ?string $model = Feeder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return FeederForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FeederInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeedersTable::configure($table);
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
            'index' => ListFeeders::route('/'),
            'create' => CreateFeeder::route('/create'),
            'view' => ViewFeeder::route('/{record}'),
            'edit' => EditFeeder::route('/{record}/edit'),
        ];
    }
}
