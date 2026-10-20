<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class EngineeringPreset extends Model
{
    protected $table = 'mdb_engineering_presets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'evidence' => 'array', 'enabled' => 'boolean', 'revision' => 'integer'];
    }
}
