# Shared, mode-independent schema contract for the fifteen approved reference MDBs.
# This file only inspects DAO metadata. It never changes database objects.
function Get-MdbSchemaContract($Database) {
    $tables = [System.Collections.Generic.SortedDictionary[string,object]]::new([StringComparer]::Ordinal)
    foreach ($table in $Database.TableDefs) {
        if ($table.Name -like 'MSys*') { continue }
        if ($table.Connect -ne '') { throw 'Linked MDB tables are forbidden.' }
        $fields = @()
        foreach ($field in $table.Fields) {
            $fields += [ordered]@{
                name = [string]$field.Name; type = [int]$field.Type; size = [int]$field.Size
                required = [bool]$field.Required; allow_zero_length = [bool]$field.AllowZeroLength
                default = [string]$field.DefaultValue; validation = [string]$field.ValidationRule
                attributes = [int]$field.Attributes
            }
        }
        $indexes = [System.Collections.Generic.SortedDictionary[string,object]]::new([StringComparer]::Ordinal)
        foreach ($index in $table.Indexes) {
            $indexFields = @()
            foreach ($field in $index.Fields) { $indexFields += [ordered]@{name=[string]$field.Name; attributes=[int]$field.Attributes} }
            $indexes.Add([string]$index.Name, [ordered]@{
                name=[string]$index.Name; primary=[bool]$index.Primary; unique=[bool]$index.Unique
                required=[bool]$index.Required; ignore_nulls=[bool]$index.IgnoreNulls; fields=$indexFields
            })
        }
        $tables.Add([string]$table.Name, [ordered]@{name=[string]$table.Name; fields=$fields; indexes=@($indexes.Values)})
    }
    $relations = [System.Collections.Generic.SortedDictionary[string,object]]::new([StringComparer]::Ordinal)
    foreach ($relation in $Database.Relations) {
        $fields = @()
        foreach ($field in $relation.Fields) { $fields += [ordered]@{name=[string]$field.Name; foreign_name=[string]$field.ForeignName} }
        $relations.Add([string]$relation.Name, [ordered]@{
            name=[string]$relation.Name; table=[string]$relation.Table; foreign_table=[string]$relation.ForeignTable
            attributes=[int]$relation.Attributes; fields=$fields
        })
    }
    return [ordered]@{tables=@($tables.Values); relationships=@($relations.Values)}
}

function Get-MdbSchemaFingerprint($Schema) {
    $json = $Schema | ConvertTo-Json -Depth 30 -Compress
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($json)))).Replace('-','').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Assert-ApprovedMdbSchema($Database) {
    $manifestPath = Join-Path $PSScriptRoot '../../resources/mdb/approved-schema.json'
    $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($manifest.schema_version -ne 1 -or $manifest.reference_count -ne 15 -or
        @($manifest.schema.tables).Count -ne 50 -or @($manifest.schema.relationships).Count -ne 41 -or
        (Get-MdbSchemaFingerprint $manifest.schema) -cne $manifest.schema_sha256) {
        throw 'Approved MDB schema manifest is invalid.'
    }
    $fingerprint = Get-MdbSchemaFingerprint (Get-MdbSchemaContract $Database)
    if ($fingerprint -cne $manifest.schema_sha256) {
        throw 'MDB schema does not match the 15 approved samples (tables, fields, types, defaults, indexes and relationships).'
    }
    return $fingerprint
}
