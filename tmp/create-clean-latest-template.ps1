$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../scripts/mdb/schema-contract.ps1')
$source = 'C:\Users\sohai\Downloads\synergee_11140223003_batch-17_r106 (1).mdb'
$outDir = Join-Path $PSScriptRoot '../output'
$target = Join-Path $outDir 'Clean_SynerGEE_Template_r106.mdb'
$working = Join-Path $outDir 'Clean_SynerGEE_Template_r106_working.mdb'
$compact = Join-Path $outDir 'Clean_SynerGEE_Template_r106_compact.mdb'
$sourceHash = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash
if (Test-Path -LiteralPath $target) { throw "Refusing to overwrite existing output: $target" }
if (Test-Path -LiteralPath $working) { throw "Refusing to overwrite existing work file: $working" }
if (Test-Path -LiteralPath $compact) { throw "Refusing to overwrite existing compact file: $compact" }
Copy-Item -LiteralPath $source -Destination $working
$engine = New-Object -ComObject DAO.DBEngine.120
$db = $engine.OpenDatabase($working, $false, $false)
$transaction = $false
try {
    $before = Assert-ApprovedMdbSchema $db
    if ($before -cne '4a7845398bc0dd699db8fffe8fa26aa613d9a7c263604172d78461fc97e48696') { throw 'The copied MDB schema is not the approved schema.' }
    $remaining = [System.Collections.Generic.List[string]]::new()
    foreach ($table in $db.TableDefs) {
        if ($table.Name -like 'MSys*') { continue }
        if ($table.Name -match '^(Inst|Node$|Loads$|SAI_Control$|Gnodes$|Survey)') {
            $rs = $db.OpenRecordset('SELECT COUNT(*) AS N FROM [' + $table.Name.Replace(']', ']]') + ']', 4)
            try { if ([int]$rs.Fields.Item('N').Value -gt 0) { $remaining.Add([string]$table.Name) } } finally { $rs.Close() }
        }
    }
    $engine.BeginTrans(); $transaction = $true
    while ($remaining.Count -gt 0) {
        $ready = @()
        foreach ($name in @($remaining.ToArray())) {
            $hasRemainingChild = $false
            foreach ($relation in $db.Relations) {
                if ($relation.Table -ceq $name -and $remaining.Contains([string]$relation.ForeignTable)) { $hasRemainingChild = $true; break }
            }
            if (-not $hasRemainingChild) { $ready += $name }
        }
        if ($ready.Count -eq 0) { throw ('Could not order dependent network tables safely: ' + ($remaining -join ', ')) }
        foreach ($name in $ready) {
            $db.Execute('DELETE FROM [' + $name.Replace(']', ']]') + ']', 128)
            [void]$remaining.Remove($name)
        }
    }
    $engine.CommitTrans(); $transaction = $false
} catch {
    if ($transaction) { try { $engine.Rollback() } catch {} }
    throw
} finally { $db.Close() }
if ((Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash -cne $sourceHash) { throw 'The attached source MDB changed during template creation.' }
$engine.CompactDatabase($working, $compact)
$checkDb = $engine.OpenDatabase($compact, $false, $true)
try {
    $schemaAfter = Assert-ApprovedMdbSchema $checkDb
    $remainingRows = @()
    foreach ($table in $checkDb.TableDefs) {
        if ($table.Name -like 'MSys*' -or $table.Name -notmatch '^(Inst|Node$|Loads$|SAI_Control$|Gnodes$|Survey)') { continue }
        $rs = $checkDb.OpenRecordset('SELECT COUNT(*) AS N FROM [' + $table.Name.Replace(']', ']]') + ']', 4)
        try { $count = [int]$rs.Fields.Item('N').Value; if ($count -gt 0) { $remainingRows += [pscustomobject]@{table=$table.Name; count=$count} } } finally { $rs.Close() }
    }
    if ($remainingRows.Count -gt 0) { throw ('Network/project rows remain after cleanup: ' + (ConvertTo-Json $remainingRows -Compress)) }
} finally { $checkDb.Close() }
Move-Item -LiteralPath $compact -Destination $target
Remove-Item -LiteralPath $working
[pscustomobject]@{output=$target;schema_sha256=$schemaAfter;table_count=51;relationship_count=42;remaining_network_rows=0;source_sha256=$sourceHash;output_sha256=(Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash} | ConvertTo-Json -Compress
