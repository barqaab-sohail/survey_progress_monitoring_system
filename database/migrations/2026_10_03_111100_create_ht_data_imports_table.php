<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ht_data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            $table->string('stored_path')->nullable();
            $table->string('file_sha256', 64)->nullable()->index();
            $table->string('sheet_name')->default('27_Feeders');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ht_data_imports');
    }
};
