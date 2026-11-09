<?php

namespace App\Models;

use App\Support\SurveyTeamGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FieldSurvey extends Model
{
    use SoftDeletes;

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

    public function htUpdate(): BelongsTo
    {
        return $this->belongsTo(HtNetworkUpdate::class, 'ht_network_update_id');
    }

    public function gpxLinks(): HasMany
    {
        return $this->hasMany(MobileGpxLink::class);
    }

    public function getSurveyNameAttribute(): string
    {
        $group = $this->reference_snapshot['group_code'] ?? collect($this->rows ?? [])->firstWhere('se', 'E')['group'] ?? SurveyTeamGroup::identifier($this->team);

        return ($group ?: '??').'_'.$this->survey_date->format('dmy').'_'.($this->transformer_code ?: 'Draft');
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
