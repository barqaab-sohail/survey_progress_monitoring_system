<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdb_workflow_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->foreignId('survey_team_id')->constrained()->restrictOnDelete();
            $table->date('survey_date');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('draft')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('approved_revision_id')->nullable()->index();
            $table->timestamps();
            $table->index(['project_id', 'feeder_id', 'survey_date']);
        });
        Schema::create('mdb_workflow_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('disk', 60)->default('local');
            $table->string('path', 1024);
            $table->string('original_name');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->string('mime', 120);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_source_id')->nullable()->constrained('mdb_workflow_sources')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('pending')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'kind']);
        });
        Schema::create('mdb_workflow_waypoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->restrictOnDelete();
            $table->foreignId('source_file_id')->constrained('mdb_workflow_sources')->restrictOnDelete();
            $table->string('name');
            $table->decimal('latitude', 12, 9);
            $table->decimal('longitude', 12, 9);
            $table->decimal('elevation', 12, 4)->nullable();
            $table->dateTime('recorded_at')->nullable();
            $table->text('description')->nullable();
            $table->json('original_entry')->nullable();
            $table->json('correction')->nullable();
            $table->foreignId('correction_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('correction_approved_at')->nullable();
            $table->timestamps();
            // Duplicate names are retained, then reported as ambiguous by validation.
            $table->index(['source_file_id', 'name']);
            $table->index(['batch_id', 'name']);
        });
        Schema::create('mdb_workflow_transformers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->restrictOnDelete();
            $table->string('code');
            $table->decimal('capacity_kva', 12, 3)->nullable();
            $table->foreignId('source_waypoint_id')->nullable()->constrained('mdb_workflow_waypoints')->restrictOnDelete();
            $table->json('header')->nullable();
            $table->json('original_header')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'code']);
        });
        Schema::create('mdb_workflow_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('mdb_workflow_transformers')->restrictOnDelete();
            foreach (['start', 'end'] as $endpoint) {
                $table->foreignId($endpoint.'_waypoint_id')->nullable()->constrained('mdb_workflow_waypoints')->restrictOnDelete();
                $table->string($endpoint.'_reference')->nullable();
                $table->foreignId($endpoint.'_source_file_id')->nullable()->constrained('mdb_workflow_sources')->restrictOnDelete();
            }
            $table->json('phases')->nullable();
            $table->json('conductors')->nullable();
            $table->string('equipment_ref')->nullable();
            $table->string('equipment_type')->nullable();
            $table->string('pole_class')->nullable();
            $table->decimal('pole_height', 10, 3)->nullable();
            $table->string('pole_height_unit', 12)->nullable();
            $table->json('geometry')->nullable();
            $table->decimal('measured_length_m', 12, 3)->nullable();
            $table->text('measured_length_reason')->nullable();
            $table->foreignId('length_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('source_pdf_id')->nullable()->constrained('mdb_workflow_sources')->restrictOnDelete();
            $table->unsignedInteger('source_page')->nullable();
            $table->string('source_row', 60)->nullable();
            $table->json('original_entry')->nullable();
            $table->timestamps();
        });
        Schema::create('mdb_workflow_consumers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('mdb_workflow_sections')->restrictOnDelete();
            $table->string('category');
            $table->unsignedInteger('count')->nullable();
            $table->text('original_value')->nullable();
            $table->json('demand')->nullable();
            $table->timestamps();
            $table->unique(['section_id', 'category']);
        });
        Schema::create('mdb_workflow_pv_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('mdb_workflow_transformers')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('mdb_workflow_sections')->restrictOnDelete();
            $table->string('reference');
            $table->decimal('installed_capacity_kw', 12, 3)->nullable();
            $table->text('remarks')->nullable();
            $table->json('original_entry')->nullable();
            $table->timestamps();
        });
        Schema::create('mdb_workflow_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('epsg')->nullable();
            $table->json('settings')->nullable();
            $table->json('load_assumptions')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('mdb_workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('version', 60);
            $table->string('disk', 60)->default('local');
            $table->string('path', 1024);
            $table->char('sha256', 64);
            $table->string('synergee_version', 60);
            $table->json('metadata')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
            $table->unique(['code', 'version']);
        });
        Schema::create('mdb_workflow_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->char('sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('frozen_at');
            $table->timestamps();
            $table->unique(['batch_id', 'number']);
        });
        Schema::create('mdb_workflow_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('mdb_workflow_revisions')->restrictOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 30);
            $table->text('comments')->nullable();
            $table->json('validation')->nullable();
            $table->timestamps();
        });
        Schema::create('mdb_workflow_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('mdb_workflow_batches')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('mdb_workflow_revisions')->restrictOnDelete();
            $table->foreignId('transformer_id')->nullable()->constrained('mdb_workflow_transformers')->restrictOnDelete();
            $table->foreignId('template_id')->constrained('mdb_workflow_templates')->restrictOnDelete();
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 40)->default('queued')->index();
            $table->string('payload_path', 1024)->nullable();
            $table->char('payload_sha256', 64)->nullable();
            $table->string('output_disk', 60)->default('local');
            $table->string('output_path', 1024)->nullable();
            $table->char('output_sha256', 64)->nullable();
            $table->string('mapping_version', 60);
            $table->string('template_version', 60)->nullable();
            $table->json('settings')->nullable();
            $table->json('readback')->nullable();
            $table->text('log')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->char('worker_token_hash', 64)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->timestamps();
        });
        Schema::create('mdb_workflow_model_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('export_job_id')->constrained('mdb_workflow_exports')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 40);
            $table->text('comments')->nullable();
            $table->string('synergee_version', 60)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['model_validations', 'exports', 'decisions', 'revisions', 'templates', 'configurations', 'pv_records', 'consumers', 'sections', 'transformers', 'waypoints', 'sources', 'batches'] as $table) {
            Schema::dropIfExists('mdb_workflow_'.$table);
        }
    }
};
