<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_workflow_batches', function (Blueprint $table): void {
            $table->foreignId('survey_team_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_batches', function (Blueprint $table): void {
            $table->foreignId('survey_team_id')->nullable(false)->change();
        });
    }
};
