<?php

namespace App\Filament\Resources\HtDataImports\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class HtDataImportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('project_id')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                FileUpload::make('stored_path')
                    ->label('HAZECO HT feeder workbook')
                    ->disk('local')
                    ->directory('ht-imports')
                    ->preserveFilenames()
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->maxSize(10240)
                    ->required()
                    ->helperText('Required sheet: 27_Feeders. Existing feeder codes are updated; new codes are created.'),
            ]);
    }
}
