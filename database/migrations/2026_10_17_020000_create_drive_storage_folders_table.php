<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_storage_folders', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 200);
            $table->string('root_folder_id', 200);
            // Preserve the mapping even when a feeder is subsequently removed.
            $table->unsignedBigInteger('feeder_id');
            $table->string('drive_folder_id', 200)->unique();
            $table->string('name');
            $table->timestamps();
            $table->unique(['account_id', 'root_folder_id', 'feeder_id'], 'drive_folder_destination_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_storage_folders');
    }
};
