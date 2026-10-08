<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$qaPath=__DIR__.'/browser.sqlite';
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$qaPath]);
Illuminate\Support\Facades\DB::purge('sqlite');
$count=0;
foreach(App\Models\TransformerMdbProject::all() as $project){
 foreach(['pdf_path','gpx_path'] as $key){$path=$project->$key;if($path && str_starts_with($path,'mdb-builder/sources/') && !str_contains($path,'..')){Illuminate\Support\Facades\Storage::disk('local')->delete($path);$count++;}}
}
echo "Removed $count QA upload copies\n";
