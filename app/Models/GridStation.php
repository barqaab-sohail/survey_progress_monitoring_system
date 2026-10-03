<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GridStation extends Model
{
    protected $fillable = ['project_id', 'sub_division_id', 'code', 'name'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function subDivision(): BelongsTo
    {
        return $this->belongsTo(SubDivision::class);
    }

    public function feeders(): HasMany
    {
        return $this->hasMany(Feeder::class);
    }
}
