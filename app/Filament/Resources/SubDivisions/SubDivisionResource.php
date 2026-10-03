<?php

namespace App\Filament\Resources\SubDivisions;

use App\Filament\Resources\SubDivisions\Pages\CreateSubDivision;
use App\Filament\Resources\SubDivisions\Pages\EditSubDivision;
use App\Filament\Resources\SubDivisions\Pages\ListSubDivisions;
use App\Filament\Resources\SubDivisions\Pages\ViewSubDivision;
use App\Filament\Resources\SubDivisions\Schemas\SubDivisionForm;
use App\Filament\Resources\SubDivisions\Schemas\SubDivisionInfolist;
use App\Filament\Resources\SubDivisions\Tables\SubDivisionsTable;
use App\Models\SubDivision;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SubDivisionResource extends Resource
{
    protected static ?string $model = SubDivision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return SubDivisionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SubDivisionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubDivisionsTable::configure($table);
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
            'index' => ListSubDivisions::route('/'),
            'create' => CreateSubDivision::route('/create'),
            'view' => ViewSubDivision::route('/{record}'),
            'edit' => EditSubDivision::route('/{record}/edit'),
        ];
    }
}
