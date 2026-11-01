<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$dir=__DIR__.'/dao-export-'.bin2hex(random_bytes(5));
mkdir($dir,0777,true);
config(['filesystems.disks.dao-probe'=>['driver'=>'local','root'=>resource_path('mdb')], 'mdb_workflow.export.powershell'=>'C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe']);
$p=json_decode(file_get_contents(__DIR__.'/normal-r101-payload.json'),true,512,JSON_THROW_ON_ERROR);
$p['template']['sha256']=hash_file('sha256',resource_path('mdb/synergee-empty.mdb'));
$t=new App\Models\Mdb\Template(['disk'=>'dao-probe','path'=>'synergee-empty.mdb','sha256'=>$p['template']['sha256']]);
$_SERVER=['PATH'=>getenv('PATH')]; $_ENV=[];
$result=(new App\Services\Mdb\WindowsExporter)->export($p,$t,$dir.'/synergee.mdb');
$readback=(new App\Services\Mdb\ReadbackVerifier)->verify($dir.'/synergee.mdb',$p,$result);
echo json_encode(['output'=>$dir.'/synergee.mdb','schema_tables'=>$readback['schema_table_count'],'relationships'=>$readback['schema_relationship_count'],'counts'=>$readback['counts'],'references_verified'=>$readback['references_verified'],'mapped_values_verified'=>$readback['mapped_values_verified']],JSON_PRETTY_PRINT);
