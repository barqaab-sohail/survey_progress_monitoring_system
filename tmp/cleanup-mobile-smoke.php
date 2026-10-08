<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    $p = DB::table('projects')->where('code', 'MOBILE-SMOKE')->where('name', 'Mobile smoke project')->first();
    $u = DB::table('users')->where('email', 'mobile-smoke@example.test')->where('name', 'Test Field Collector')->first();
    $o = DB::table('organizations')->where('name', 'Mobile smoke organization')->first();
    if (! $p || ! $u || ! $o || $u->organization_id !== $o->id) {
        throw new RuntimeException('Synthetic fixture identity check failed; no changes performed.');
    }
    $feederIds = DB::table('feeders')->where('project_id', $p->id)->pluck('id');
    $teamIds = DB::table('survey_teams')->where('project_id', $p->id)->pluck('id');
    if (DB::table('field_surveys')->whereIn('feeder_id', $feederIds)->exists() || DB::table('survey_daily_entries')->where('entered_by', $u->id)->exists()) {
        throw new RuntimeException('Unexpected collected data; no cleanup performed.');
    }
    DB::table('transformers')->whereIn('feeder_id', $feederIds)->delete();
    DB::table('feeder_assignments')->whereIn('feeder_id', $feederIds)->delete();
    DB::table('survey_team_members')->whereIn('survey_team_id', $teamIds)->delete();
    DB::table('survey_teams')->whereIn('id', $teamIds)->delete();
    DB::table('feeders')->whereIn('id', $feederIds)->delete();
    foreach (['grid_stations', 'sub_divisions', 'divisions', 'circles'] as $table) {
        DB::table($table)->where('project_id', $p->id)->delete();
    }
    DB::table('projects')->where('id', $p->id)->delete();
    DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $u->id)->delete();
    DB::table('users')->where('id', $u->id)->delete();
    DB::table('organizations')->where('id', $o->id)->delete();
});
echo 'Removed only the synthetic mobile test user, hierarchy and assignments.'.PHP_EOL;
