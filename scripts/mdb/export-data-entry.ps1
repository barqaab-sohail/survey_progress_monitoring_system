param(
    [Parameter(Mandatory=$true)][string]$InputPath,
    [Parameter(Mandatory=$true)][string]$TemplatePath,
    [Parameter(Mandatory=$true)][string]$SchemaPath,
    [Parameter(Mandatory=$true)][string]$OutputPath
)
$ErrorActionPreference = 'Stop'
$db = $null
$transaction = $false
$created = $false

function Assert-Schema($database, $contract) {
    $tables = @($database.TableDefs | Where-Object { $_.Name -notlike 'MSys*' })
    if ($tables.Count -ne 2 -or $database.Relations.Count -ne 0) { throw 'Unexpected tables or relationships.' }
    foreach ($expected in $contract.tables) {
        $table = $database.TableDefs.Item($expected.name)
        if ($table.Fields.Count -ne $expected.fields.Count -or $table.Indexes.Count -ne $expected.indexes.Count) { throw 'Schema field/index count mismatch.' }
        for ($i=0; $i -lt $expected.fields.Count; $i++) {
            $field = $table.Fields.Item($i); $spec = $expected.fields[$i]
            if ($field.Name -cne $spec.name -or $field.Type -ne $spec.type -or $field.Size -ne $spec.size -or $field.Attributes -ne $spec.attributes -or $field.Required -ne $spec.required -or $field.AllowZeroLength -ne $spec.allow_zero_length -or [string]$field.DefaultValue -cne [string]$spec.default -or [string]$field.ValidationRule -cne [string]$spec.validation) { throw ('Field schema mismatch: '+$table.Name+'.'+$spec.name) }
        }
        foreach ($spec in $expected.indexes) {
            $index = $table.Indexes.Item($spec.name)
            $names = @($index.Fields | ForEach-Object { $_.Name })
            if ($index.Primary -ne $spec.primary -or $index.Unique -ne $spec.unique -or ($names -join '|') -cne ($spec.fields -join '|')) { throw ('Index mismatch: '+$spec.name) }
        }
    }
}

try {
    $payload = Get-Content -LiteralPath $InputPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $schema = Get-Content -LiteralPath $SchemaPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($payload.version -ne 1 -or @($payload.rows).Count -eq 0 -or (@($payload.rows).Count % 2) -ne 0) { throw 'Invalid or empty observation payload.' }
    if (Test-Path -LiteralPath $OutputPath) { throw 'Output already exists.' }
    Copy-Item -LiteralPath $TemplatePath -Destination $OutputPath
    $created = $true
    $engine = New-Object -ComObject DAO.DBEngine.120
    $workspace = $engine.Workspaces.Item(0)
    $db = $workspace.OpenDatabase($OutputPath, $true, $false)
    Assert-Schema $db $schema
    foreach ($name in @('Field_Data','GPS_Points')) {
        $rs = $db.OpenRecordset('SELECT COUNT(*) AS N FROM ['+$name+']',4)
        $count = $rs.Fields.Item('N').Value; $rs.Close()
        if ($count -ne 0) { throw 'Template contains source records.' }
    }
    $workspace.BeginTrans(); $transaction=$true
    $rs = $db.OpenRecordset('Field_Data',2)
    $expectedId=1
    foreach ($row in $payload.rows) {
        if ($row.ID1 -ne $expectedId -or $row.S_E -cne $(if (($expectedId % 2) -eq 1) {'S'} else {'E'}) -or $null -ne $row.x -or $null -ne $row.y) { throw 'Invalid identity, S/E order or coordinate value.' }
        if (($expectedId % 2) -eq 0 -and $row.Equip_Number -cne $previous.Equip_Number) { throw 'S/E transformer identity mismatch.' }
        $previous=$row; $expectedId++
        if (@($row.PSObject.Properties).Count -ne 69) { throw 'Observation must contain exactly 69 fields.' }
        $rs.AddNew()
        foreach ($field in $db.TableDefs.Item('Field_Data').Fields) {
            $property=$row.PSObject.Properties[$field.Name]
            if ($null -eq $property) { throw ('Missing field: '+$field.Name) }
            $value=$property.Value
            if ($null -eq $value) { $rs.Fields.Item($field.Name).Value=[DBNull]::Value }
            else {
                switch ([int]$field.Type) {
                    3 { $rs.Fields.Item($field.Name).Value=[int16]$value }
                    4 { $rs.Fields.Item($field.Name).Value=[int32]$value }
                    7 { $rs.Fields.Item($field.Name).Value=[double]$value }
                    10 {
                        if (([string]$value).Length -gt $field.Size) { throw 'Text exceeds field capacity.' }
                        $rs.Fields.Item($field.Name).Value=[string]$value
                    }
                    12 { $rs.Fields.Item($field.Name).Value=[string]$value }
                    default { throw 'Unsupported field type.' }
                }
            }
        }
        $rs.Update()
    }
    $rs.Close()
    $workspace.CommitTrans(1); $transaction=$false
    $db.Close(); $db=$null
    $db=$workspace.OpenDatabase($OutputPath,$false,$true)
    Assert-Schema $db $schema
    $rs=$db.OpenRecordset('SELECT * FROM Field_Data ORDER BY ID1',4)
    $count=0
    while (-not $rs.EOF) {
        if ($count -ge $payload.rows.Count) { throw 'Unexpected extra observation.' }
        $expected=$payload.rows[$count]
        foreach ($field in $rs.Fields) {
            $value=$expected.PSObject.Properties[$field.Name].Value
            $actual=$field.Value
            if ($null -eq $value) { if ($null -ne $actual -and $actual -isnot [DBNull]) { throw ('NULL readback mismatch: '+$field.Name) } }
            elseif ($actual -is [DBNull] -or $null -eq $actual) { throw ('Missing readback value: '+$field.Name) }
            elseif ($field.Type -in @(10,12)) { if ([string]$actual -cne [string]$value) { throw ('Text readback mismatch: '+$field.Name) } }
            elseif ([double]$actual -ne [double]$value) { throw ('Numeric readback mismatch: '+$field.Name) }
        }
        $count++; $rs.MoveNext()
    }
    $rs.Close()
    if ($count -ne $payload.rows.Count) { throw 'Observation count mismatch.' }
    $rs=$db.OpenRecordset('SELECT COUNT(*) AS N FROM GPS_Points',4)
    if ($rs.Fields.Item('N').Value -ne 0) { throw 'GPS_Points must remain empty.' }; $rs.Close()
    $db.Close(); $db=$null
    [ordered]@{validated=$true;observations=$count;pairs=($count/2);gps_points=0;sha256=(Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()} | ConvertTo-Json -Compress
} catch {
    if ($transaction) { $workspace.Rollback() }
    if ($null -ne $db) { $db.Close(); $db=$null }
    if ($created -and (Test-Path -LiteralPath $OutputPath)) { Remove-Item -LiteralPath $OutputPath -Force }
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
} finally {
    if ($null -ne $db) { $db.Close() }
}
