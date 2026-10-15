<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdb_review_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->cascadeOnDelete();
            $table->foreignId('transformer_id')->constrained('mdb_workflow_transformers')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('field_key', 120);
            $table->char('value_hash', 64);
            $table->timestamps();
            $table->unique(['transformer_id', 'reviewer_id', 'field_key'], 'mdb_review_check_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdb_review_checks');
    }
};
