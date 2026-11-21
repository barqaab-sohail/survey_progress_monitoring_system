<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdb_workflow_entry_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->cascadeOnDelete();
            $table->foreignId('transformer_id')->nullable()->constrained('mdb_workflow_transformers')->nullOnDelete();
            $table->string('transformer_reference');
            $table->foreignId('source_file_id')->nullable()->constrained('mdb_workflow_sources')->nullOnDelete();
            $table->string('pdf_name');
            $table->unsignedInteger('pdf_version')->default(1);
            $table->unsignedInteger('source_page');
            $table->string('source_row', 100)->nullable();
            $table->text('comment');
            $table->uuid('client_uuid');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name');
            $table->string('status', 16)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolver_name')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'client_uuid'], 'mdb_entry_correction_retry');
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdb_workflow_entry_corrections');
    }
};
