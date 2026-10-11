<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdb_workflow_generation_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->index(['batch_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdb_workflow_generation_inputs');
    }
};
