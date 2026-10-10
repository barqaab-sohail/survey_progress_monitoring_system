<?php

namespace App\Models\SurveyProgress;

use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    protected $table = 'survey_progress_connections';

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted:array'];
    }
}
