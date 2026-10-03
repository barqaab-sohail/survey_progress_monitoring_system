<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feeder extends Model
{
    protected $fillable = [
        'project_id', 'circle_id', 'division_id', 'sub_division_id', 'grid_station_id',
        'feeder_code', 'feeder_name', 'source_serial', 'source_feeder_code', 'load_kw',
        'number_of_consumers', 'nature', 'total_transformers', 'baseline_pending', 'demo_baseline',
        'source_file', 'source_sheet', 'imported_at', 'survey_drive_url', 'mdb_drive_url',
        'processing_drive_url', 'processing_required', 'status',
    ];

    protected function casts(): array
    {
        return [
            'processing_required' => 'boolean',
            'baseline_pending' => 'boolean',
            'demo_baseline' => 'boolean',
            'load_kw' => 'decimal:2',
            'imported_at' => 'datetime',
            'status' => RecordStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function subDivision(): BelongsTo
    {
        return $this->belongsTo(SubDivision::class);
    }

    public function gridStation(): BelongsTo
    {
        return $this->belongsTo(GridStation::class);
    }

    public function surveyItems(): HasMany
    {
        return $this->hasMany(SurveyDailyEntryItem::class);
    }

    public function mdbItems(): HasMany
    {
        return $this->hasMany(MdbDailyEntryItem::class);
    }

    public function processingAssignments(): HasMany
    {
        return $this->hasMany(MdbProcessingAssignment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FeederAssignment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }
}
