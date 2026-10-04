<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransformerKmzImport extends Model
{
    protected $fillable = [
        'feeder_id', 'imported_by', 'file_name', 'stored_path', 'file_sha256',
        'source_feeder_name', 'source_substation_name', 'status', 'total_placemarks',
        'point_placemarks', 'transformer_count', 'created_rows', 'updated_rows',
        'removed_rows', 'errors', 'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function transformers(): HasMany
    {
        return $this->hasMany(Transformer::class, 'kmz_import_id');
    }
}
