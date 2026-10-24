<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_workflow_waypoints', function (Blueprint $table): void {
            $table->dropForeign(['source_file_id']);
            $table->foreignId('source_file_id')->nullable()->change();
            $table->foreign('source_file_id')->references('id')->on('mdb_workflow_sources')->restrictOnDelete();
            $table->foreignId('generation_input_id')->nullable()->after('source_file_id')
                ->constrained('mdb_workflow_generation_inputs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_waypoints', function (Blueprint $table): void {
            $table->dropForeign(['generation_input_id']);
            $table->dropColumn('generation_input_id');
            $table->dropForeign(['source_file_id']);
            // Keep the column nullable so a rollback cannot destroy already-recorded KMZ points.
            $table->foreignId('source_file_id')->nullable()->change();
            $table->foreign('source_file_id')->references('id')->on('mdb_workflow_sources')->restrictOnDelete();
        });
    }
};
