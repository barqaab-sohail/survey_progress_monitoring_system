<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class NetworkTransformer extends Model
{
    protected $table = 'mdb_workflow_transformers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['capacity_kva' => 'float', 'header' => 'array', 'original_header' => 'array'];
    }

    public function batch()
    {
        return $this->belongsTo(SurveyBatch::class, 'batch_id');
    }

    public function sourceWaypoint()
    {
        return $this->belongsTo(Waypoint::class, 'source_waypoint_id');
    }

    public function sections()
    {
        return $this->hasMany(NetworkSection::class, 'transformer_id');
    }

    public function entryRows()
    {
        return $this->hasMany(EntryRow::class, 'transformer_id')->orderBy('pair_number')->orderByRaw("CASE WHEN designation = 'S' THEN 0 ELSE 1 END");
    }

    public function consumers()
    {
        return $this->hasManyThrough(Consumer::class, NetworkSection::class, 'transformer_id', 'section_id');
    }

    public function pvRecords()
    {
        return $this->hasMany(PvRecord::class, 'transformer_id');
    }
}
