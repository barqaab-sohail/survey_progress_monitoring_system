$ErrorActionPreference = 'Stop'
$path = 'W:\2120 HAZECO T&D Losses Project\HAZECO Data\MEPCO 1st Data Package\Sample Data\Noor PurT-4221215879.mdb'
$dao = New-Object -ComObject DAO.DBEngine.120
$db = $dao.OpenDatabase($path, $false, $true)
$result = [ordered]@{sha256=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLower(); tables=@(); relationships=@()}
foreach ($table in $db.TableDefs) {
 if ($table.Name -like 'MSys*') { continue }
 $fields=@(); foreach($field in $table.Fields) {
  $fields += [ordered]@{name=$field.Name;dao_type=$field.Type;size=$field.Size;required=$field.Required;allow_zero_length=$field.AllowZeroLength;default=$field.DefaultValue;validation=$field.ValidationRule}
 }
 $indexes=@(); foreach($index in $table.Indexes) { $indexes += [ordered]@{name=$index.Name;primary=$index.Primary;unique=$index.Unique;fields=@($index.Fields | ForEach-Object {$_.Name})} }
 $rs=$db.OpenRecordset('SELECT * FROM ['+$table.Name+']',4)
 $rows=@(); while(-not $rs.EOF) { $row=[ordered]@{};foreach($field in $rs.Fields){$row[$field.Name]=$field.Value};$rows+=$row;$rs.MoveNext() }
 $rs.Close()
 $result.tables += [ordered]@{name=$table.Name;fields=$fields;indexes=$indexes;count=$rows.Count;rows=$rows}
}
foreach($relation in $db.Relations) { $result.relationships += [ordered]@{name=$relation.Name;table=$relation.Table;foreign_table=$relation.ForeignTable;attributes=$relation.Attributes;fields=@($relation.Fields | ForEach-Object {[ordered]@{name=$_.Name;foreign_name=$_.ForeignName}})} }
$db.Close()
$result | ConvertTo-Json -Depth 15 | Set-Content -LiteralPath 'E:\xampp\htdocs\survey_progress_monitoring_system\tmp\mdb-inspection\workflow-sample-inspection.json' -Encoding UTF8
Write-Output ('Tables: '+$result.tables.Count+'; relationships: '+$result.relationships.Count)
