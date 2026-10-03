<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubDivision extends Model
{
    protected $fillable = ['project_id', 'division_id', 'code', 'name'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function gridStations(): HasMany
    {
        return $this->hasMany(GridStation::class);
    }
}
