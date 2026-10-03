<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeders', function (Blueprint $table) {
            $table->boolean('demo_baseline')->default(false)->after('baseline_pending')->index();
        });
    }

    public function down(): void
    {
        Schema::table('feeders', function (Blueprint $table) {
            $table->dropIndex(['demo_baseline']);
            $table->dropColumn('demo_baseline');
        });
    }
};
