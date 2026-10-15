param([Parameter(Mandatory=$true)][string]$InputPath,[Parameter(Mandatory=$true)][string]$OutputPath)
$ErrorActionPreference='Stop'
$dao=New-Object -ComObject DAO.DBEngine.120
$db=$dao.OpenDatabase($InputPath,$false,$true)
try {
 $result=[ordered]@{file=[IO.Path]::GetFileName($InputPath);sha256=(Get-FileHash -LiteralPath $InputPath -Algorithm SHA256).Hash.ToLower();tables=@();relationships=@()}
 foreach($table in $db.TableDefs){
  if($table.Name -like 'MSys*'){continue}
  $fields=@();foreach($f in $table.Fields){$fields += [ordered]@{name=$f.Name;type=$f.Type;size=$f.Size;required=$f.Required;allow_zero_length=$f.AllowZeroLength;default=$f.DefaultValue;validation=$f.ValidationRule;attributes=$f.Attributes}}
  $indexes=@();foreach($index in $table.Indexes){$indexes += [ordered]@{name=$index.Name;primary=$index.Primary;unique=$index.Unique;fields=@($index.Fields | ForEach-Object {$_.Name})}}
  $rs=$db.OpenRecordset('SELECT * FROM ['+$table.Name+']',4)
  $rows=@();while(-not $rs.EOF){$row=[ordered]@{};foreach($f in $rs.Fields){$v=$f.Value;if($v -is [DBNull]){$v=$null};$row[$f.Name]=$v};$rows+=$row;$rs.MoveNext()};$rs.Close()
  $result.tables += [ordered]@{name=$table.Name;fields=$fields;indexes=$indexes;count=$rows.Count;rows=$rows}
 }
 foreach($rel in $db.Relations){$result.relationships += [ordered]@{name=$rel.Name;table=$rel.Table;foreign_table=$rel.ForeignTable;attributes=$rel.Attributes;fields=@($rel.Fields | ForEach-Object {[ordered]@{name=$_.Name;foreign_name=$_.ForeignName}})}}
 $result | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $OutputPath -Encoding UTF8
 Write-Output ($result.file+': '+$result.tables.Count+' tables, '+$result.relationships.Count+' relationships')
} finally {$db.Close()}
