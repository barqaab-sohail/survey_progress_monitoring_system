<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeders', function (Blueprint $table) {
            $table->unsignedInteger('source_serial')->nullable()->after('feeder_name');
            $table->string('source_feeder_code')->nullable()->after('source_serial');
            $table->decimal('load_kw', 14, 2)->nullable()->after('source_feeder_code');
            $table->unsignedInteger('number_of_consumers')->nullable()->after('load_kw');
            $table->string('nature')->nullable()->after('number_of_consumers');
            $table->boolean('baseline_pending')->default(false)->after('total_transformers')->index();
            $table->string('source_file')->nullable()->after('baseline_pending');
            $table->string('source_sheet')->nullable()->after('source_file');
            $table->timestamp('imported_at')->nullable()->after('source_sheet');
            $table->index(['project_id', 'source_serial']);
        });
    }

    public function down(): void
    {
        Schema::table('feeders', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'source_serial']);
            $table->dropIndex(['baseline_pending']);
            $table->dropColumn([
                'source_serial', 'source_feeder_code', 'load_kw', 'number_of_consumers',
                'nature', 'baseline_pending', 'source_file', 'source_sheet', 'imported_at',
            ]);
        });
    }
};
