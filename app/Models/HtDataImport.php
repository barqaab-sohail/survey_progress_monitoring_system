<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HtDataImport extends Model
{
    protected $fillable = [
        'project_id', 'imported_by', 'file_name', 'stored_path', 'file_sha256',
        'sheet_name', 'status', 'total_rows', 'created_rows', 'updated_rows',
        'rejected_rows', 'errors', 'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
