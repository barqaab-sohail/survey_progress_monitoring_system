<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdb_workflow_pdf_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_file_id')->constrained('mdb_workflow_sources')->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->uuid('client_uuid')->unique();
            $table->json('start');
            $table->json('end');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['source_file_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdb_workflow_pdf_lines');
    }
};
