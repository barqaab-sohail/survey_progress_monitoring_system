<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->decimal('capacity_kva', 10, 2)->nullable()->change();
            $table->string('source_transformer_code')->nullable();
            $table->decimal('source_capacity_kva', 10, 2)->nullable();
            $table->char('source_identity_key', 64)->nullable();
            $table->string('temporary_reference', 60)->nullable()->unique();
            $table->string('code_verification_status', 30)->default('confirmed')->index();
            $table->string('capacity_verification_status', 30)->default('confirmed')->index();
            $table->unsignedInteger('verification_revision')->default(1);
            $table->unsignedInteger('capacity_revision')->default(1);
            $table->json('code_aliases')->nullable();
            $table->foreignId('code_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('code_confirmed_at')->nullable();
            $table->foreignId('capacity_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('capacity_verified_at')->nullable();
            $table->unique(['feeder_id', 'source_identity_key'], 'transformer_source_identity');
        });
        Schema::table('transformer_kmz_imports', function (Blueprint $table) {
            $table->unsignedInteger('pending_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
        });
        Schema::create('transformer_capacity_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained()->restrictOnDelete();
            $table->foreignId('observed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('field_survey_id')->nullable()->constrained('field_surveys')->nullOnDelete();
            $table->unsignedInteger('source_survey_revision')->nullable();
            $table->unsignedInteger('baseline_revision');
            $table->unsignedInteger('baseline_capacity_revision')->default(1);
            $table->decimal('capacity_kva', 10, 2);
            $table->text('evidence_note');
            $table->string('evidence_path')->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['field_survey_id', 'source_survey_revision'], 'transformer_capacity_source');
        });
        Schema::create('transformer_registry_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 30);
            $table->json('old_values');
            $table->json('new_values');
            $table->text('reason');
            $table->text('evidence_reference');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transformer_registry_changes');
        Schema::dropIfExists('transformer_capacity_observations');
        Schema::table('transformer_kmz_imports', fn (Blueprint $table) => $table->dropColumn(['pending_rows', 'skipped_rows']));
        Schema::table('transformers', function (Blueprint $table) {
            $table->dropUnique('transformer_source_identity');
            $table->dropUnique(['temporary_reference']);
            $table->dropIndex(['code_verification_status']);
            $table->dropIndex(['capacity_verification_status']);
            $table->dropConstrainedForeignId('code_confirmed_by');
            $table->dropConstrainedForeignId('capacity_verified_by');
            $table->dropColumn(['source_transformer_code', 'source_capacity_kva', 'source_identity_key', 'temporary_reference',
                'code_verification_status', 'capacity_verification_status', 'verification_revision', 'capacity_revision', 'code_aliases', 'code_confirmed_at', 'capacity_verified_at']);
            // Retain nullable capacity on rollback rather than replacing unknown values with invented zeros.
        });
    }
};
