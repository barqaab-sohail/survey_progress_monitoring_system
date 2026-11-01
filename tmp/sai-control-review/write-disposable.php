<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$transfer=app(App\Services\Mdb\SynergeeTransfer::class);
$package=$transfer->read(file_get_contents('C:/Users/sohai/Downloads/synergee_11140223003_batch-17_r101.json'));
$inspection=$transfer->inspect($package);
if ($inspection['issues']) { throw new RuntimeException(json_encode($inspection['issues'])); }
$after=$inspection['payload'];
$before=$after;
foreach ($after['tables'] as $table=>$rows) {
 if ($table!=='InstMeterPoints' && $rows!==$before['tables'][$table]) { throw new RuntimeException('Unexpected change to '.$table); }
}
file_put_contents(__DIR__.'/after-payload.json',json_encode($after,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_PRETTY_PRINT));
$dir=__DIR__.'/native-'.bin2hex(random_bytes(6)); mkdir($dir,0777,true);
$shell='C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe';
config(['filesystems.disks.loads-review'=>['driver'=>'local','root'=>resource_path('mdb')], 'mdb_workflow.export.powershell'=>$shell]);
$template=new App\Models\Mdb\Template(['disk'=>'loads-review','path'=>'synergee-empty.mdb','sha256'=>hash_file('sha256',resource_path('mdb/synergee-empty.mdb'))]);
$templateHash=$template->sha256;
$_SERVER=['PATH'=>getenv('PATH')]; $_ENV=[];
foreach (['before'=>$before,'after'=>$after] as $name=>$payload) {
 $result=(new App\Services\Mdb\WindowsExporter)->export($payload,$template,$dir.'/'.$name.'.mdb');
 $readback=(new App\Services\Mdb\ReadbackVerifier)->verify($dir.'/'.$name.'.mdb',$payload,$result);
 file_put_contents($dir.'/'.$name.'-readback.json',json_encode($readback,JSON_PRETTY_PRINT));
 $process=new Symfony\Component\Process\Process([$shell,'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',base_path('scripts/mdb/inspect-reference.ps1'),'-InputPath',$dir.'/'.$name.'.mdb','-OutputPath',$dir.'/'.$name.'-inspection.json'],null,App\Services\Mdb\AccessPowerShell::processEnvironment($shell,getenv('SystemRoot')?:null,getenv('WINDIR')?:null,getenv('ComSpec')?:null,realpath($dir)));
 $process->setTimeout(90); $process->mustRun();
}
if (hash_file('sha256',resource_path('mdb/synergee-empty.mdb'))!==$templateHash) { throw new RuntimeException('Template changed'); }
file_put_contents(__DIR__.'/latest-native-directory.txt',$dir);
echo json_encode(['directory'=>$dir,'counts'=>array_map('count',$after['tables']),'other_payload_tables_unchanged'=>true,'template_unchanged'=>true],JSON_PRETTY_PRINT);

