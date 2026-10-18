<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_workflow_batches', function (Blueprint $table) {
            $table->foreignId('entry_assigned_to')->nullable()->after('entry_operator_id')->constrained('users')->nullOnDelete();
            $table->foreignId('verification_assigned_to')->nullable()->after('survey_verifier_id')->constrained('users')->nullOnDelete();
            $table->foreignId('mdb_assigned_to')->nullable()->after('verification_assigned_to')->constrained('users')->nullOnDelete();
            $table->index(['survey_status', 'entry_assigned_to']);
            $table->index(
                ['survey_status', 'verification_assigned_to'],
                'mdb_batches_status_verifier_idx'
            );
            $table->index(['survey_status', 'mdb_assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_batches', function (Blueprint $table) {
            $table->dropIndex(['survey_status', 'entry_assigned_to']);
            $table->dropIndex(['survey_status', 'verification_assigned_to']);
            $table->dropIndex(['survey_status', 'mdb_assigned_to']);
            $table->dropConstrainedForeignId('entry_assigned_to');
            $table->dropConstrainedForeignId('verification_assigned_to');
            $table->dropConstrainedForeignId('mdb_assigned_to');
        });
    }
};
