$ErrorActionPreference='Stop'
. ./scripts/mdb/schema-contract.ps1
$outDir=[IO.Path]::GetFullPath('outputs/mdb-comparison-2026-10-26')
$engine=New-Object -ComObject DAO.DBEngine.120
$paths=[ordered]@{generated='C:\Users\sohai\Downloads\synergee_11140223003_batch-17_r79 (1).mdb';reference='C:\Users\sohai\OneDrive\Desktop\HAZECO\Choper HattaT-3030216724.mdb';template=[IO.Path]::GetFullPath('resources/mdb/synergee-empty.mdb')}
$results=@()
foreach($role in $paths.Keys){
 $path=$paths[$role];$before=(Get-FileHash -LiteralPath $path).Hash
 $db=$engine.OpenDatabase($path,$false,$true)
 try{
  $schema=Get-MdbSchemaContract $db
  $tables=@();foreach($t in $db.TableDefs){$tables += [ordered]@{name=$t.Name;attributes=$t.Attributes;connect=$t.Connect}}
  $queries=@();foreach($q in $db.QueryDefs){$queries += [ordered]@{name=$q.Name;type=$q.Type;sql=$q.SQL}}
  $result=[ordered]@{role=$role;path=$path;sha256=$before.ToLower();bytes=(Get-Item -LiteralPath $path).Length;version=$db.Version;fingerprint=(Assert-ApprovedMdbSchema $db);tables=@($schema.tables).Count;fields=($schema.tables | ForEach-Object {$_.fields.Count} | Measure-Object -Sum).Sum;indexes=($schema.tables | ForEach-Object {$_.indexes.Count} | Measure-Object -Sum).Sum;relationships=@($schema.relationships).Count;table_objects=$tables;queries=$queries}
 }finally{$db.Close()}
 # Compact a working copy only. Originals are never opened for writing.
 $copy=Join-Path $outDir ($role+'-working-copy.mdb')
 $compact=Join-Path $outDir ($role+'-compacted.mdb')
 Copy-Item -LiteralPath $path -Destination $copy
 $engine.CompactDatabase($copy,$compact)
 $result.compacted_bytes=(Get-Item -LiteralPath $compact).Length
 $result.reclaimed_bytes=$result.bytes-$result.compacted_bytes
 $db=$engine.OpenDatabase($compact,$false,$true)
 try{$result.compacted_schema_fingerprint=Assert-ApprovedMdbSchema $db}finally{$db.Close()}
 $result.source_unchanged=((Get-FileHash -LiteralPath $path).Hash -eq $before)
 $results+=$result
}
$results | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath (Join-Path $outDir 'schema-and-storage.json') -Encoding UTF8
$results | Select-Object role,bytes,compacted_bytes,reclaimed_bytes,tables,fields,indexes,relationships,source_unchanged | Format-Table
