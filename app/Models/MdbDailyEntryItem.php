<?php

namespace App\Models;

use App\Enums\SurveyItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdbDailyEntryItem extends Model
{
    protected $attributes = ['status' => 'submitted'];

    protected $fillable = ['mdb_daily_entry_id', 'feeder_id', 'mdb_files_created', 'drive_url', 'remarks', 'status', 'verified_by', 'verified_at', 'return_reason', 'resubmitted_at'];

    protected function casts(): array
    {
        return ['status' => SurveyItemStatus::class, 'verified_at' => 'datetime', 'resubmitted_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(MdbDailyEntry::class, 'mdb_daily_entry_id');
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
        return $this->hasMany(MdbVerificationHistory::class);
    }
}
