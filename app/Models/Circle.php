<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Circle extends Model
{
    protected $fillable = ['project_id', 'code', 'name'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class);
    }
}
