<?php

namespace App\Models\Mdb;

use App\Casts\MdbUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    protected $table = 'mdb_workflow_exports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'readback' => 'array', 'attempts' => 'integer', 'started_at' => MdbUtcDateTime::class, 'completed_at' => MdbUtcDateTime::class, 'generated_at' => MdbUtcDateTime::class, 'superseded_at' => MdbUtcDateTime::class];
    }

    public function batch()
    {
        return $this->belongsTo(SurveyBatch::class, 'batch_id');
    }

    public function revision()
    {
        return $this->belongsTo(Revision::class, 'revision_id');
    }

    public function transformer()
    {
        return $this->belongsTo(NetworkTransformer::class, 'transformer_id');
    }

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function validations()
    {
        return $this->hasMany(ModelValidation::class, 'export_job_id');
    }
}
