param(
    [string]$Device = 'emulator-5554'
)

$ErrorActionPreference = 'Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    & flutter run --debug -d $Device '--dart-define=API_BASE_URL=http://10.0.2.2:8000'
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
} finally {
    Pop-Location
}
