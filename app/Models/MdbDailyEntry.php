<?php

namespace App\Models;

use App\Enums\SurveyItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdbDailyEntry extends Model
{
    public function canBeEdited(): bool
    {
        return $this->items()->exists()
            && ! $this->items()->where('status', '!=', SurveyItemStatus::Submitted->value)->exists();
    }

    protected $fillable = ['entry_date', 'mdb_team_id', 'entered_by', 'remarks'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(MdbTeam::class, 'mdb_team_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MdbDailyEntryItem::class);
    }
}
