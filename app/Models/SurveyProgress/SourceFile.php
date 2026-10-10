<?php

namespace App\Models\SurveyProgress;

use Illuminate\Database\Eloquent\Model;

class SourceFile extends Model
{
    protected $table = 'survey_progress_files';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'active' => 'boolean'];
    }
}
