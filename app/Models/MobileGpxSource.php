<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileGpxSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['survey_date' => 'date', 'points' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(SurveyTeam::class, 'survey_team_id');
    }
}
