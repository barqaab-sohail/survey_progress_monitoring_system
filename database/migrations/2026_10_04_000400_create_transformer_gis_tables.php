<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transformer_kmz_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            $table->text('stored_path');
            $table->char('file_sha256', 64)->nullable()->index();
            $table->string('source_feeder_name')->nullable();
            $table->string('source_substation_name')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('total_placemarks')->default(0);
            $table->unsignedInteger('point_placemarks')->default(0);
            $table->unsignedInteger('transformer_count')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('removed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->index(['feeder_id', 'status']);
        });

        Schema::create('transformers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kmz_import_id')->nullable()->constrained('transformer_kmz_imports')->nullOnDelete();
            $table->string('source_feature_id')->nullable();
            $table->string('transformer_code');
            $table->string('gps_waypoint_number')->nullable();
            $table->string('source_substation_name')->nullable();
            $table->string('source_feeder_name')->nullable();
            $table->string('line_voltage')->nullable();
            $table->unsignedSmallInteger('feeders_on_pole')->nullable();
            $table->string('pole_number')->nullable();
            $table->string('pole_phase')->nullable();
            $table->string('pole_use')->nullable();
            $table->decimal('pole_height', 8, 2)->nullable();
            $table->string('pole_type')->nullable();
            $table->string('conductor_phase_r')->nullable();
            $table->string('conductor_phase_y')->nullable();
            $table->string('conductor_phase_b')->nullable();
            $table->string('conductor_neutral')->nullable();
            $table->decimal('capacity_kva', 10, 2);
            $table->unsignedSmallInteger('equipment_unit')->nullable();
            $table->string('equipment_phase')->nullable();
            $table->string('equipment_use')->nullable();
            $table->string('equipment_status')->nullable();
            $table->string('equipment_make')->nullable();
            $table->string('equipment_name')->nullable();
            $table->string('equipment_location')->nullable();
            $table->string('equipment_mounting')->nullable();
            $table->string('end_type')->nullable();
            $table->unsignedInteger('residential_single')->default(0);
            $table->unsignedInteger('residential_three')->default(0);
            $table->unsignedInteger('residential_total')->default(0);
            $table->unsignedInteger('small_commercial')->default(0);
            $table->unsignedInteger('large_commercial')->default(0);
            $table->unsignedInteger('small_industries')->default(0);
            $table->unsignedInteger('large_industries')->default(0);
            $table->unsignedInteger('public_use')->default(0);
            $table->unsignedInteger('agricultural')->default(0);
            $table->unsignedInteger('street_lights')->default(0);
            $table->text('remarks')->nullable();
            $table->text('source_picture_path')->nullable();
            $table->decimal('longitude', 10, 7);
            $table->decimal('latitude', 9, 7);
            $table->decimal('altitude', 10, 2)->nullable();
            $table->json('raw_attributes')->nullable();
            $table->timestamps();
            $table->unique(['feeder_id', 'transformer_code']);
            $table->index(['feeder_id', 'capacity_kva']);
            $table->index('gps_waypoint_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transformers');
        Schema::dropIfExists('transformer_kmz_imports');
    }
};
