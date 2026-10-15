<?php

namespace App\Models\Mdb;

use Illuminate\Database\Eloquent\Model;

class PdfLine extends Model
{
    protected $table = 'mdb_workflow_pdf_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start' => 'array', 'end' => 'array', 'page' => 'integer'];
    }
}
