<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdb_workflow_pdf_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_file_id')->constrained('mdb_workflow_sources')->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->unsignedInteger('row_number');
            $table->json('bounds');
            $table->foreignId('entry_row_id')->nullable()->unique()->constrained('mdb_workflow_entry_rows')->nullOnDelete();
            $table->boolean('entered')->default(false);
            $table->timestamps();
            $table->unique(['source_file_id', 'page', 'row_number'], 'mdb_pdf_row_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdb_workflow_pdf_rows');
    }
};
