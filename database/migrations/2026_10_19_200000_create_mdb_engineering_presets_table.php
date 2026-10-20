<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdb_engineering_presets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->json('configuration');
            $table->json('evidence');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdb_engineering_presets');
    }
};
