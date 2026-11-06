<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileGpxLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_ids' => 'array', 'mapping' => 'array', 'report' => 'array', 'snapshot' => 'array'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(FieldSurvey::class, 'field_survey_id');
    }
}
