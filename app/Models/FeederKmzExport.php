<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeederKmzExport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'networks' => 'array', 'coverage' => 'array', 'warnings' => 'array', 'changes' => 'array', 'bytes' => 'integer'];
    }

    public function feeder() { return $this->belongsTo(Feeder::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Export history is immutable. Generate a new export.'));
        static::deleting(fn () => throw new \LogicException('Export history must be retained.'));
    }
}
