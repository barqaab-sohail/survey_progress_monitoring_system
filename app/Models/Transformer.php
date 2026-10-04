<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transformer extends Model
{
    protected $fillable = [
        'feeder_id', 'kmz_import_id', 'source_feature_id', 'transformer_code',
        'gps_waypoint_number', 'source_substation_name', 'source_feeder_name',
        'line_voltage', 'feeders_on_pole', 'pole_number', 'pole_phase', 'pole_use',
        'pole_height', 'pole_type', 'conductor_phase_r', 'conductor_phase_y',
        'conductor_phase_b', 'conductor_neutral', 'capacity_kva', 'equipment_unit',
        'equipment_phase', 'equipment_use', 'equipment_status', 'equipment_make',
        'equipment_name', 'equipment_location', 'equipment_mounting', 'end_type',
        'residential_single', 'residential_three', 'residential_total',
        'small_commercial', 'large_commercial', 'small_industries', 'large_industries',
        'public_use', 'agricultural', 'street_lights', 'remarks', 'source_picture_path',
        'longitude', 'latitude', 'altitude', 'raw_attributes',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kva' => 'decimal:2',
            'pole_height' => 'decimal:2',
            'longitude' => 'decimal:7',
            'latitude' => 'decimal:7',
            'altitude' => 'decimal:2',
            'raw_attributes' => 'array',
        ];
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }

    public function kmzImport(): BelongsTo
    {
        return $this->belongsTo(TransformerKmzImport::class, 'kmz_import_id');
    }

    public function getMapUrlAttribute(): string
    {
        return 'https://www.google.com/maps?q='.$this->latitude.','.$this->longitude;
    }
}
