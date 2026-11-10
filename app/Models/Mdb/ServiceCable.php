<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class ServiceCable extends Model
{
    protected $table = 'mdb_workflow_service_cables';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['consumer_number' => 'integer', 'measured_length_m' => 'float', 'source_page' => 'integer'];
    }

    public function surveyData(): array
    {
        return $this->attributesToArray();
    }
}
