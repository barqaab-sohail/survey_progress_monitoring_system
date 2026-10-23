$ErrorActionPreference = 'Stop'
$applicationDirectory = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $applicationDirectory
$existingListener = Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort 8766 -State Listen -ErrorAction SilentlyContinue
if ($existingListener) {
    Write-Output ('Worker port already listening; PID '+$existingListener.OwningProcess)
    exit 0
}
$runtimePath = & php (Join-Path $PSScriptRoot 'local-mdb-worker-config.php')
if ($LASTEXITCODE -ne 0) { throw 'Could not prepare local worker configuration.' }
$runtime = Get-Content -LiteralPath $runtimePath -Raw | ConvertFrom-Json
$env:MDB_EXPORT_WORKER_SECRET = $runtime.secret
$env:MDB_EXPORT_POWERSHELL = $runtime.powershell
$env:MDB_WORKER_ROOT = $runtime.root
$env:MDB_WORKER_PORT = '8766'
$pythonPath = (Get-Command python).Source
$workerScript = Join-Path $applicationDirectory 'scripts/mdb/worker_server.py'
$worker = Start-Process -FilePath $pythonPath -ArgumentList @('-B', $workerScript) -WorkingDirectory $applicationDirectory -WindowStyle Hidden -RedirectStandardOutput (Join-Path $applicationDirectory 'storage/logs/local-mdb-worker.out.log') -RedirectStandardError (Join-Path $applicationDirectory 'storage/logs/local-mdb-worker.err.log') -PassThru
Write-Output ('Started local MDB worker; PID '+$worker.Id+'; URL http://127.0.0.1:8766')
