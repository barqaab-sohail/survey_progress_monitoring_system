param(
    [Parameter(Mandatory=$true)][string]$InputPath,
    [Parameter(Mandatory=$true)][string]$TemplatePath,
    [Parameter(Mandatory=$true)][string]$OutputPath
)
# Both application entry paths use the exact approved sample schema. Survey
# observations remain in Laravel and private network.json, never extra MDB tables.
# Engineering completeness is decided by Laravel; schema and essential references
# are checked here in both modes.
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'export-reviewed-mdb.ps1') -InputPath $InputPath -TemplatePath $TemplatePath -OutputPath $OutputPath -LegacyNetwork
exit $LASTEXITCODE