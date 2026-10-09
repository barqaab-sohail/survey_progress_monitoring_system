param(
    [Parameter(Mandatory=$true)][string]$InputPath,
    [Parameter(Mandatory=$true)][string]$TemplatePath,
    [Parameter(Mandatory=$true)][string]$OutputPath
)
# This CLI is invoked only with server-owned paths. Remote clients call worker_server.py.
$ErrorActionPreference = 'Stop'
$db = $null
$workspace = $null
$inTransaction = $false
$allowed = @('SAI_Control','Node','InstFeeders','InstSection','InstPrimaryTransformers','Loads')
function SchemaSignature($database) {
    $schema = [ordered]@{tables=@(); relations=@()}
    foreach ($table in $database.TableDefs) {
        if ($table.Name -like 'MSys*') { continue }
        if ($table.Connect -ne '') { throw 'Linked tables are forbidden in approved templates.' }
        $fields=@(); foreach($field in $table.Fields) { $fields += [ordered]@{name=$field.Name;type=$field.Type;size=$field.Size;required=$field.Required;default=$field.DefaultValue;attributes=$field.Attributes} }
        $indexes=@(); foreach($index in $table.Indexes) { $indexes += [ordered]@{name=$index.Name;primary=$index.Primary;unique=$index.Unique;fields=@($index.Fields | ForEach-Object {$_.Name})} }
        $schema.tables += [ordered]@{name=$table.Name;fields=$fields;indexes=$indexes}
    }
    foreach($relation in $database.Relations) { $schema.relations += [ordered]@{name=$relation.Name;table=$relation.Table;foreign_table=$relation.ForeignTable;attributes=$relation.Attributes;fields=@($relation.Fields | ForEach-Object {[ordered]@{name=$_.Name;foreign_name=$_.ForeignName}})} }
    return ($schema | ConvertTo-Json -Depth 15 -Compress)
}
function ValuesEqual($expected, $actual) {
    if ($null -eq $expected) { return ($null -eq $actual -or $actual -is [DBNull]) }
    if ($null -eq $actual -or $actual -is [DBNull]) { return $false }
    if ($expected -is [string]) { return ([string]$actual -ceq $expected) }
    if ($expected -is [bool]) { return ([bool]$actual -eq $expected) }
    return ([Math]::Abs([double]$actual - [double]$expected) -le 0.0000001)
}
try {
    $payload = Get-Content -LiteralPath $InputPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($payload.payload_version -ne 1 -or $payload.mapping_version -ne 'synergee-reviewed-v1') { throw 'Unsupported export payload or mapping version.' }
    if ($payload.idempotency_key -notmatch '^[a-f0-9]{64}$') { throw 'Invalid idempotency key.' }
    if ($payload.revision.sha256 -notmatch '^[a-f0-9]{64}$') { throw 'Invalid revision identity.' }
    $templateHash = (Get-FileHash -LiteralPath $TemplatePath -Algorithm SHA256).Hash.ToLower()
    if ($templateHash -cne $payload.template.sha256) { throw 'Template SHA-256 differs from approved revision.' }
    if (Test-Path -LiteralPath $OutputPath) { throw 'Output path already exists.' }
    $outputFull = [IO.Path]::GetFullPath($OutputPath)
    if ([IO.Path]::GetExtension($outputFull) -cne '.mdb') { throw 'Output must have MDB extension.' }
    Copy-Item -LiteralPath $TemplatePath -Destination $outputFull
    $engine = New-Object -ComObject DAO.DBEngine.120
    $workspace = $engine.Workspaces.Item(0)
    $db = $workspace.OpenDatabase($outputFull, $true, $false)
    if (-not $db.Transactions) { throw 'Template does not support transactional MDB writes.' }
    $schemaBefore = SchemaSignature $db
    $actualTables = @($db.TableDefs | ForEach-Object {$_.Name})
    foreach ($name in $allowed) {
        if ($actualTables -notcontains $name -or $payload.tables.PSObject.Properties.Name -notcontains $name) { throw ('Required SynerGEE table missing: '+$name) }
    }
    foreach ($property in $payload.tables.PSObject.Properties) {
        if ($allowed -notcontains $property.Name) { throw 'Payload includes a nonpermitted table.' }
    }
    # No unrelated model/control records may survive. Explicit static library tables alone may contain data.
    foreach ($table in $db.TableDefs) {
        if ($table.Name -like 'MSys*') { continue }
        $rs=$db.OpenRecordset('SELECT COUNT(*) AS N FROM ['+$table.Name+']',4)
        $count=[int]$rs.Fields.Item('N').Value; $rs.Close()
        if ($count -gt 0) {
            if ($table.Name -match '^(Inst|Node$|Loads$|SAI_Control$|Gnodes$|Survey)') { throw ('Template contains network or project data: '+$table.Name) }
            if (@($payload.template.permitted_static_tables) -notcontains $table.Name) { throw ('Unreviewed static template data: '+$table.Name) }
        }
    }
    $workspace.BeginTrans(); $inTransaction=$true
    # Ordering respects the sample relationships: Node -> Feeder -> Section -> Transformer / Loads.
    foreach ($name in $allowed) {
        $definition = $db.TableDefs.Item($name)
        $fieldNames = @($definition.Fields | ForEach-Object {$_.Name})
        $rs=$db.OpenRecordset($name,2)
        foreach ($row in @($payload.tables.$name)) {
            foreach($property in $row.PSObject.Properties) {
                if ($fieldNames -notcontains $property.Name) { throw ('Mapped field does not exist: '+$name+'.'+$property.Name) }
                if ($property.Value -is [System.Management.Automation.PSCustomObject] -or $property.Value -is [array]) { throw 'Mapped values must be scalars.' }
            }
            $rs.AddNew()
            foreach ($field in $definition.Fields) {
                if (($field.Attributes -band 16) -eq 16) { continue }
                $property = $row.PSObject.Properties[$field.Name]
                $value = if ($null -eq $property) { $null } else { $property.Value }
                if ($field.Required -and $null -eq $value) { throw ('Explicit required value missing: '+$name+'.'+$field.Name) }
                if ($null -ne $value -and $field.Type -eq 10 -and ([string]$value).Length -gt $field.Size) { throw ('Mapped text exceeds MDB size: '+$name+'.'+$field.Name) }
                # Null omitted columns explicitly. MDB schema defaults must never invent electrical values.
                try {
                    if ($null -eq $value) { $rs.Fields.Item($field.Name).Value = [DBNull]::Value }
                    else {
                        switch ([int]$field.Type) {
                            1 { $rs.Fields.Item($field.Name).Value = [bool]$value }
                            2 { $rs.Fields.Item($field.Name).Value = [byte]$value }
                            3 { $rs.Fields.Item($field.Name).Value = [int16]$value }
                            4 { $rs.Fields.Item($field.Name).Value = [int32]$value }
                            5 { $rs.Fields.Item($field.Name).Value = [decimal]$value }
                            6 { $rs.Fields.Item($field.Name).Value = [single]$value }
                            7 { $rs.Fields.Item($field.Name).Value = [double]$value }
                            8 { $rs.Fields.Item($field.Name).Value = [datetime]$value }
                            10 { $rs.Fields.Item($field.Name).Value = [string]$value }
                            12 { $rs.Fields.Item($field.Name).Value = [string]$value }
                            default { throw 'Unsupported DAO field type.' }
                        }
                    }
                } catch { throw ('Cannot assign '+$name+'.'+$field.Name+': '+$_.Exception.Message) }
            }
            $rs.Update()
        }
        $rs.Close()
    }
    $workspace.CommitTrans(1); $inTransaction=$false
    $db.Close(); $db=$null
    # Reopen through DAO and compare every field, row, count, reference and schema to the approved payload.
    $db=$workspace.OpenDatabase($outputFull,$false,$true)
    if ((SchemaSignature $db) -cne $schemaBefore) { throw 'Output schema/relationships changed during export.' }
    $counts=[ordered]@{}
    foreach ($name in $allowed) {
        $expected=@($payload.tables.$name)
        $rs=$db.OpenRecordset('SELECT * FROM ['+$name+']',4)
        $rows=@(); while(-not $rs.EOF) { $actual=[ordered]@{};foreach($field in $rs.Fields){$actual[$field.Name]=$field.Value};$rows+=$actual;$rs.MoveNext() };$rs.Close()
        $counts[$name]=$rows.Count
        if ($rows.Count -ne $expected.Count) { throw ('Readback count mismatch: '+$name) }
        $identityColumn = switch($name) {'Node' {'NodeId'} 'InstFeeders' {'FeederId'} 'SAI_Control' {''} default {'SectionId'}}
        $groups=@{}
        for($i=0;$i -lt $rows.Count;$i++) {
            $identity = if($identityColumn -eq '') {'control'} else {[string]$rows[$i][$identityColumn]}
            if (-not $groups.ContainsKey($identity)) { $groups[$identity]=@() }
            $groups[$identity]+=$i
        }
        $used=@{}
        foreach($row in $expected) {
            $match=-1
            $identity = if($identityColumn -eq '') {'control'} else {[string]$row.PSObject.Properties[$identityColumn].Value}
            if (-not $groups.ContainsKey($identity)) { throw ('Readback identity mismatch: '+$name) }
            foreach($i in @($groups[$identity])) {
                if ($used.ContainsKey($i)) { continue }
                $equal=$true
                foreach($field in $db.TableDefs.Item($name).Fields) {
                    if (($field.Attributes -band 16) -eq 16) { continue }
                    $property=$row.PSObject.Properties[$field.Name]
                    $value=if($null -eq $property){$null}else{$property.Value}
                    if (-not (ValuesEqual $value $rows[$i][$field.Name])) { $equal=$false;break }
                }
                if ($equal) {$match=$i;break}
            }
            if ($match -lt 0) { throw ('Readback mapped value mismatch: '+$name) }
            $used[$match]=$true
        }
    }
    foreach($query in @(
        'SELECT COUNT(*) FROM InstSection AS S LEFT JOIN Node AS N ON S.FromNodeId=N.NodeId WHERE N.NodeId IS NULL',
        'SELECT COUNT(*) FROM InstSection AS S LEFT JOIN Node AS N ON S.ToNodeId=N.NodeId WHERE N.NodeId IS NULL',
        'SELECT COUNT(*) FROM InstFeeders AS F LEFT JOIN Node AS N ON F.FeederId=N.NodeId WHERE N.NodeId IS NULL',
        'SELECT COUNT(*) FROM InstSection AS S LEFT JOIN InstFeeders AS F ON S.FeederId=F.FeederId WHERE F.FeederId IS NULL',
        'SELECT COUNT(*) FROM InstPrimaryTransformers AS T LEFT JOIN InstSection AS S ON T.SectionId=S.SectionId WHERE S.SectionId IS NULL',
        'SELECT COUNT(*) FROM Loads AS L LEFT JOIN InstSection AS S ON L.SectionId=S.SectionId WHERE S.SectionId IS NULL'
    )) { $rs=$db.OpenRecordset($query,4);if([int]$rs.Fields.Item(0).Value -ne 0){throw 'MDB reference validation failed.'};$rs.Close() }
    $db.Close();$db=$null
    $result=[ordered]@{output_sha256=(Get-FileHash -LiteralPath $outputFull -Algorithm SHA256).Hash.ToLower();payload_sha256=(Get-FileHash -LiteralPath $InputPath -Algorithm SHA256).Hash.ToLower();readback=[ordered]@{readable=$true;schema_verified=$true;references_verified=$true;mapped_values_verified=$true;counts=$counts;template_sha256=$templateHash;revision_sha256=$payload.revision.sha256};log='Access DAO reopened MDB; schema, relationships, exact mapped values, counts and model references verified. SynerGEE engineering validation remains pending.'}
    $result | ConvertTo-Json -Depth 10 -Compress
    exit 0
} catch {
    if ($inTransaction -and $workspace) { try {$workspace.Rollback()} catch {} }
    if ($db) { try {$db.Close()} catch {} }
    # Only the fully resolved output path provided by the trusted launcher can be removed; never recursively.
    if ($outputFull -and (Test-Path -LiteralPath $outputFull)) { Remove-Item -LiteralPath $outputFull -Force }
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
