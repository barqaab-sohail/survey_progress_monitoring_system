<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('allow_consumer_service_cables')->default(false);
        });
        Schema::table('mdb_workflow_transformers', function (Blueprint $table) {
            $table->boolean('service_cables_enabled')->default(false);
        });
        Schema::create('mdb_workflow_service_cables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('mdb_workflow_transformers')->cascadeOnDelete();
            $table->foreignId('entry_row_id')->constrained('mdb_workflow_entry_rows')->cascadeOnDelete();
            $table->string('category', 2);
            $table->unsignedInteger('consumer_number');
            $table->string('group_number', 2);
            $table->date('row_date');
            $table->string('waypoint_reference', 3);
            $table->string('composite_identifier', 11);
            $table->foreignId('gpx_source_id')->nullable()->constrained('mdb_workflow_sources')->nullOnDelete();
            $table->foreignId('meter_waypoint_id')->nullable()->constrained('mdb_workflow_waypoints')->nullOnDelete();
            $table->string('consumer_reference')->nullable();
            $table->string('meter_number')->nullable();
            $table->string('phase', 3)->nullable();
            $table->string('conductor_code')->nullable();
            $table->double('measured_length_m')->nullable();
            $table->text('length_evidence')->nullable();
            $table->foreignId('source_pdf_id')->nullable()->constrained('mdb_workflow_sources')->nullOnDelete();
            $table->unsignedInteger('source_page');
            $table->string('source_row')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['entry_row_id', 'category', 'consumer_number'], 'mdb_service_consumer_slot');
            $table->unique(['transformer_id', 'composite_identifier'], 'mdb_service_meter_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdb_workflow_service_cables');
        Schema::table('mdb_workflow_transformers', fn (Blueprint $table) => $table->dropColumn('service_cables_enabled'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('allow_consumer_service_cables'));
    }
};
