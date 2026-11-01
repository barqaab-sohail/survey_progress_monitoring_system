<?php
require __DIR__.'/../../vendor/autoload.php';
$os = getenv();
$_SERVER = ['PATH' => getenv('PATH')];
$_ENV = [];
$ps = 'C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe';
$script = '$ErrorActionPreference="Stop"; $dao=New-Object -ComObject DAO.DBEngine.120; $db=$dao.OpenDatabase("E:\xampp\htdocs\survey_progress_monitoring_system\resources\mdb\synergee-empty.mdb",$false,$true); Write-Output $db.TableDefs.Count; $db.Close()';
$command = [$ps,'-NoProfile','-NonInteractive','-EncodedCommand',base64_encode(iconv('UTF-8','UTF-16LE',$script))];
$core = App\Services\Mdb\AccessPowerShell::processEnvironment($ps,$os['SystemRoot']??null,$os['WINDIR']??null,$os['ComSpec']??null);
$cases=['current'=>$core,'temp'=>$core+array_intersect_key($os,array_flip(['TEMP','TMP'])),'profile'=>$core+array_intersect_key($os,array_flip(['USERPROFILE','APPDATA','LOCALAPPDATA'])),'programs'=>$core+array_intersect_key($os,array_flip(['ProgramFiles','ProgramFiles(x86)','CommonProgramFiles','CommonProgramFiles(x86)','ProgramW6432','CommonProgramW6432'])),'complete'=>$os];
foreach ($cases as $name=>$env) {
 $p=new Symfony\Component\Process\Process($command,null,$env); $p->run();
 echo $name.' exit='.$p->getExitCode().' output='.trim($p->getOutput()).' error='.substr(trim($p->getErrorOutput()),0,1800).PHP_EOL;
}
