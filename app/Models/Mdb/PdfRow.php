<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class PdfRow extends Model
{
    protected $table = 'mdb_workflow_pdf_rows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['bounds' => 'array', 'entered' => 'boolean', 'page' => 'integer', 'row_number' => 'integer'];
    }
}
