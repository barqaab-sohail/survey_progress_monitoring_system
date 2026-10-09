<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdb_workflow_entry_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('mdb_workflow_transformers')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('mdb_workflow_sections')->nullOnDelete();
            $table->uuid('client_uuid');
            $table->uuid('save_uuid');
            $table->string('save_hash', 64);
            $table->unsignedInteger('pair_number');
            $table->string('designation', 1);
            $table->string('group_number', 40)->nullable();
            $table->date('row_date')->nullable();
            $table->string('waypoint_reference')->nullable();
            $table->foreignId('gpx_source_id')->nullable()->constrained('mdb_workflow_sources')->restrictOnDelete();
            $table->foreignId('source_pdf_id')->nullable()->constrained('mdb_workflow_sources')->restrictOnDelete();
            $table->unsignedInteger('source_page')->nullable();
            $table->string('source_row', 60)->nullable();
            $table->json('conductors');
            $table->string('equipment_type')->nullable();
            $table->string('pole_class')->nullable();
            $table->decimal('pole_height', 10, 3)->nullable();
            $table->string('pole_height_unit', 12)->nullable();
            $table->json('consumers');
            $table->boolean('intersection')->default(false);
            $table->json('pv_details')->nullable();
            $table->json('inheritance')->nullable();
            $table->json('original_entry')->nullable();
            $table->boolean('manually_verified')->default(false);
            $table->timestamps();
            $table->unique(['transformer_id', 'client_uuid']);
            $table->unique(['transformer_id', 'pair_number', 'designation'], 'mdb_entry_pair_slot_unique');
        });
        Schema::table('mdb_workflow_pv_records', function (Blueprint $table) {
            $table->foreignId('entry_row_id')->nullable()->constrained('mdb_workflow_entry_rows')->nullOnDelete();
            $table->decimal('service_load_kw', 12, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_pv_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entry_row_id');
            $table->dropColumn('service_load_kw');
        });
        Schema::dropIfExists('mdb_workflow_entry_rows');
    }
};
