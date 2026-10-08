<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$qaPath = __DIR__.'/browser.sqlite';
if (!is_file($qaPath)) touch($qaPath);
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$qaPath]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
if (!App\Models\User::where('email','mdb-ui@example.test')->exists()) {
 $org=App\Models\Organization::create(['name'=>'QA only','type'=>'internal','status'=>'active']);
 $user=App\Models\User::create(['name'=>'MDB Browser QA','email'=>'mdb-ui@example.test','password'=>'QaOnlyPassword123!','organization_id'=>$org->id,'role'=>'mdb_team_user','status'=>'active']);
 $project=App\Models\Project::create(['code'=>'QA','name'=>'MDB UI QA','timezone'=>'Asia/Karachi','status'=>'active']);
 $circle=App\Models\Circle::create(['project_id'=>$project->id,'code'=>'C','name'=>'Circle']);
 $division=App\Models\Division::create(['project_id'=>$project->id,'circle_id'=>$circle->id,'code'=>'D','name'=>'Division']);
 $sub=App\Models\SubDivision::create(['project_id'=>$project->id,'division_id'=>$division->id,'code'=>'SD','name'=>'Subdivision']);
 $grid=App\Models\GridStation::create(['project_id'=>$project->id,'sub_division_id'=>$sub->id,'code'=>'G','name'=>'132KV M.Pur']);
 App\Models\Feeder::create(['project_id'=>$project->id,'circle_id'=>$circle->id,'division_id'=>$division->id,'sub_division_id'=>$sub->id,'grid_station_id'=>$grid->id,'feeder_code'=>'105903','feeder_name'=>'Noor Pur','total_transformers'=>0,'baseline_pending'=>true,'status'=>'active']);
}
echo 'QA SQLite ready';
