param([string]$Path,[string]$Output)
. (Join-Path $PSScriptRoot '../scripts/mdb/schema-contract.ps1')
$engine=New-Object -ComObject DAO.DBEngine.120;$db=$engine.OpenDatabase($Path,$false,$true)
try{$schema=Get-MdbSchemaContract $db;$obj=[ordered]@{schema_version=1;reference_count=15;schema_sha256=(Get-MdbSchemaFingerprint $schema);schema=$schema};$obj|ConvertTo-Json -Depth 30|Set-Content -LiteralPath $Output -Encoding UTF8;Write-Output ('tables='+$schema.tables.Count+' relations='+$schema.relationships.Count+' sha='+$obj.schema_sha256)}finally{$db.Close()}
