<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Division extends Model
{
    protected $fillable = ['project_id', 'circle_id', 'code', 'name'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function subDivisions(): HasMany
    {
        return $this->hasMany(SubDivision::class);
    }
}
