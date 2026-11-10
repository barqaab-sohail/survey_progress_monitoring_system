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
        'source_transformer_code', 'source_capacity_kva', 'source_identity_key', 'temporary_reference',
        'code_verification_status', 'capacity_verification_status', 'verification_revision', 'capacity_revision', 'code_aliases',
        'code_confirmed_by', 'code_confirmed_at', 'capacity_verified_by', 'capacity_verified_at',
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
            'source_capacity_kva' => 'decimal:2', 'verification_revision' => 'integer', 'capacity_revision' => 'integer', 'code_aliases' => 'array',
            'code_confirmed_at' => 'datetime', 'capacity_verified_at' => 'datetime',
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

    public function capacityObservations() { return $this->hasMany(TransformerCapacityObservation::class); }

    public function registryChanges() { return $this->hasMany(TransformerRegistryChange::class); }

    public function fieldSurveys() { return $this->hasMany(FieldSurvey::class); }

    public function hasPendingVerification(): bool
    {
        return $this->code_verification_status === 'pending_client' || $this->capacity_verification_status === 'pending_field';
    }

    public function getVerificationLabelAttribute(): string
    {
        return collect([$this->code_verification_status === 'pending_client' ? 'Client code confirmation pending' : null,
            $this->capacity_verification_status === 'pending_field' ? 'Capacity unknown — field verification pending' : null])->filter()->join(' · ') ?: 'Confirmed';
    }
}
