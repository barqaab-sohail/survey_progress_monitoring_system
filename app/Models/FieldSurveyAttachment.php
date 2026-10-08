<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldSurveyAttachment extends Model
{
    protected $fillable = ['client_uuid', 'field_survey_id', 'kind', 'path', 'mime_type', 'byte_length', 'sha256'];

    protected $hidden = ['path', 'sha256'];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(FieldSurvey::class, 'field_survey_id');
    }
}
