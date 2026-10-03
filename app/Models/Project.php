<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['code', 'name', 'timezone', 'processing_required', 'status'];

    protected function casts(): array
    {
        return ['processing_required' => 'boolean', 'status' => RecordStatus::class];
    }

    public function circles(): HasMany
    {
        return $this->hasMany(Circle::class);
    }

    public function feeders(): HasMany
    {
        return $this->hasMany(Feeder::class);
    }

    public function surveyTeams(): HasMany
    {
        return $this->hasMany(SurveyTeam::class);
    }
}
