<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdbProcessingDailyEntry extends Model
{
    protected $fillable = ['entry_date', 'organization_id', 'entered_by', 'remarks'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MdbProcessingDailyEntryItem::class);
    }
}
