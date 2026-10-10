<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_progress_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('survey_progress_connections', function (Blueprint $table) {
            $table->id();
            $table->text('token');
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('survey_progress_feeders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeder_id')->unique()->constrained('feeders')->cascadeOnDelete();
            $table->string('folder_id')->nullable()->unique();
            $table->boolean('mapping_manual')->default(false);
            $table->unsignedInteger('surveyed_count')->default(0);
            $table->string('sync_status')->default('not_synced');
            $table->timestamp('synced_at')->nullable();
            $table->string('manifest_hash', 64)->nullable();
            $table->string('length_status')->default('not_calculated');
            $table->decimal('confirmed_km', 16, 6)->nullable();
            $table->decimal('unverified_horizontal_km', 16, 6)->nullable();
            $table->unsignedInteger('unresolved_spans')->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->json('issues')->nullable();
            $table->json('aggregates')->nullable();
            $table->json('length_issues')->nullable();
            $table->timestamps();
        });
        Schema::create('survey_progress_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_feeder_id')->constrained('survey_progress_feeders')->cascadeOnDelete();
            $table->string('drive_id');
            $table->string('name');
            $table->string('kind', 10);
            $table->string('survey_key')->nullable();
            $table->json('metadata');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['progress_feeder_id', 'drive_id']);
        });
        Schema::create('survey_progress_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_feeder_id')->nullable()->constrained('survey_progress_feeders')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 30)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('manifest_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('survey_progress_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_feeder_id')->constrained('survey_progress_feeders')->cascadeOnDelete();
            $table->string('survey_key');
            $table->unsignedInteger('version');
            $table->string('pdf_drive_id');
            $table->string('pdf_checksum', 32);
            $table->string('gpx_drive_id');
            $table->string('gpx_checksum', 32);
            $table->json('data');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['progress_feeder_id', 'survey_key', 'version'], 'survey_progress_transcription_version');
        });
    }

    public function down(): void
    {
        foreach (['survey_progress_transcriptions', 'survey_progress_runs', 'survey_progress_files', 'survey_progress_feeders', 'survey_progress_connections', 'survey_progress_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
