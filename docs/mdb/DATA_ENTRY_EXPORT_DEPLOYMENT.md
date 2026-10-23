# Data Entry MDB downloads from Linux/cPanel

The website can run on Linux. The current MDB writer uses Microsoft Access DAO/ACE and must run on Windows. Installing PowerShell on Linux does not install the Access engine. The previous download path always attempted to run that writer on the web server, which caused the production error.

`DATA_ENTRY_MDB_DRIVER=auto` now selects local generation on Windows and the authenticated HTTPS worker on Linux. `http` and `windows` are explicit overrides. The remote endpoint is `/v1/data-entry-exports`; it is separate from final SynerGEE generation and uses the same existing worker authentication protocol.

## Windows worker

Use a Windows host with an installed Access/ACE runtime exposing `DAO.DBEngine.120`. Deploy these files from the same Git revision:

- `scripts/mdb/worker_server.py`
- `scripts/mdb/export-data-entry.ps1`
- `resources/mdb/data-entry/empty.mdb`
- `resources/mdb/data-entry/schema.json`

Keep this repository directory layout. The worker uses only its bundled template and schema; the website cannot supply executable names, paths or SQL. A separate approved SynerGEE template registry is not required for Data Entry MDB downloads. Existing final MDB exports still require that registry.

Configure the worker service environment:

```dotenv
MDB_WORKER_ROOT=C:\PrivateMdbWorker\exports
MDB_WORKER_PORT=8766
MDB_EXPORT_WORKER_SECRET=<random-secret-of-at-least-32-characters>
MDB_EXPORT_POWERSHELL=C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe
MDB_EXPORT_TIMEOUT=180
```

Use `SysWOW64` for 32-bit ACE or `System32` for 64-bit ACE. Match the installed engine; Windows architecture alone does not determine ACE architecture. Run `python scripts/mdb/worker_server.py` under a supervised service account. The server binds only to `127.0.0.1`; expose it through the existing private, firewall-restricted HTTPS reverse proxy as described in [WORKER.md](WORKER.md). Restart an existing worker after deploying the new endpoint and allow this path through the reverse proxy.

## Linux/cPanel application

After deploying the updated Git revision, set production environment values:

```dotenv
DATA_ENTRY_MDB_DRIVER=http
DATA_ENTRY_MDB_WORKER_URL=https://your-authorized-windows-worker.example
DATA_ENTRY_MDB_WORKER_SECRET=<same-secret-as-MDB_EXPORT_WORKER_SECRET-on-Windows>
DATA_ENTRY_MDB_TIMEOUT=180
```

The URL is the worker base URL, without `/v1/data-entry-exports`. The secret is set privately on both hosts, never committed to Git. If the existing final MDB worker is shared, `MDB_EXPORT_WORKER_URL` and `MDB_EXPORT_WORKER_SECRET` are fallback values when the dedicated settings are absent; the Windows worker must still be updated to support the new endpoint.

In the production application directory:

```sh
git pull --ff-only origin main
php artisan config:cache
```

No database migrations or re-entry of approved observations are needed. This Git update alone cannot supply a Windows host, its Access installation, HTTPS endpoint or service credentials.

Open the approved batch's Data Entry MDB export page and download again. The worker checks the reference schema and reads back every field before returning the MDB; the website verifies the authenticated response, request identity, MDB header, checksum and row counts. The approved snapshot is checked again after generation. Temporary worker files are removed after the response is prepared.

An unreachable worker, mismatched secret, outdated endpoint or mismatched schema produces a validation message, with no incomplete download. Service logs distinguish writer/Access errors. The snapshot approval and access requirements still apply.

## Verification

The PHP tests cover local DAO download, HTTP routing without local Windows tools, request authentication, response integrity, counts, HTTPS configuration, approval and authorization. The worker tests exercise authentication, schema mismatch, Windows/Linux line endings, failure cleanup and a real Windows DAO export when Access is installed:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter=DataEntryMdbExportTest
python tests/Worker/test_data_entry_worker.py
```

Use an isolated SQLite test configuration and set `MDB_EXPORT_POWERSHELL` to the worker's actual ACE-compatible PowerShell path before the Python tests. Production connection and deployment must be verified on the configured servers.
