<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class EntryCorrection extends Model
{
    protected $table = 'mdb_workflow_entry_corrections';

    protected $guarded = ['id'];

    protected $hidden = ['screenshot_path', 'screenshot_sha256'];

    protected $appends = ['screenshot_url'];

    public function getScreenshotUrlAttribute(): ?string
    {
        return $this->screenshot_path ? route('mdb-workflow.entry-corrections.screenshot', [$this->batch_id, $this->id]) : null;
    }

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'batch_id' => 'integer', 'transformer_id' => 'integer',
            'reported_by' => 'integer', 'resolved_by' => 'integer', 'source_file_id' => 'integer', 'source_page' => 'integer'];
    }

    public function batch()
    {
        return $this->belongsTo(SurveyBatch::class, 'batch_id');
    }
}
