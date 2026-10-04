<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
            $table->string('favicon_path')->nullable()->after('logo_path');
        });

        DB::table('projects')->where('code', 'HAZECO-TDL')->update([
            'name' => 'HAZECO Transmission and Distribution Losses Calculation Project',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'favicon_path']);
        });
    }
};
