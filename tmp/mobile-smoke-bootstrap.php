<?php

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\Transformer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../vendor/autoload.php';
$path = __DIR__.'/mobile-smoke-isolated.sqlite';
if (file_exists($path)) {
    throw new RuntimeException('Smoke fixture already exists; refuse to replace it.');
}
touch($path);
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => __DIR__.'/mobile-smoke-config.php', 'APP_ROUTES_CACHE' => __DIR__.'/mobile-smoke-routes.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $path) {
    throw new RuntimeException('Isolation guard failed.');
}
Artisan::call('migrate', ['--force' => true]);
$org = Organization::create(['name' => 'Mobile smoke organization', 'type' => 'internal', 'status' => 'active']);
$user = User::factory()->create(['name' => 'Test Field Collector', 'email' => 'mobile-smoke@example.test', 'password' => 'MobileTest123!', 'organization_id' => $org->id, 'role' => UserRole::SurveyTeamLeader, 'status' => 'active']);
$p = Project::create(['code' => 'MOBILE-SMOKE', 'name' => 'Mobile smoke project', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
$c = Circle::create(['project_id' => $p->id, 'code' => 'SM-C', 'name' => 'Test circle']);
$d = Division::create(['project_id' => $p->id, 'circle_id' => $c->id, 'code' => 'SM-D', 'name' => 'Test division']);
$sd = SubDivision::create(['project_id' => $p->id, 'division_id' => $d->id, 'code' => 'SM-SD', 'name' => 'Test subdivision']);
$gs = GridStation::create(['project_id' => $p->id, 'sub_division_id' => $sd->id, 'code' => 'SM-G', 'name' => 'Test grid']);
$f = Feeder::create(['project_id' => $p->id, 'circle_id' => $c->id, 'division_id' => $d->id, 'sub_division_id' => $sd->id, 'grid_station_id' => $gs->id, 'feeder_code' => 'TEST-001', 'feeder_name' => 'Test feeder', 'total_transformers' => 100, 'status' => 'active']);
$t = SurveyTeam::create(['project_id' => $p->id, 'code' => 'SM-T', 'name' => 'Test team', 'status' => 'active']);
$t->members()->attach($user->id, ['is_leader' => true]);
FeederAssignment::create(['feeder_id' => $f->id, 'survey_team_id' => $t->id, 'assigned_by' => $user->id, 'start_date' => today(), 'status' => 'active']);
Transformer::create(['feeder_id' => $f->id, 'source_feature_id' => 'smoke-transformer', 'transformer_code' => 'TEST-TF-001', 'capacity_kva' => 100, 'latitude' => 33.9, 'longitude' => 73.4]);
echo 'Created isolated SQLite smoke fixture with synthetic mobile user.'.PHP_EOL;
