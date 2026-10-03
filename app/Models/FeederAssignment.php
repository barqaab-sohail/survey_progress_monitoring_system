<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeederAssignment extends Model
{
    protected $fillable = ['feeder_id', 'survey_team_id', 'assigned_by', 'start_date', 'end_date', 'status', 'remarks'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }

    public function surveyTeam(): BelongsTo
    {
        return $this->belongsTo(SurveyTeam::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
