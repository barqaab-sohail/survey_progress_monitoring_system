<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyTeam extends Model
{
    protected $fillable = ['project_id', 'code', 'name', 'status'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'survey_team_members')->withPivot('is_leader')->withTimestamps();
    }

    public function feederAssignments(): HasMany
    {
        return $this->hasMany(FeederAssignment::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SurveyDailyEntry::class);
    }
}
