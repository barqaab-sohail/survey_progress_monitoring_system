<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldSurvey extends Model
{
    protected $fillable = ['client_uuid', 'collected_by', 'survey_team_id', 'feeder_id', 'transformer_id', 'transformer_code', 'survey_date', 'status', 'revision', 'payload_hash', 'header', 'rows', 'solar', 'reference_snapshot', 'remarks', 'submitted_at'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['survey_date' => 'date', 'submitted_at' => 'datetime', 'header' => 'array', 'rows' => 'array', 'solar' => 'array', 'reference_snapshot' => 'array'];
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(SurveyTeam::class, 'survey_team_id');
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FieldSurveyAttachment::class);
    }
}
