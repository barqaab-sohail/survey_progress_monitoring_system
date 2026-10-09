<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_workflow_entry_rows', function (Blueprint $table) {
            $table->string('composite_identifier', 11)->nullable()->index();
            $table->unsignedTinyInteger('identity_version')->nullable();
            $table->unsignedInteger('entry_sequence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mdb_workflow_entry_rows', function (Blueprint $table) {
            $table->dropIndex(['composite_identifier']);
            $table->dropColumn(['composite_identifier', 'identity_version', 'entry_sequence']);
        });
    }
};
