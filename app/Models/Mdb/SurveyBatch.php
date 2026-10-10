<?php

namespace App\Models\Mdb;

use App\Models\Feeder;
use App\Models\Project;
use App\Models\SurveyTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SurveyBatch extends Model
{
    protected $table = 'mdb_workflow_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['survey_date' => 'date', 'revision' => 'integer', 'staged_workflow' => 'boolean', 'entry_actor_ids' => 'array', 'entry_completed_at' => 'datetime', 'survey_verified_at' => 'datetime'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function feeder()
    {
        return $this->belongsTo(Feeder::class);
    }

    public function surveyTeam()
    {
        return $this->belongsTo(SurveyTeam::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function entryOperator()
    {
        return $this->belongsTo(User::class, 'entry_operator_id');
    }

    public function surveyVerifier()
    {
        return $this->belongsTo(User::class, 'survey_verifier_id');
    }

    public function sources()
    {
        return $this->hasMany(SourceFile::class, 'batch_id');
    }

    public function waypoints()
    {
        return $this->hasMany(Waypoint::class, 'batch_id');
    }

    public function transformers()
    {
        return $this->hasMany(NetworkTransformer::class, 'batch_id');
    }

    public function revisions()
    {
        return $this->hasMany(Revision::class, 'batch_id');
    }

    public function decisions()
    {
        return $this->hasMany(VerificationDecision::class, 'batch_id');
    }

    public function exports()
    {
        return $this->hasMany(ExportJob::class, 'batch_id');
    }

    public function approvedRevision()
    {
        return $this->belongsTo(Revision::class, 'approved_revision_id');
    }

    public function configuration()
    {
        return $this->hasOne(Configuration::class, 'project_id', 'project_id');
    }
}
