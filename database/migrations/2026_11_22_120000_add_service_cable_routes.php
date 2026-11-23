<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_workflow_service_cables', function (Blueprint $table) {
            $table->json('route_points')->nullable();
            $table->double('route_length_m')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_service_cables', fn (Blueprint $table) => $table->dropColumn(['route_points', 'route_length_m']));
    }
};
