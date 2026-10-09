<?php

namespace App\Models\Mdb;

use App\Casts\MdbUtcDateTime;
use App\Models\Project;
use App\Services\Mdb\SnapshotService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Configuration extends Model
{
    protected $table = 'mdb_workflow_configurations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['epsg' => 'integer', 'settings' => 'array', 'load_assumptions' => 'array', 'revision' => 'integer', 'approved_at' => MdbUtcDateTime::class];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    protected static function booted(): void
    {
        static::saved(function (self $configuration) {
            if ($configuration->wasRecentlyCreated || $configuration->wasChanged(['epsg', 'settings', 'load_assumptions', 'approved_by', 'approved_at'])) {
                DB::transaction(function () use ($configuration) {
                    foreach (SurveyBatch::where('project_id', $configuration->project_id)->lockForUpdate()->get() as $batch) {
                        app(SnapshotService::class)->invalidate($batch);
                        $batch->increment('revision');
                    }
                });
            }
        });
    }
}
