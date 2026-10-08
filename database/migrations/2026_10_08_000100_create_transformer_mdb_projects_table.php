<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transformer_mdb_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_field_survey_id')->nullable()->constrained('field_surveys')->nullOnDelete();
            $table->string('transformer_code', 100);
            $table->date('survey_date');
            $table->json('header');
            $table->json('rows');
            $table->json('solar');
            $table->text('remarks')->nullable();
            $table->json('export_settings');
            $table->string('pdf_path')->nullable();
            $table->string('gpx_path')->nullable();
            $table->json('gpx_waypoints')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transformer_mdb_projects');
    }
};
