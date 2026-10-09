<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_name', 120);
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('field_surveys', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('survey_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->foreignId('transformer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transformer_code', 100)->nullable();
            $table->date('survey_date');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->char('payload_hash', 64);
            $table->json('header');
            $table->json('rows');
            $table->json('solar');
            $table->json('reference_snapshot');
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['collected_by', 'survey_date']);
            $table->index(['feeder_id', 'status']);
        });

        Schema::create('field_survey_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('field_survey_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('byte_length');
            $table->char('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_survey_attachments');
        Schema::dropIfExists('field_surveys');
        Schema::dropIfExists('mobile_device_tokens');
    }
};
