<?php

namespace App\Models\Mdb;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ModelValidation extends Model
{
    protected $table = 'mdb_workflow_model_validations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    public function exportJob()
    {
        return $this->belongsTo(ExportJob::class, 'export_job_id');
    }

    public function analyst()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
