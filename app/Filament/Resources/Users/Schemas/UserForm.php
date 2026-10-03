<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('organization_id')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')
                    ->tel()
                    ->default(null),
                Select::make('role')
                    ->options(collect(UserRole::cases())->mapWithKeys(fn (UserRole $role) => [$role->value => $role->label()])->all())
                    ->default('management_viewer')
                    ->required(),
                Select::make('status')
                    ->options(collect(RecordStatus::cases())->mapWithKeys(fn (RecordStatus $status) => [$status->value => ucfirst($status->value)])->all())
                    ->default('active')
                    ->required(),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->helperText('Leave blank when editing to keep the existing password.'),
            ]);
    }
}
