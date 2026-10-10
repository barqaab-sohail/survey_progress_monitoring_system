<?php

namespace App\Models\SurveyProgress;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    protected $table = 'survey_progress_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
