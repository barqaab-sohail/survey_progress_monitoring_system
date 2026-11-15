<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('field_surveys', fn (Blueprint $table) => $table->json('service_cables')->nullable());
    }

    public function down(): void
    {
        Schema::table('field_surveys', fn (Blueprint $table) => $table->dropColumn('service_cables'));
    }
};
