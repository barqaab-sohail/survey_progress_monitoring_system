<?php

namespace App\Models\Mdb;

use App\Casts\MdbUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class Waypoint extends Model
{
    protected $table = 'mdb_workflow_waypoints';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'elevation' => 'float', 'recorded_at' => MdbUtcDateTime::class, 'original_entry' => 'array', 'correction' => 'array', 'correction_approved_at' => MdbUtcDateTime::class];
    }

    public function batch()
    {
        return $this->belongsTo(SurveyBatch::class, 'batch_id');
    }

    public function sourceFile()
    {
        return $this->belongsTo(SourceFile::class, 'source_file_id');
    }
}
