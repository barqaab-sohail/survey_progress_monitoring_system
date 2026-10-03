<?php

namespace App\Models;

use App\Enums\SurveyItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyDailyEntryItem extends Model
{
    protected $fillable = ['survey_daily_entry_id', 'feeder_id', 'transformers_surveyed', 'drive_url', 'remarks', 'status', 'verified_by', 'verified_at', 'return_reason', 'resubmitted_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'resubmitted_at' => 'datetime', 'status' => SurveyItemStatus::class];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SurveyDailyEntry::class, 'survey_daily_entry_id');
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(SurveyVerificationHistory::class);
    }
}
