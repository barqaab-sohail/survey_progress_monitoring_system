<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class SourceFile extends Model
{
    protected $table = 'mdb_workflow_sources';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'bytes' => 'integer', 'version' => 'integer'];
    }

    public function batch()
    {
        return $this->belongsTo(SurveyBatch::class, 'batch_id');
    }

    public function parentSource()
    {
        return $this->belongsTo(self::class, 'parent_source_id');
    }

    public function versions()
    {
        return $this->hasMany(self::class, 'parent_source_id');
    }

    public function waypoints()
    {
        return $this->hasMany(Waypoint::class, 'source_file_id');
    }
}
