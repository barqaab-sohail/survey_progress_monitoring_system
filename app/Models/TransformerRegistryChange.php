<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransformerRegistryChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public function actor() { return $this->belongsTo(User::class, 'changed_by'); }
}
