<?php

namespace App\Models\SurveyProgress;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Transcription extends Model
{
    protected $table = 'survey_progress_transcriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'reviewed_at' => 'datetime', 'version' => 'integer'];
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
