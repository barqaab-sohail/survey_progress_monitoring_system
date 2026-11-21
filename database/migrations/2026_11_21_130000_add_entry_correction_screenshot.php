<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_workflow_entry_corrections', function (Blueprint $table) {
            $table->string('screenshot_path')->nullable();
            $table->string('screenshot_mime', 32)->nullable();
            $table->string('screenshot_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_entry_corrections', fn (Blueprint $table) => $table->dropColumn(['screenshot_path', 'screenshot_mime', 'screenshot_sha256']));
    }
};
