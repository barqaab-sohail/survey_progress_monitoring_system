<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransformerMdbProject extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['survey_date' => 'date', 'header' => 'array', 'rows' => 'array', 'solar' => 'array', 'export_settings' => 'array', 'gpx_waypoints' => 'array'];
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceSurvey(): BelongsTo
    {
        return $this->belongsTo(FieldSurvey::class, 'source_field_survey_id');
    }
}
