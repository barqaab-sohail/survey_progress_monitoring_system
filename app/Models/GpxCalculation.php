<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpxCalculation extends Model
{
    protected $fillable = ['created_by', 'project_id', 'feeder_id', 'file_name', 'source_sha256', 'source_data', 'settings', 'result'];

    protected function casts(): array
    {
        return ['source_data' => 'array', 'settings' => 'array', 'result' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }
}
