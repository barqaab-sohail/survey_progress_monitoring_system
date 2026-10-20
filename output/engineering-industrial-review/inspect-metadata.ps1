param([Parameter(Mandatory=$true)][string]$InputPath,[Parameter(Mandatory=$true)][string]$OutputPath)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../../scripts/mdb/schema-contract.ps1')
$path=(Resolve-Path -LiteralPath $InputPath).Path
$before=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
$engine=New-Object -ComObject DAO.DBEngine.120
$db=$engine.OpenDatabase($path,$false,$true)
try {
    $contract=Get-MdbSchemaContract $db
    $fingerprint=Get-MdbSchemaFingerprint $contract
    $manifest=Get-Content -LiteralPath (Join-Path $PSScriptRoot '../../resources/mdb/approved-schema.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $properties=@()
    foreach($property in $db.Properties){
        try {
            $value=$property.Value
            if($value -is [byte[]]){$value='[binary property: '+$value.Length+' bytes]'}
            $properties += [ordered]@{name=$property.Name;type=$property.Type;value=$value}
        } catch { $properties += [ordered]@{name=$property.Name;value='[unavailable]'} }
    }
    $annotations=@()
    foreach($table in $db.TableDefs){
        if($table.Name -like 'MSys*'){continue}
        foreach($field in $table.Fields){
            foreach($name in @('Description','Caption','Format','InputMask','RowSource','RowSourceType')){
                try {$value=$field.Properties.Item($name).Value; if($null -ne $value -and [string]$value -ne ''){$annotations += [ordered]@{table=$table.Name;field=$field.Name;property=$name;value=$value}}} catch {}
            }
        }
    }
    $queries=@($db.QueryDefs | ForEach-Object {[ordered]@{name=$_.Name;type=$_.Type}})
    $result=[ordered]@{file=[IO.Path]::GetFileName($path);sha256=$before;database_version=$db.Version;schema_sha256=$fingerprint;approved_schema_match=($fingerprint -ceq $manifest.schema_sha256);properties=$properties;field_annotations=$annotations;saved_queries=$queries}
} finally {$db.Close()}
if((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $before){throw 'Source file changed'}
$result | ConvertTo-Json -Depth 15 | Set-Content -LiteralPath $OutputPath -Encoding UTF8
Write-Output ($result.file+': canonical schema match='+$result.approved_schema_match+'; field annotations='+$annotations.Count+'; saved queries='+$queries.Count+'; source unchanged')
