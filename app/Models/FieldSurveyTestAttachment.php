<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldSurveyTestAttachment extends Model
{
    protected $guarded = ['id'];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(FieldSurveyTest::class, 'field_survey_test_id');
    }
}
