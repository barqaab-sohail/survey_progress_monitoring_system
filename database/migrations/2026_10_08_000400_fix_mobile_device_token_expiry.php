<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older MySQL/MariaDB servers implicitly add ON UPDATE to the first
        // non-null TIMESTAMP. Updating last_used_at must not change expiry.
        Schema::table('mobile_device_tokens', function (Blueprint $table): void {
            $table->dateTime('expires_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_device_tokens', function (Blueprint $table): void {
            $table->timestamp('expires_at')->useCurrent()->change();
        });
    }
};
