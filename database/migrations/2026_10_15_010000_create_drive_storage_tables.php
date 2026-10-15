<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_storage_connections', function (Blueprint $table) {
            $table->id();
            $table->text('token');
            $table->string('account_id');
            $table->string('account_email');
            $table->string('folder_id')->nullable();
            $table->foreignId('connected_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('drive_stored_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('drive_file_id', 200)->nullable()->unique();
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->char('md5', 32);
            $table->string('local_disk', 60)->default('local');
            $table->string('local_path', 1024);
            $table->string('account_id')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('local_deleted_at')->nullable();
            $table->json('context');
            $table->timestamps();
        });
        Schema::table('mdb_workflow_sources', function (Blueprint $table) {
            $table->foreignId('drive_stored_file_id')->nullable()->constrained('drive_stored_files')->restrictOnDelete();
        });
        foreach (['field_survey_attachments', 'field_survey_test_attachments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('drive_stored_file_id')->nullable()->constrained('drive_stored_files')->restrictOnDelete());
        }
        Schema::table('transformer_mdb_projects', function (Blueprint $table) {
            $table->foreignId('pdf_drive_stored_file_id')->nullable()->constrained('drive_stored_files')->restrictOnDelete();
            $table->foreignId('gpx_drive_stored_file_id')->nullable()->constrained('drive_stored_files')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transformer_mdb_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pdf_drive_stored_file_id');
            $table->dropConstrainedForeignId('gpx_drive_stored_file_id');
        });
        Schema::table('mdb_workflow_sources', fn (Blueprint $table) => $table->dropConstrainedForeignId('drive_stored_file_id'));
        foreach (['field_survey_attachments', 'field_survey_test_attachments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('drive_stored_file_id'));
        }
        Schema::dropIfExists('drive_stored_files');
        Schema::dropIfExists('drive_storage_connections');
    }
};
