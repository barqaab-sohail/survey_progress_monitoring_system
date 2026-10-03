<?php

namespace App\Filament\Resources\Circles;

use App\Filament\Resources\Circles\Pages\CreateCircle;
use App\Filament\Resources\Circles\Pages\EditCircle;
use App\Filament\Resources\Circles\Pages\ListCircles;
use App\Filament\Resources\Circles\Pages\ViewCircle;
use App\Filament\Resources\Circles\Schemas\CircleForm;
use App\Filament\Resources\Circles\Schemas\CircleInfolist;
use App\Filament\Resources\Circles\Tables\CirclesTable;
use App\Models\Circle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CircleResource extends Resource
{
    protected static ?string $model = Circle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CircleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CircleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CirclesTable::configure($table);
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
            'index' => ListCircles::route('/'),
            'create' => CreateCircle::route('/create'),
            'view' => ViewCircle::route('/{record}'),
            'edit' => EditCircle::route('/{record}/edit'),
        ];
    }
}
