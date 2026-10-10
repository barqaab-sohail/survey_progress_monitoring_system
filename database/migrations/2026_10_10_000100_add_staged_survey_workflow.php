<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_workflow_batches', function (Blueprint $table) {
            $table->boolean('staged_workflow')->default(false);
            $table->string('survey_status', 40)->default('entry');
            $table->json('entry_actor_ids')->nullable();
            $table->foreignId('entry_operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('survey_verifier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_completed_at')->nullable();
            $table->timestamp('survey_verified_at')->nullable();
            $table->text('survey_remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entry_operator_id');
            $table->dropConstrainedForeignId('survey_verifier_id');
            $table->dropColumn(['staged_workflow', 'survey_status', 'entry_actor_ids', 'entry_completed_at', 'survey_verified_at', 'survey_remarks']);
        });
    }
};
