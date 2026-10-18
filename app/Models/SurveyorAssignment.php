<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyorAssignment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['assigned_at' => 'date', 'deadline' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function team(): BelongsTo { return $this->belongsTo(SurveyTeam::class, 'survey_team_id'); }
    public function feeder(): BelongsTo { return $this->belongsTo(Feeder::class); }
    public function surveyor(): BelongsTo { return $this->belongsTo(User::class, 'surveyor_id'); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
}
