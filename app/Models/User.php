<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'organization_id',
        'role',
        'status',
        'password',
        'profile_photo_path',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_drive_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'google_drive_token' => 'encrypted:array',
            'role' => UserRole::class,
            'status' => RecordStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function surveyTeams(): BelongsToMany
    {
        return $this->belongsToMany(SurveyTeam::class, 'survey_team_members')->withPivot('is_leader')->withTimestamps();
    }

    public function mdbTeams(): BelongsToMany
    {
        return $this->belongsToMany(MdbTeam::class, 'mdb_team_members')->withTimestamps();
    }

    public function processingTeams(): BelongsToMany
    {
        return $this->belongsToMany(ProcessingTeam::class, 'processing_team_members')->withTimestamps();
    }

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('profile_photo_path') && $old = $user->getRawOriginal('profile_photo_path')) {
                Storage::disk('public')->delete($old);
            }
        });
        static::deleted(function (User $user): void {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }
        });
        static::saved(function (User $user): void {
            if ($user->role && ($user->wasRecentlyCreated || $user->wasChanged('role')) && Schema::hasTable(config('permission.table_names.roles', 'roles'))) {
                $role = Role::findOrCreate($user->role->value, $user->getDefaultGuardName());
                $primaryRoles = array_map(fn (UserRole $item) => $item->value, UserRole::cases());
                $additionalRoles = $user->roles()->whereNotIn('name', $primaryRoles)->pluck('name')->all();
                $user->syncRoles(array_merge([$role->name], $additionalRoles));
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->hasAnyRole([UserRole::SuperAdmin->value, UserRole::ProjectManager->value])
            || $this->can('Access:AdminPanel');
    }

    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo_path ? asset('storage/'.$this->profile_photo_path) : null;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->profile_photo_url;
    }
}
