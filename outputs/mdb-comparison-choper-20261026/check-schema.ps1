param([string]$OutDir)
$ErrorActionPreference='Stop'
. ./scripts/mdb/schema-contract.ps1
$dao=New-Object -ComObject DAO.DBEngine.120
$paths=@('C:/Users/sohai/Downloads/synergee_11140223003_batch-17_r79.mdb','C:/Users/sohai/OneDrive/Desktop/HAZECO/Choper HattaT-3030216724.mdb')
$results=@()
foreach($path in $paths){
 $db=$dao.OpenDatabase($path,$false,$true)
 try{$s=Get-MdbSchemaContract $db;$result=[ordered]@{file=[IO.Path]::GetFileName($path);fingerprint=(Assert-ApprovedMdbSchema $db);tables=@($s.tables).Count;fields=(@($s.tables | ForEach-Object {$_.fields.Count})|Measure-Object -Sum).Sum;indexes=(@($s.tables | ForEach-Object {$_.indexes.Count})|Measure-Object -Sum).Sum;relationships=@($s.relationships).Count};$results+=$result}finally{$db.Close()}
}
$results | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath "$OutDir/schema-validation.json" -Encoding UTF8
$results | ConvertTo-Json -Depth 5
