<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class Consumer extends Model
{
    protected $table = 'mdb_workflow_consumers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['count' => 'integer', 'demand' => 'array'];
    }

    public function section()
    {
        return $this->belongsTo(NetworkSection::class, 'section_id');
    }
}
