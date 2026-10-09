<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('profile_photo_path')
                    ->label('Profile picture')
                    ->avatar()
                    ->disk('public')
                    ->directory('user-photos')
                    ->preventFilePathTampering()
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2048)
                    ->rules(['dimensions:max_width=4096,max_height=4096'])
                    ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                    ->dehydrated(fn () => auth()->user()?->hasRole('super_admin'))
                    ->helperText('JPG, PNG or WebP; up to 2 MB and 4096 × 4096 pixels.'),
                Select::make('organization_id')
                    ->relationship('organization', 'name', modifyQueryUsing: fn (Builder $query, Get $get) => $query->when($get('role') === UserRole::MdbProcessingUser->value, fn (Builder $query) => $query->where('type', 'third_party')->where('status', 'active')))
                    ->required(fn (Get $get) => $get('role') === UserRole::MdbProcessingUser->value)
                    ->rules(fn (Get $get) => $get('role') === UserRole::MdbProcessingUser->value ? [Rule::exists('organizations', 'id')->where('type', 'third_party')->where('status', 'active')->whereNull('deleted_at')] : [])
                    ->helperText('Third-Party Processor accounts require an active third-party organization.')
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
                    ->live()
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
