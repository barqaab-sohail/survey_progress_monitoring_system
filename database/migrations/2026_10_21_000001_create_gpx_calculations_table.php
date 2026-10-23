<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpx_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('feeder_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('file_name');
            $table->string('source_sha256', 64);
            $table->json('source_data');
            $table->json('settings');
            $table->json('result');
            $table->timestamps();
            $table->index(['created_by', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpx_calculations');
    }
};
