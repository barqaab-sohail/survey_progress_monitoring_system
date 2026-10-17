<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_workflow_pv_records', function (Blueprint $table): void {
            $table->string('meter_number', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_pv_records', function (Blueprint $table): void {
            $table->dropColumn('meter_number');
        });
    }
};
