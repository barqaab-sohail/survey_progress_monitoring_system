<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveyor_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('assignment_uuid')->unique();
            $table->foreignId('survey_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->foreignId('surveyor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->date('assigned_at');
            $table->date('deadline')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->text('instructions')->nullable();
            $table->string('status', 32)->default('assigned')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['surveyor_id', 'status', 'deadline']);
            $table->index(['survey_team_id', 'feeder_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveyor_assignments');
    }
};
