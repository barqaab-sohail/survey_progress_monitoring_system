<?php

namespace App\Models;

use App\Enums\SurveyEntryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyDailyEntry extends Model
{
    protected $fillable = ['entry_date', 'survey_team_id', 'entered_by', 'status', 'remarks', 'submitted_at'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'submitted_at' => 'datetime', 'status' => SurveyEntryStatus::class];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(SurveyTeam::class, 'survey_team_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SurveyDailyEntryItem::class);
    }
}
