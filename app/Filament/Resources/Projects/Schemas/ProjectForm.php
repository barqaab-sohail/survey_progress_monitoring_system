<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\RecordStatus;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->label('Project name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('For project code HAZECO-TDL, this name appears on the login page, application header, browser title, and admin panel.'),
                FileUpload::make('logo_path')
                    ->label('Project logo')
                    ->disk('public')
                    ->directory('branding')
                    ->image()
                    ->imageEditor()
                    ->maxSize(5120)
                    ->helperText('PNG, JPG or WebP up to 5 MB. Leave empty to use the supplied BARQAAB logo.'),
                FileUpload::make('favicon_path')
                    ->label('Browser favicon')
                    ->disk('public')
                    ->directory('branding')
                    ->image()
                    ->imageEditor()
                    ->maxSize(2048)
                    ->helperText('Upload a square PNG, JPG or WebP up to 2 MB. Leave empty to use the supplied circular emblem.'),
                TextInput::make('timezone')
                    ->required()
                    ->default('Asia/Karachi'),
                Select::make('status')
                    ->options(RecordStatus::class)
                    ->default('active')
                    ->required(),
            ]);
    }
}
