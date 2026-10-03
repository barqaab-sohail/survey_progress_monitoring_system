<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdbDailyEntryItem extends Model
{
    protected $fillable = ['mdb_daily_entry_id', 'feeder_id', 'mdb_files_created', 'drive_url', 'remarks'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(MdbDailyEntry::class, 'mdb_daily_entry_id');
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }
}
