<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ht_network_updates', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->foreignId('survey_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('transformer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('transformer_code', 100);
            $table->string('transformer_key', 100);
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['feeder_id', 'transformer_key']);
        });
        Schema::table('field_surveys', function (Blueprint $table) {
            $table->foreignId('ht_network_update_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('field_surveys', fn (Blueprint $table) => $table->dropConstrainedForeignId('ht_network_update_id'));
        Schema::dropIfExists('ht_network_updates');
    }
};
