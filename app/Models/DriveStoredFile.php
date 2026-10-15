<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriveStoredFile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'bytes' => 'integer', 'attempts' => 'integer',
            'next_attempt_at' => 'datetime', 'uploaded_at' => 'datetime', 'local_deleted_at' => 'datetime'];
    }
}
