<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdbVerificationHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['mdb_daily_entry_item_id', 'action', 'comment', 'acted_by', 'quantity_snapshot', 'acted_at'];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MdbDailyEntryItem::class, 'mdb_daily_entry_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
