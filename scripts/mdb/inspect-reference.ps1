param(
    [Parameter(Mandatory=$true)][string]$InputPath,
    [Parameter(Mandatory=$true)][string]$OutputPath
)
# Run with the PowerShell architecture matching installed DAO/ACE (locally: SysWOW64).
# Reads table data only; saved Access queries, macros and modules are never executed.
$ErrorActionPreference = 'Stop'
$inputFull = (Resolve-Path -LiteralPath $InputPath).Path
$outputFull = [IO.Path]::GetFullPath($OutputPath)
if ([IO.Path]::GetExtension($outputFull) -ine '.json' -or $inputFull -ieq $outputFull) { throw 'Inspection output must be a separate JSON file.' }
$hashBefore = (Get-FileHash -LiteralPath $inputFull -Algorithm SHA256).Hash.ToLower()
$dao = New-Object -ComObject DAO.DBEngine.120
$db = $dao.OpenDatabase($inputFull,$false,$true)
try {
    $result = [ordered]@{file=[IO.Path]::GetFileName($inputFull);sha256=$hashBefore;tables=@();relationships=@()}
    foreach ($table in $db.TableDefs) {
        if ($table.Name -like 'MSys*') { continue }
        if ($table.Connect -ne '') { throw ('Linked reference table is unsupported: '+$table.Name) }
        $fields=@()
        foreach ($field in $table.Fields) {
            $fields += [ordered]@{name=$field.Name;type=$field.Type;size=$field.Size;required=$field.Required;allow_zero_length=$field.AllowZeroLength;default=$field.DefaultValue;validation=$field.ValidationRule;attributes=$field.Attributes}
        }
        $indexes=@()
        foreach ($index in $table.Indexes) {
            $indexes += [ordered]@{name=$index.Name;primary=$index.Primary;unique=$index.Unique;fields=@($index.Fields | ForEach-Object {$_.Name})}
        }
        $rs=$db.OpenRecordset('SELECT * FROM ['+$table.Name.Replace(']',']]')+']',4)
        try {
            $rows = New-Object 'System.Collections.Generic.List[object]'
            while (-not $rs.EOF) {
                $row=[ordered]@{}
                foreach ($field in $rs.Fields) {
                    $value=$field.Value
                    if ($value -is [DBNull]) { $value=$null }
                    $row[$field.Name]=$value
                }
                $rows.Add($row)
                $rs.MoveNext()
            }
            $result.tables += [ordered]@{name=$table.Name;fields=$fields;indexes=$indexes;count=$rows.Count;rows=@($rows.ToArray())}
        } finally { $rs.Close() }
    }
    foreach ($relation in $db.Relations) {
        $result.relationships += [ordered]@{name=$relation.Name;table=$relation.Table;foreign_table=$relation.ForeignTable;attributes=$relation.Attributes;fields=@($relation.Fields | ForEach-Object {[ordered]@{name=$_.Name;foreign_name=$_.ForeignName}})}
    }
} finally { $db.Close() }
if ((Get-FileHash -LiteralPath $inputFull -Algorithm SHA256).Hash.ToLower() -cne $hashBefore) { throw 'Reference bytes changed during inspection.' }
$result | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $outputFull -Encoding UTF8
Write-Output ($result.file+': '+$result.tables.Count+' tables, '+$result.relationships.Count+' relationships; source SHA-256 unchanged')
