$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$sourceDir = 'C:\Users\sohai\OneDrive\Desktop\HAZECO'
$destDir = Join-Path $PSScriptRoot 'raw'
New-Item -ItemType Directory -Path $destDir -Force | Out-Null
$names = @('Choper HattaT-3030216724.mdb','Choper HattaT-3030216727.mdb','Jodh PurT-4101220409.mdb','Jodh PurT-4151220461.mdb','Jodh PurT-4171220510.mdb','Jodh PurT-4171220531.mdb','Kot Gohar MT-3150216178.mdb')
foreach ($name in $names) {
  $path = Join-Path $sourceDir $name
  $before = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash
  $c = New-Object -ComObject ADODB.Connection
  $c.Open("Provider=Microsoft.ACE.OLEDB.12.0;Data Source=$path;Mode=Read;")
  $schema = $c.OpenSchema(20)
  $tableNames = @()
  while (-not $schema.EOF) {
    if ($schema.Fields.Item('TABLE_TYPE').Value -eq 'TABLE') { $tableNames += [string]$schema.Fields.Item('TABLE_NAME').Value }
    $schema.MoveNext()
  }
  $schema.Close()
  $tables = @()
  foreach ($tableName in $tableNames) {
    $r = New-Object -ComObject ADODB.Recordset
    $r.Open("SELECT * FROM [$tableName]", $c, 0, 1)
    $columns = @()
    foreach ($f in $r.Fields) { $columns += @{name=$f.Name; type=[int]$f.Type; size=[int]$f.DefinedSize; attributes=[int]$f.Attributes} }
    $rows = New-Object System.Collections.Generic.List[object]
    while (-not $r.EOF) {
      $row = [ordered]@{}
      foreach ($f in $r.Fields) {
        $v = $f.Value
        if ($v -is [DBNull]) { $v=$null }
        elseif ($v -is [DateTime]) { $v=$v.ToString('o') }
        elseif ($v -is [byte[]]) { $v=@{binary_base64=[Convert]::ToBase64String($v); bytes=$v.Length} }
        $row[$f.Name] = $v
      }
      $rows.Add($row)
      $r.MoveNext()
    }
    $tables += @{name=$tableName; columns=$columns; rows=$rows.ToArray(); count=$rows.Count}
    $r.Close()
  }
  $c.Close()
  $after = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash
  if ($before -ne $after) { throw "Source hash changed: $name" }
  $result = @{file=$name; path=$path; sha256=$before; tables=$tables}
  $result | ConvertTo-Json -Depth 30 | Set-Content -LiteralPath (Join-Path $destDir ($name+'.json')) -Encoding UTF8
  Write-Output "$name : $($tables.Count) tables, $(($tables | Measure-Object -Property count -Sum).Sum) rows; source hash unchanged"
}
