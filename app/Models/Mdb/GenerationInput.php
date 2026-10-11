<?php

namespace App\Models\Mdb;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class GenerationInput extends Model
{
    protected $table = 'mdb_workflow_generation_inputs';

    protected $guarded = ['id'];

    public function batch()
    {
        return $this->belongsTo(SurveyBatch::class, 'batch_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
