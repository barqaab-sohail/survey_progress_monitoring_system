<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdb_daily_entry_items', function (Blueprint $table) {
            $table->string('status', 20)->default('submitted')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
        });

        Schema::create('mdb_verification_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mdb_daily_entry_item_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->text('comment')->nullable();
            $table->foreignId('acted_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantity_snapshot');
            $table->timestamp('acted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdb_verification_histories');

        Schema::table('mdb_daily_entry_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'verified_at', 'return_reason', 'resubmitted_at']);
        });
    }
};
