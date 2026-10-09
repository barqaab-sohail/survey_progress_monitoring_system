<?php

namespace App\Models\Mdb;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class VerificationDecision extends Model
{
    protected $table = 'mdb_workflow_decisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['validation' => 'array'];
    }

    public function batch()
    {
        return $this->belongsTo(SurveyBatch::class, 'batch_id');
    }

    public function revision()
    {
        return $this->belongsTo(Revision::class, 'revision_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
