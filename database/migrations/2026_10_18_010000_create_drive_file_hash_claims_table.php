<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_file_hash_claims', function (Blueprint $table) {
            $table->char('sha256', 64)->primary();
            $table->foreignId('drive_stored_file_id')->nullable()->unique()->constrained('drive_stored_files')->restrictOnDelete();
            $table->timestamps();
        });

        // Claims select a canonical record without changing or deleting any existing Drive copies.
        $files = DB::table('drive_stored_files')->orderByRaw("CASE WHEN status = 'uploaded' THEN 0 ELSE 1 END")
            ->orderBy('id')->get(['id', 'sha256', 'created_at', 'updated_at']);
        foreach ($files as $file) {
            DB::table('drive_file_hash_claims')->insertOrIgnore([
                'sha256' => $file->sha256, 'drive_stored_file_id' => $file->id,
                'created_at' => $file->created_at, 'updated_at' => $file->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_file_hash_claims');
    }
};
