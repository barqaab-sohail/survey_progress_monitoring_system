$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../scripts/mdb/schema-contract.ps1')
$path = 'C:\Users\sohai\Downloads\synergee_11140223003_batch-17_r106 (1).mdb'
$manifest = Get-Content (Join-Path $PSScriptRoot '../resources/mdb/approved-schema.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$engine = New-Object -ComObject DAO.DBEngine.120
$db = $engine.OpenDatabase($path, $false, $true)
try {
    $schema = Get-MdbSchemaContract $db
    $fingerprint = Get-MdbSchemaFingerprint $schema
    $counts = foreach ($table in $db.TableDefs) {
        if ($table.Name -like 'MSys*') { continue }
        $rs = $db.OpenRecordset('SELECT COUNT(*) AS N FROM [' + $table.Name.Replace(']', ']]') + ']', 4)
        try { [pscustomobject]@{name=$table.Name; count=[int]$rs.Fields.Item('N').Value} } finally { $rs.Close() }
    }
    [pscustomobject]@{fingerprint=$fingerprint; approved=$manifest.schema_sha256; exact_schema=($fingerprint -ceq $manifest.schema_sha256); tables=$schema.tables.Count; relationships=$schema.relationships.Count; nonempty=@($counts|Where-Object {$_.count -gt 0})} | ConvertTo-Json -Depth 8
} finally { $db.Close() }
