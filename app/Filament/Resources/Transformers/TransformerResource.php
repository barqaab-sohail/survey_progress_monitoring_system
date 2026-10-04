<?php

namespace App\Filament\Resources\Transformers;

use App\Filament\Resources\Transformers\Pages\ListTransformers;
use App\Filament\Resources\Transformers\Pages\ViewTransformer;
use App\Models\Transformer;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransformerResource extends Resource
{
    protected static ?string $model = Transformer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('feeder.feeder_code')->label('Linked feeder'),
            TextEntry::make('feeder.feeder_name')->label('Feeder name'),
            TextEntry::make('transformer_code'),
            TextEntry::make('capacity_kva')->label('Capacity')->suffix(' kVA'),
            TextEntry::make('gps_waypoint_number')->label('GPS waypoint')->placeholder('-'),
            TextEntry::make('source_feeder_name')->label('KMZ feeder'),
            TextEntry::make('source_substation_name')->label('KMZ substation')->placeholder('-'),
            TextEntry::make('equipment_status')->label('Status')->placeholder('-'),
            TextEntry::make('equipment_make')->label('Make')->placeholder('-'),
            TextEntry::make('equipment_location')->label('Location')->placeholder('-'),
            TextEntry::make('equipment_mounting')->label('Mounting')->placeholder('-'),
            TextEntry::make('pole_number')->placeholder('-'),
            TextEntry::make('pole_type')->placeholder('-'),
            TextEntry::make('pole_phase')->placeholder('-'),
            TextEntry::make('longitude'),
            TextEntry::make('latitude'),
            TextEntry::make('remarks')->placeholder('-')->columnSpanFull(),
            TextEntry::make('raw_attributes')
                ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('feeder.feeder_code')->label('Feeder')->searchable()->sortable(),
                TextColumn::make('transformer_code')->searchable()->sortable(),
                TextColumn::make('capacity_kva')->label('kVA')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('gps_waypoint_number')->label('GPS WP')->searchable()->placeholder('-'),
                TextColumn::make('equipment_status')->badge()->placeholder('-'),
                TextColumn::make('equipment_location')->searchable()->placeholder('-')->limit(30),
                TextColumn::make('source_feeder_name')->label('KMZ feeder')->searchable(),
                TextColumn::make('updated_at')->label('Imported')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('feeder_id')->relationship('feeder', 'feeder_code')->searchable()->preload(),
                SelectFilter::make('capacity_kva')->label('Capacity (kVA)')->options(fn (): array => Transformer::query()->distinct()->orderBy('capacity_kva')->pluck('capacity_kva', 'capacity_kva')->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('transformer_code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransformers::route('/'),
            'view' => ViewTransformer::route('/{record}'),
        ];
    }
}
