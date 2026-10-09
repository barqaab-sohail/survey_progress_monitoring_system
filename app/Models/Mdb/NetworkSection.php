<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class NetworkSection extends Model
{
    protected $table = 'mdb_workflow_sections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['phases' => 'array', 'conductors' => 'array', 'geometry' => 'array', 'original_entry' => 'array', 'pole_height' => 'float', 'measured_length_m' => 'float'];
    }

    public function transformer()
    {
        return $this->belongsTo(NetworkTransformer::class, 'transformer_id');
    }

    public function startWaypoint()
    {
        return $this->belongsTo(Waypoint::class, 'start_waypoint_id');
    }

    public function endWaypoint()
    {
        return $this->belongsTo(Waypoint::class, 'end_waypoint_id');
    }

    public function sourcePdf()
    {
        return $this->belongsTo(SourceFile::class, 'source_pdf_id');
    }

    public function consumers()
    {
        return $this->hasMany(Consumer::class, 'section_id');
    }

    public function pvRecords()
    {
        return $this->hasMany(PvRecord::class, 'section_id');
    }

    public function entryRows()
    {
        return $this->hasMany(EntryRow::class, 'section_id');
    }
}
