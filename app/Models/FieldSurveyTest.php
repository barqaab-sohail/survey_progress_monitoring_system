<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldSurveyTest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FieldSurveyTestAttachment::class);
    }
}
