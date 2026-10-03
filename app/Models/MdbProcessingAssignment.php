<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdbProcessingAssignment extends Model
{
    protected $fillable = ['feeder_id', 'organization_id', 'processing_team_id', 'assigned_quantity', 'assignment_date', 'target_date', 'drive_url', 'remarks', 'status', 'assigned_by'];

    protected function casts(): array
    {
        return ['assignment_date' => 'date', 'target_date' => 'date', 'status' => AssignmentStatus::class];
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function processingTeam(): BelongsTo
    {
        return $this->belongsTo(ProcessingTeam::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function progressItems(): HasMany
    {
        return $this->hasMany(MdbProcessingDailyEntryItem::class);
    }

    public function getProcessedQuantityAttribute(): int
    {
        return (int) $this->progressItems()->sum('mdb_processed');
    }

    public function getRemainingQuantityAttribute(): int
    {
        return max($this->assigned_quantity - $this->processed_quantity, 0);
    }
}
