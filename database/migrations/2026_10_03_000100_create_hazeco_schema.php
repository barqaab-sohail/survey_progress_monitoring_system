<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 30)->index();
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('timezone')->default('Asia/Karachi');
            $table->boolean('processing_required')->default(true);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('circles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['project_id', 'code']);
        });

        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['project_id', 'code']);
            $table->index(['circle_id', 'name']);
        });

        Schema::create('sub_divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['project_id', 'code']);
            $table->index(['division_id', 'name']);
        });

        Schema::create('grid_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_division_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['project_id', 'code']);
            $table->index(['sub_division_id', 'name']);
        });

        Schema::create('feeders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('circle_id')->constrained()->restrictOnDelete();
            $table->foreignId('division_id')->constrained()->restrictOnDelete();
            $table->foreignId('sub_division_id')->constrained()->restrictOnDelete();
            $table->foreignId('grid_station_id')->constrained()->restrictOnDelete();
            $table->string('feeder_code');
            $table->string('feeder_name');
            $table->unsignedInteger('total_transformers');
            $table->text('survey_drive_url')->nullable();
            $table->text('mdb_drive_url')->nullable();
            $table->text('processing_drive_url')->nullable();
            $table->boolean('processing_required')->default(true);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['project_id', 'feeder_code']);
            $table->index(['grid_station_id', 'status']);
        });

        Schema::create('survey_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['project_id', 'code']);
        });

        Schema::create('survey_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_leader')->default(false);
            $table->timestamps();
            $table->unique(['survey_team_id', 'user_id']);
        });

        Schema::create('mdb_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['project_id', 'code']);
        });

        Schema::create('mdb_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mdb_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['mdb_team_id', 'user_id']);
        });

        Schema::create('processing_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['project_id', 'code']);
        });

        Schema::create('processing_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['processing_team_id', 'user_id']);
        });

        Schema::create('feeder_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['survey_team_id', 'status']);
            $table->index(['feeder_id', 'status']);
        });

        Schema::create('survey_daily_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->index();
            $table->foreignId('survey_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('submitted')->index();
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->index(['survey_team_id', 'entry_date']);
        });

        Schema::create('survey_daily_entry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_daily_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('transformers_surveyed');
            $table->text('drive_url')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 30)->default('submitted')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();
            $table->index(['feeder_id', 'status']);
            $table->unique(['survey_daily_entry_id', 'feeder_id']);
        });

        Schema::create('survey_verification_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_daily_entry_item_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->text('comment')->nullable();
            $table->foreignId('acted_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantity_snapshot');
            $table->timestamp('acted_at');
            $table->index(['survey_daily_entry_item_id', 'acted_at'], 'survey_history_item_date_idx');
        });

        Schema::create('mdb_daily_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->index();
            $table->foreignId('mdb_team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('mdb_daily_entry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mdb_daily_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('mdb_files_created');
            $table->text('drive_url')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index('feeder_id');
            $table->unique(['mdb_daily_entry_id', 'feeder_id']);
        });

        Schema::create('mdb_processing_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('processing_team_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('assigned_quantity');
            $table->date('assignment_date')->index();
            $table->date('target_date')->nullable();
            $table->text('drive_url')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['feeder_id', 'status']);
        });

        Schema::create('mdb_processing_daily_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->index();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'entry_date']);
        });

        Schema::create('mdb_processing_daily_entry_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mdb_processing_daily_entry_id');
            $table->unsignedBigInteger('mdb_processing_assignment_id');
            $table->foreign('mdb_processing_daily_entry_id', 'processing_items_entry_fk')->references('id')->on('mdb_processing_daily_entries')->cascadeOnDelete();
            $table->foreign('mdb_processing_assignment_id', 'processing_items_assignment_fk')->references('id')->on('mdb_processing_assignments')->restrictOnDelete();
            $table->unsignedInteger('mdb_processed');
            $table->text('output_drive_url')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index('mdb_processing_assignment_id', 'processing_items_assignment_idx');
            $table->unique(['mdb_processing_daily_entry_id', 'mdb_processing_assignment_id'], 'processing_entry_assignment_unique');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['created_at', 'user_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('mdb_processing_daily_entry_items');
        Schema::dropIfExists('mdb_processing_daily_entries');
        Schema::dropIfExists('mdb_processing_assignments');
        Schema::dropIfExists('mdb_daily_entry_items');
        Schema::dropIfExists('mdb_daily_entries');
        Schema::dropIfExists('survey_verification_history');
        Schema::dropIfExists('survey_daily_entry_items');
        Schema::dropIfExists('survey_daily_entries');
        Schema::dropIfExists('feeder_assignments');
        Schema::dropIfExists('processing_team_members');
        Schema::dropIfExists('processing_teams');
        Schema::dropIfExists('mdb_team_members');
        Schema::dropIfExists('mdb_teams');
        Schema::dropIfExists('survey_team_members');
        Schema::dropIfExists('survey_teams');
        Schema::dropIfExists('feeders');
        Schema::dropIfExists('grid_stations');
        Schema::dropIfExists('sub_divisions');
        Schema::dropIfExists('divisions');
        Schema::dropIfExists('circles');
        Schema::dropIfExists('projects');
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['organization_id']));
        Schema::dropIfExists('organizations');
    }
};
