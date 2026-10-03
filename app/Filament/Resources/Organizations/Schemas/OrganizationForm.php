<?php

namespace App\Filament\Resources\Organizations\Schemas;

use App\Enums\OrganizationType;
use App\Enums\RecordStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrganizationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('type')
                    ->options(OrganizationType::class)
                    ->required(),
                TextInput::make('contact_person')
                    ->default(null),
                TextInput::make('phone')
                    ->tel()
                    ->default(null),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->default(null),
                Textarea::make('address')
                    ->default(null)
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(RecordStatus::class)
                    ->default('active')
                    ->required(),
            ]);
    }
}
