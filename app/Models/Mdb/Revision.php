<?php

namespace App\Models\Mdb;

use App\Casts\MdbUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class Revision extends Model
{
    protected $table = 'mdb_workflow_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'number' => 'integer', 'frozen_at' => MdbUtcDateTime::class];
    }

    public function batch()
    {
        return $this->belongsTo(SurveyBatch::class, 'batch_id');
    }

    public function decisions()
    {
        return $this->hasMany(VerificationDecision::class, 'revision_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('MDB revisions are immutable. Create a new revision.'));
        static::deleting(fn () => throw new \LogicException('MDB revisions must be retained.'));
    }
}
