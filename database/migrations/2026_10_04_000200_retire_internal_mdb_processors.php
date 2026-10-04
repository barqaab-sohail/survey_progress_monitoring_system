<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $internalIds = DB::table('organizations')->where('type', 'internal')->pluck('id');
        DB::table('users')->where('role', 'mdb_processing_user')->whereIn('organization_id', $internalIds)
            ->update(['status' => 'inactive', 'updated_at' => now()]);
        DB::table('processing_teams')->whereIn('organization_id', $internalIds)
            ->update(['status' => 'inactive', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Account status is a deliberate administrative choice. Rolling back schema
        // must not reactivate retired users or remove their historical records.
    }
};
