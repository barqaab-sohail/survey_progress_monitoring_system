<?php

namespace App\Models\SurveyProgress;

use App\Models\Feeder;
use Illuminate\Database\Eloquent\Model;

class ProgressFeeder extends Model
{
    protected $table = 'survey_progress_feeders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issues' => 'array', 'aggregates' => 'array', 'length_issues' => 'array', 'mapping_manual' => 'boolean', 'synced_at' => 'datetime', 'calculated_at' => 'datetime'];
    }

    public function feeder()
    {
        return $this->belongsTo(Feeder::class);
    }

    public function files()
    {
        return $this->hasMany(SourceFile::class, 'progress_feeder_id');
    }

    public function runs()
    {
        return $this->hasMany(Run::class, 'progress_feeder_id');
    }
}
