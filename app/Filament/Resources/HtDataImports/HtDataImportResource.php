<?php

namespace App\Filament\Resources\HtDataImports;

use App\Filament\Resources\HtDataImports\Pages\CreateHtDataImport;
use App\Filament\Resources\HtDataImports\Pages\ListHtDataImports;
use App\Filament\Resources\HtDataImports\Pages\ViewHtDataImport;
use App\Filament\Resources\HtDataImports\Schemas\HtDataImportForm;
use App\Filament\Resources\HtDataImports\Schemas\HtDataImportInfolist;
use App\Filament\Resources\HtDataImports\Tables\HtDataImportsTable;
use App\Models\HtDataImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class HtDataImportResource extends Resource
{
    protected static ?string $model = HtDataImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return HtDataImportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return HtDataImportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HtDataImportsTable::configure($table);
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
            'index' => ListHtDataImports::route('/'),
            'create' => CreateHtDataImport::route('/create'),
            'view' => ViewHtDataImport::route('/{record}'),
        ];
    }
}
