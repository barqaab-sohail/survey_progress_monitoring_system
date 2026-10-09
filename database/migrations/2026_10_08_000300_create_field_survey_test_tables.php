<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_survey_tests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->timestamps();
        });
        Schema::create('field_survey_test_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('field_survey_test_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->string('kind');
            $table->string('path');
            $table->string('mime_type');
            $table->string('file_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_survey_test_attachments');
        Schema::dropIfExists('field_survey_tests');
    }
};
