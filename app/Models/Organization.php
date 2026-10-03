<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'type', 'contact_person', 'phone', 'email', 'address', 'status'];

    protected function casts(): array
    {
        return ['type' => OrganizationType::class, 'status' => RecordStatus::class];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function processingTeams(): HasMany
    {
        return $this->hasMany(ProcessingTeam::class);
    }

    public function processingAssignments(): HasMany
    {
        return $this->hasMany(MdbProcessingAssignment::class);
    }
}
