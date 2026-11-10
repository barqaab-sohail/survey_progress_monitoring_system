<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransformerCapacityObservation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['evidence_path', 'evidence_sha256'];

    protected function casts(): array
    {
        return ['capacity_kva' => 'decimal:2', 'baseline_revision' => 'integer', 'baseline_capacity_revision' => 'integer', 'source_survey_revision' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function transformer() { return $this->belongsTo(Transformer::class); }

    public function survey() { return $this->belongsTo(FieldSurvey::class, 'field_survey_id'); }

    public function observer() { return $this->belongsTo(User::class, 'observed_by'); }

    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
