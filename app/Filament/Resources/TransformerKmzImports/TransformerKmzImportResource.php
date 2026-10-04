<?php

namespace App\Filament\Resources\TransformerKmzImports;

use App\Filament\Resources\TransformerKmzImports\Pages\ListTransformerKmzImports;
use App\Filament\Resources\TransformerKmzImports\Pages\ViewTransformerKmzImport;
use App\Models\TransformerKmzImport;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransformerKmzImportResource extends Resource
{
    protected static ?string $model = TransformerKmzImport::class;

    protected static ?string $navigationLabel = 'Transformer KMZ Imports';

    protected static ?string $modelLabel = 'transformer KMZ import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('feeder.feeder_code')->label('Linked feeder code'),
            TextEntry::make('feeder.feeder_name')->label('Linked feeder name'),
            TextEntry::make('source_feeder_name')->label('KMZ source feeder')->placeholder('-'),
            TextEntry::make('source_substation_name')->label('KMZ source substation')->placeholder('-'),
            TextEntry::make('importer.name')->label('Imported by')->placeholder('-'),
            TextEntry::make('file_name'),
            TextEntry::make('file_sha256')->label('SHA-256')->placeholder('-')->columnSpanFull(),
            TextEntry::make('status')->badge(),
            TextEntry::make('total_placemarks')->numeric(),
            TextEntry::make('point_placemarks')->numeric(),
            TextEntry::make('transformer_count')->numeric(),
            TextEntry::make('created_rows')->numeric(),
            TextEntry::make('updated_rows')->numeric(),
            TextEntry::make('removed_rows')->numeric(),
            TextEntry::make('errors')
                ->formatStateUsing(fn ($state): string => $state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '-')
                ->columnSpanFull(),
            TextEntry::make('imported_at')->dateTime()->placeholder('-'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('feeder.feeder_code')->label('Linked feeder')->searchable()->sortable(),
                TextColumn::make('source_feeder_name')->label('KMZ feeder')->searchable()->placeholder('-'),
                TextColumn::make('file_name')->searchable()->limit(35),
                TextColumn::make('status')->badge(),
                TextColumn::make('transformer_count')->label('Transformers')->numeric()->sortable(),
                TextColumn::make('created_rows')->label('New')->numeric(),
                TextColumn::make('updated_rows')->label('Updated')->numeric(),
                TextColumn::make('removed_rows')->label('Removed')->numeric(),
                TextColumn::make('importer.name')->label('Imported by')->placeholder('-'),
                TextColumn::make('imported_at')->dateTime()->sortable(),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransformerKmzImports::route('/'),
            'view' => ViewTransformerKmzImport::route('/{record}'),
        ];
    }
}
