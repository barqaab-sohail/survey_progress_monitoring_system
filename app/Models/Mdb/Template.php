<?php

namespace App\Models\Mdb;

use App\Casts\MdbUtcDateTime;
use App\Services\Mdb\SnapshotService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Template extends Model
{
    protected $table = 'mdb_workflow_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'active' => 'boolean', 'approved_at' => MdbUtcDateTime::class];
    }

    public function exports()
    {
        return $this->hasMany(ExportJob::class, 'template_id');
    }

    protected static function booted(): void
    {
        static::saved(function (self $template): void {
            $isApproved = $template->active && $template->approved_by && $template->approved_at;
            $wasApproved = $template->getOriginal('active') && $template->getOriginal('approved_by') && $template->getOriginal('approved_at');
            if (($isApproved || $wasApproved) && ($template->wasRecentlyCreated || $template->wasChanged(['active', 'approved_by', 'approved_at', 'sha256', 'metadata', 'path', 'disk', 'version', 'synergee_version']))) {
                DB::transaction(function () {
                    foreach (SurveyBatch::query()->lockForUpdate()->get() as $batch) {
                        app(SnapshotService::class)->invalidate($batch);
                        $batch->increment('revision');
                    }
                });
            }
        });
    }
}
