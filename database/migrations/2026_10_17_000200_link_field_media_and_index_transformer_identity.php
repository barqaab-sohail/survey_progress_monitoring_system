<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_survey_attachments', function (Blueprint $table): void {
            $table->uuid('row_id')->nullable()->index();
        });
        Schema::table('field_surveys', function (Blueprint $table): void {
            $table->string('transformer_key', 100)->nullable();
            $table->index(['feeder_id', 'transformer_key']);
        });
        // Existing duplicates are retained; serialize new claims on the feeder row.
        DB::table('field_surveys')->select(['id', 'transformer_code'])->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $key = mb_strtoupper(preg_replace('/\s+/u', '', str_replace(['‐', '‑', '‒', '–', '—', '−'], '-', $row->transformer_code ?? '')));
                DB::table('field_surveys')->where('id', $row->id)->update(['transformer_key' => $key === '' ? null : $key]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('field_survey_attachments', function (Blueprint $table): void {
            $table->dropIndex(['row_id']);
            $table->dropColumn('row_id');
        });
        Schema::table('field_surveys', function (Blueprint $table): void {
            $table->dropIndex(['feeder_id', 'transformer_key']);
            $table->dropColumn('transformer_key');
        });
    }
};
