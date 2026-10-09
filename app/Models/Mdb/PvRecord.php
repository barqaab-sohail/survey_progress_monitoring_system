<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class PvRecord extends Model
{
    protected $table = 'mdb_workflow_pv_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['installed_capacity_kw' => 'float', 'service_load_kw' => 'float', 'original_entry' => 'array'];
    }

    public function transformer()
    {
        return $this->belongsTo(NetworkTransformer::class, 'transformer_id');
    }

    public function section()
    {
        return $this->belongsTo(NetworkSection::class, 'section_id');
    }
}
