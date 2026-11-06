param(
    [Parameter(Mandatory=$true)][string]$InputPath,
    [Parameter(Mandatory=$true)][string]$TemplatePath,
    [Parameter(Mandatory=$true)][string]$OutputPath,
    [switch]$LegacyNetwork
)
# This CLI is invoked only with server-owned paths. Remote clients call worker_server.py.
$ErrorActionPreference = 'Stop'
$db = $null
$engine = $null
$inTransaction = $false
$allowed = @('SAI_Control','Node','InstFeeders','InstSection','InstPrimaryTransformers','Loads')
. (Join-Path $PSScriptRoot 'schema-contract.ps1')
function ValuesEqual($expected, $actual) {
    if ($null -eq $expected) { return ($null -eq $actual -or $actual -is [DBNull]) }
    if ($null -eq $actual -or $actual -is [DBNull]) { return $false }
    if ($expected -is [string]) { return ([string]$actual -ceq $expected) }
    if ($expected -is [bool]) { return ([bool]$actual -eq $expected) }
    return ([Math]::Abs([double]$actual - [double]$expected) -le 0.0000001)
}
function ExpectedFieldValue($row, $field) {
    $property = $row.PSObject.Properties[$field.Name]
    $value = if ($null -eq $property) { $null } else { $property.Value }
    # The approved schema requires a few engineering TEXT fields (for example
    # TransformerType), but explicitly allows zero-length values. Keep those
    # missing values blank without changing the schema or using its defaults.
    if ($null -eq $value -and $field.Required -and $field.Type -eq 10 -and $field.AllowZeroLength) { return '' }
    return $value
}
function Add-InstMeterDmdSchemaToLegacyCopy($Database) {
    if (@($Database.TableDefs | ForEach-Object {$_.Name}) -contains 'InstMeterDmd') { return }
    $manifestPath = Join-Path $PSScriptRoot '../../resources/mdb/approved-schema.json'
    $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $schema = Get-MdbSchemaContract $Database
    $fingerprint = Get-MdbSchemaFingerprint $schema
    if ($fingerprint -cne $manifest.schema_extension.legacy_schema_sha256 -or
        @($schema.tables).Count -ne [int]$manifest.schema_extension.legacy_table_count -or
        @($schema.relationships).Count -ne [int]$manifest.schema_extension.legacy_relationship_count) {
        throw 'Template schema is neither the approved current schema nor the approved pre-InstMeterDmd schema.'
    }

    $definition = $manifest.schema.tables | Where-Object {$_.name -ceq 'InstMeterDmd'} | Select-Object -First 1
    if (-not $definition) { throw 'Approved InstMeterDmd definition is missing.' }
    $table = $Database.CreateTableDef('InstMeterDmd')
    foreach ($spec in $definition.fields) {
        if ([int]$spec.type -eq 10) { $field = $table.CreateField([string]$spec.name, [int]$spec.type, [int]$spec.size) }
        else { $field = $table.CreateField([string]$spec.name, [int]$spec.type) }
        if ([int]$spec.type -eq 10) {
            $field.Required = [bool]$spec.required
            $field.AllowZeroLength = [bool]$spec.allow_zero_length
        }
        if (-not [string]::IsNullOrEmpty([string]$spec.default)) { $field.DefaultValue = [string]$spec.default }
        $table.Fields.Append($field)
    }
    $relation = $manifest.schema.relationships | Where-Object {$_.foreign_table -ceq 'InstMeterDmd'} | Select-Object -First 1
    foreach ($indexSpec in $definition.indexes) {
        # DAO creates this nonunique index when the relationship is appended.
        if ($relation -and $indexSpec.name -ceq $relation.name) { continue }
        $index = $table.CreateIndex([string]$indexSpec.name)
        $index.Primary = [bool]$indexSpec.primary
        $index.Unique = [bool]$indexSpec.unique
        $index.Required = [bool]$indexSpec.required
        $index.IgnoreNulls = [bool]$indexSpec.ignore_nulls
        foreach ($indexField in $indexSpec.fields) { $index.Fields.Append($index.CreateField([string]$indexField.name)) }
        $table.Indexes.Append($index)
    }
    $Database.TableDefs.Append($table)
    if (-not $relation) { throw 'Approved InstMeterPoints-to-InstMeterDmd relationship is missing.' }
    $newRelation = $Database.CreateRelation([string]$relation.name, [string]$relation.table, [string]$relation.foreign_table, [int]$relation.attributes)
    foreach ($fieldSpec in $relation.fields) {
        $relationField = $newRelation.CreateField([string]$fieldSpec.name)
        $relationField.ForeignName = [string]$fieldSpec.foreign_name
        $newRelation.Fields.Append($relationField)
    }
    $Database.Relations.Append($newRelation)
    $meterTable = $Database.TableDefs.Item('InstMeterDmd')
    $meterTable.Indexes.Refresh()
    if (@($meterTable.Indexes | ForEach-Object {$_.Name}) -notcontains [string]$relation.name) {
        $index = $meterTable.CreateIndex([string]$relation.name)
        foreach ($fieldSpec in $relation.fields) { $index.Fields.Append($index.CreateField([string]$fieldSpec.foreign_name)) }
        $meterTable.Indexes.Append($index)
    }
    $completed = Get-MdbSchemaContract $Database
    if ((Get-MdbSchemaFingerprint $completed) -cne $manifest.schema_sha256) {
        $expectedTable = $manifest.schema.tables | Where-Object {$_.name -ceq 'InstMeterDmd'} | Select-Object -First 1
        $actualTable = $completed.tables | Where-Object {$_.name -ceq 'InstMeterDmd'} | Select-Object -First 1
        $expectedRelation = $manifest.schema.relationships | Where-Object {$_.foreign_table -ceq 'InstMeterDmd'} | Select-Object -First 1
        $actualRelation = $completed.relationships | Where-Object {$_.foreign_table -ceq 'InstMeterDmd'} | Select-Object -First 1
        $tableDifferences = @()
        foreach ($property in $expectedTable.PSObject.Properties) {
            if ((ConvertTo-Json $property.Value -Depth 20 -Compress) -cne (ConvertTo-Json $actualTable.($property.Name) -Depth 20 -Compress)) { $tableDifferences += $property.Name }
        }
        throw ('InstMeterDmd schema extension differs from its approved definition (table fields/indexes={0}, relation={1}).' -f
            ($tableDifferences -join ','),
            ((ConvertTo-Json $expectedRelation -Depth 20 -Compress) -ceq (ConvertTo-Json $actualRelation -Depth 20 -Compress)))
    }
}
try {
    $payload = Get-Content -LiteralPath $InputPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($LegacyNetwork) {
        # Keep the original survey/audit outside MDB; both entry paths share all
        # schema, explicit-NULL, mapped-value and essential-reference checks.
        $identity = (Get-FileHash -LiteralPath $InputPath -Algorithm SHA256).Hash.ToLowerInvariant()
        $payload = [pscustomobject]@{
            payload_version=1; mapping_version='synergee-reviewed-v1'; idempotency_key=$identity
            revision=[pscustomobject]@{sha256=$identity}
            template=[pscustomobject]@{sha256=(Get-FileHash -LiteralPath $TemplatePath -Algorithm SHA256).Hash.ToLowerInvariant(); permitted_static_tables=@()}
            tables=$payload.tables
        }
    }
    if ($payload.payload_version -ne 1 -or $payload.mapping_version -notin @('synergee-reviewed-v1','synergee-reviewed-v2')) { throw 'Unsupported export payload or mapping version.' }
    if ($payload.mapping_version -eq 'synergee-reviewed-v2') { $allowed += @('InstMeterPoints','InstDGens','InstMymDGens') }
    if ($payload.mapping_version -eq 'synergee-reviewed-v1' -and $payload.tables.PSObject.Properties.Name -contains 'InstMeterPoints') { $allowed += 'InstMeterPoints' }
    if ($payload.tables.PSObject.Properties.Name -contains 'InstMymLoads') { $allowed += 'InstMymLoads' }
    if ($payload.tables.PSObject.Properties.Name -contains 'InstMymMeter') { $allowed += 'InstMymMeter' }
    if ($payload.tables.PSObject.Properties.Name -contains 'InstMymSwDevPos') { $allowed += 'InstMymSwDevPos' }
    if ($payload.tables.PSObject.Properties.Name -contains 'InstMeterDmd') { $allowed += 'InstMeterDmd' }
    if ($payload.idempotency_key -notmatch '^[a-f0-9]{64}$') { throw 'Invalid idempotency key.' }
    if ($payload.revision.sha256 -notmatch '^[a-f0-9]{64}$') { throw 'Invalid revision identity.' }
    $templateHash = (Get-FileHash -LiteralPath $TemplatePath -Algorithm SHA256).Hash.ToLower()
    if ($templateHash -cne $payload.template.sha256) { throw 'Template SHA-256 differs from approved revision.' }
    if (Test-Path -LiteralPath $OutputPath) { throw 'Output path already exists.' }
    $outputFull = [IO.Path]::GetFullPath($OutputPath)
    if ([string]::IsNullOrWhiteSpace($outputFull)) { throw 'Resolved output MDB path is blank.' }
    if ([IO.Path]::GetExtension($outputFull) -cne '.mdb') { throw 'Output must have MDB extension.' }
    Copy-Item -LiteralPath $TemplatePath -Destination $outputFull
    if (-not (Test-Path -LiteralPath $outputFull -PathType Leaf)) { throw 'The disposable MDB copy was not created at its resolved output path.' }
    $engine = New-Object -ComObject DAO.DBEngine.120
    # DBEngine.OpenDatabase and its transaction methods use DAO's default
    # workspace, avoiding workspace collection/custom-workspace COM lookups.
    try {
        $db = $engine.OpenDatabase([string]$outputFull, $true, $false)
    } catch {
        $systemDb = try { [string]$engine.SystemDB } catch { '(unavailable)' }
        throw ("DAO could not open the disposable output MDB '{0}' (SystemDB='{1}'): {2}" -f $outputFull, $systemDb, $_.Exception.Message)
    }
    if (-not $db.Transactions) { throw 'Template does not support transactional MDB writes.' }
    Add-InstMeterDmdSchemaToLegacyCopy $db
    $schemaBefore = Assert-ApprovedMdbSchema $db
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
    $engine.BeginTrans(); $inTransaction=$true
    # Ordering respects the sample relationships: Node -> Feeder -> Section -> Transformer / Loads.
    foreach ($name in $allowed) {
        $definition = $db.TableDefs.Item($name)
        $fieldNames = @($definition.Fields | ForEach-Object {$_.Name})
        $rs=$db.OpenRecordset($name,2)
        foreach ($row in @($payload.tables.$name)) {
            # PHP encodes an empty row as []; it still represents a record with
            # all engineering columns omitted, rather than array metadata.
            if ($row -is [array] -and $row.Count -eq 0) { $row = [pscustomobject]@{} }
            $essential = switch ($name) {
                'Node' { @('NodeId') }
                'InstFeeders' { @('FeederId','SubstationId') }
                'InstSection' { @('SectionId','FeederId','FromNodeId','ToNodeId') }
                'SAI_Control' { @() }
                default { @('SectionId') }
            }
            foreach ($column in $essential) {
                $property = $row.PSObject.Properties[$column]
                if ($null -eq $property -or [string]::IsNullOrWhiteSpace([string]$property.Value)) { throw ('Essential MDB identifier missing: '+$name+'.'+$column) }
            }
            foreach($property in $row.PSObject.Properties) {
                if ($fieldNames -notcontains $property.Name) { throw ('Mapped field does not exist: '+$name+'.'+$property.Name) }
                if ($property.Value -is [System.Management.Automation.PSCustomObject] -or $property.Value -is [array]) { throw 'Mapped values must be scalars.' }
            }
            $rs.AddNew()
            foreach ($field in $definition.Fields) {
                if (($field.Attributes -band 16) -eq 16) { continue }
                $value = ExpectedFieldValue $row $field
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
    $engine.CommitTrans(1); $inTransaction=$false
    $db.Close(); $db=$null
    # Reopen through DAO and compare every field, row, count, reference and schema to the approved payload.
    $db=$engine.OpenDatabase($outputFull,$false,$true)
    $schemaAfter = Assert-ApprovedMdbSchema $db
    if ($schemaAfter -cne $schemaBefore) { throw 'Output schema/relationships changed during export.' }
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
            if ($row -is [array] -and $row.Count -eq 0) { $row = [pscustomobject]@{} }
            $match=-1
            $identity = if($identityColumn -eq '') {'control'} else {[string]$row.PSObject.Properties[$identityColumn].Value}
            if (-not $groups.ContainsKey($identity)) { throw ('Readback identity mismatch: '+$name) }
            foreach($i in @($groups[$identity])) {
                if ($used.ContainsKey($i)) { continue }
                $equal=$true
                foreach($field in $db.TableDefs.Item($name).Fields) {
                    if (($field.Attributes -band 16) -eq 16) { continue }
                    $value=ExpectedFieldValue $row $field
                    if (-not (ValuesEqual $value $rows[$i][$field.Name])) { $equal=$false;break }
                }
                if ($equal) {$match=$i;break}
            }
            if ($match -lt 0) { throw ('Readback mapped value mismatch: '+$name) }
            $used[$match]=$true
        }
    }
    $referenceQueries = @(
        'SELECT COUNT(*) FROM InstSection AS S LEFT JOIN Node AS N ON S.FromNodeId=N.NodeId WHERE N.NodeId IS NULL',
        'SELECT COUNT(*) FROM InstSection AS S LEFT JOIN Node AS N ON S.ToNodeId=N.NodeId WHERE N.NodeId IS NULL',
        'SELECT COUNT(*) FROM InstFeeders AS F LEFT JOIN Node AS N ON F.FeederId=N.NodeId WHERE N.NodeId IS NULL',
        'SELECT COUNT(*) FROM InstSection AS S LEFT JOIN InstFeeders AS F ON S.FeederId=F.FeederId WHERE F.FeederId IS NULL',
        'SELECT COUNT(*) FROM InstPrimaryTransformers AS T LEFT JOIN InstSection AS S ON T.SectionId=S.SectionId WHERE S.SectionId IS NULL',
        'SELECT COUNT(*) FROM Loads AS L LEFT JOIN InstSection AS S ON L.SectionId=S.SectionId WHERE S.SectionId IS NULL'
    )
    if ($payload.mapping_version -eq 'synergee-reviewed-v2') {
        $referenceQueries += @(
            'SELECT COUNT(*) FROM InstMeterPoints AS M LEFT JOIN InstSection AS S ON M.SectionId=S.SectionId WHERE S.SectionId IS NULL',
            'SELECT COUNT(*) FROM InstDGens AS G LEFT JOIN InstSection AS S ON G.SectionId=S.SectionId WHERE S.SectionId IS NULL',
            'SELECT COUNT(*) FROM InstMymDGens AS Y LEFT JOIN InstDGens AS G ON Y.SectionId=G.SectionId WHERE G.SectionId IS NULL'
        )
    }
    if ($payload.tables.PSObject.Properties.Name -contains 'InstMymLoads') {
        $referenceQueries += 'SELECT COUNT(*) FROM InstMymLoads AS L LEFT JOIN InstSection AS S ON L.SectionId=S.SectionId WHERE S.SectionId IS NULL'
    }
    if ($payload.tables.PSObject.Properties.Name -contains 'InstMymMeter') {
        $referenceQueries += 'SELECT COUNT(*) FROM InstMymMeter AS M LEFT JOIN InstSection AS S ON M.SectionId=S.SectionId WHERE S.SectionId IS NULL'
    }
    if ($payload.tables.PSObject.Properties.Name -contains 'InstMymSwDevPos') {
        $referenceQueries += 'SELECT COUNT(*) FROM InstMymSwDevPos AS P LEFT JOIN InstPrimaryTransformers AS T ON P.SectionId=T.SectionId WHERE T.SectionId IS NULL'
    }
    if ($payload.tables.PSObject.Properties.Name -contains 'InstMeterDmd') {
        $referenceQueries += @(
            'SELECT COUNT(*) FROM InstMeterDmd AS D LEFT JOIN InstMeterPoints AS M ON D.SectionId=M.SectionId WHERE M.SectionId IS NULL',
            'SELECT COUNT(*) FROM InstMeterPoints AS M LEFT JOIN InstMeterDmd AS D ON M.SectionId=D.SectionId WHERE D.SectionId IS NULL'
        )
    }
    foreach($query in $referenceQueries) { $rs=$db.OpenRecordset($query,4);if([int]$rs.Fields.Item(0).Value -ne 0){throw 'MDB reference validation failed.'};$rs.Close() }
    $db.Close();$db=$null
    $result=[ordered]@{output_sha256=(Get-FileHash -LiteralPath $outputFull -Algorithm SHA256).Hash.ToLower();payload_sha256=(Get-FileHash -LiteralPath $InputPath -Algorithm SHA256).Hash.ToLower();readback=[ordered]@{readable=$true;schema_verified=$true;schema_sha256=$schemaAfter;schema_table_count=51;schema_relationship_count=42;references_verified=$true;mapped_values_verified=$true;counts=$counts;template_sha256=$templateHash;revision_sha256=$payload.revision.sha256};log='Access DAO reopened MDB; exact approved schema including InstMeterDmd and its relationship, mapped values, counts and model references verified. SynerGEE engineering validation remains pending.'}
    $result | ConvertTo-Json -Depth 10 -Compress
    exit 0
} catch {
    $failure = $_
    if ($inTransaction -and $engine) { try {$engine.Rollback()} catch {} }
    if ($db) { try {$db.Close()} catch {} }
    # Only the fully resolved output path provided by the trusted launcher can be removed; never recursively.
    if ($outputFull -and (Test-Path -LiteralPath $outputFull)) { Remove-Item -LiteralPath $outputFull -Force }
    $location = $failure.InvocationInfo
    $scriptName = if ($location.ScriptName) { [IO.Path]::GetFileName($location.ScriptName) } else { 'export-reviewed-mdb.ps1' }
    $line = if ($location.ScriptLineNumber) { [int]$location.ScriptLineNumber } else { 0 }
    $message = $failure.Exception.GetBaseException().Message
    if ($line -gt 0) { $message += (' ['+$scriptName+':'+$line+']') }
    [Console]::Error.WriteLine($message)
    exit 1
}
