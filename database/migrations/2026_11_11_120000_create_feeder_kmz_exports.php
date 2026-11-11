<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feeder_kmz_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeder_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('filename');
            $table->string('disk', 40)->default('local');
            $table->string('path', 1024);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->char('baseline_sha256', 64)->nullable();
            $table->json('counts');
            $table->json('networks');
            $table->json('coverage');
            $table->json('warnings');
            $table->json('changes');
            $table->timestamps();
            $table->index(['feeder_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feeder_kmz_exports');
    }
};
