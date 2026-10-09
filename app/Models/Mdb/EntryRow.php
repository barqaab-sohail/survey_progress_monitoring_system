<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class EntryRow extends Model
{
    protected $table = 'mdb_workflow_entry_rows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['conductors' => 'array', 'consumers' => 'array', 'pv_details' => 'array', 'inheritance' => 'array',
            'original_entry' => 'array', 'intersection' => 'boolean', 'manually_verified' => 'boolean', 'pole_height' => 'float', 'row_date' => 'date',
            'identity_version' => 'integer', 'entry_sequence' => 'integer'];
    }

    public function transformer()
    {
        return $this->belongsTo(NetworkTransformer::class, 'transformer_id');
    }

    public function section()
    {
        return $this->belongsTo(NetworkSection::class, 'section_id');
    }

    public function pvRecords()
    {
        return $this->hasMany(PvRecord::class, 'entry_row_id');
    }

    public function surveyData(): array
    {
        $data = $this->only(['id', 'transformer_id', 'section_id', 'client_uuid', 'pair_number', 'designation', 'group_number',
            'waypoint_reference', 'gpx_source_id', 'source_pdf_id', 'source_page', 'source_row', 'conductors', 'equipment_type',
            'pole_class', 'pole_height', 'pole_height_unit', 'consumers', 'intersection', 'pv_details', 'inheritance', 'original_entry', 'manually_verified']);
        $data['row_date'] = $this->row_date?->format('Y-m-d');
        if ($this->composite_identifier !== null || $this->identity_version !== null) {
            $data += $this->only(['composite_identifier', 'identity_version', 'entry_sequence']);
        }

        return $data;
    }
}
