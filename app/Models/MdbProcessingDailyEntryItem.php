<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdbProcessingDailyEntryItem extends Model
{
    protected $fillable = ['mdb_processing_daily_entry_id', 'mdb_processing_assignment_id', 'mdb_processed', 'output_drive_url', 'remarks'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(MdbProcessingDailyEntry::class, 'mdb_processing_daily_entry_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MdbProcessingAssignment::class, 'mdb_processing_assignment_id');
    }
}
