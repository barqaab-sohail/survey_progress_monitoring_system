<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_teams', function (Blueprint $table) {
            $table->string('group_code', 2)->nullable();
            $table->string('gpx_group_code', 20)->nullable();
            $table->unique(['project_id', 'group_code']);
            $table->unique(['project_id', 'gpx_group_code']);
        });
        Schema::create('mobile_gpx_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_team_id')->constrained();
            $table->date('survey_date');
            $table->string('group_code', 2);
            $table->string('filename_group', 20);
            $table->unsignedInteger('expected_transformers');
            $table->string('original_name');
            $table->string('path');
            $table->string('sha256', 64);
            $table->json('points');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->unique(['survey_team_id', 'sha256']);
        });
        Schema::create('mobile_gpx_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_survey_id')->constrained();
            $table->unsignedInteger('survey_revision');
            $table->string('survey_sha256', 64);
            $table->json('source_ids');
            $table->json('mapping');
            $table->json('report');
            $table->json('snapshot');
            $table->string('sha256', 64);
            $table->text('confirmation_note')->nullable();
            $table->foreignId('confirmed_by')->constrained('users');
            $table->timestamps();
            $table->index(['field_survey_id', 'survey_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_gpx_links');
        Schema::dropIfExists('mobile_gpx_sources');
        Schema::table('survey_teams', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'group_code']);
            $table->dropUnique(['project_id', 'gpx_group_code']);
            $table->dropColumn(['group_code', 'gpx_group_code']);
        });
    }
};
