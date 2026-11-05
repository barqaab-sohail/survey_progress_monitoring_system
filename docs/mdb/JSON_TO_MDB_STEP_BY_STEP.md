# JSON download, local MDB conversion, and automatic worker connection

Prepared from the application code inspected on 3 November 2026. This document describes the current implementation; it does not deploy or change production, DNS, or Windows services. Paths below are relative to `E:\xampp\htdocs\survey_progress_monitoring_system` unless an absolute path is given.

## 1. Identify your output

| Production button | Local conversion page | Output |
| --- | --- | --- |
| Download Transformer JSON | JSON to MDB: `/mdb-workflow/offline-mdb` | Data Entry MDB, with `Field_Data` and `GPS_Points` |
| Download SynerGEE JSON | JSON to SynerGEE: `/mdb-workflow/offline-synergee` | SynerGEE network MDB, with nodes, sections, loads, transformer and other model tables |

These JSON formats are different and are not interchangeable. Data Entry JSON has format `hazeco-transformer-data`; SynerGEE JSON has format `hazeco-synergee-transfer`.

## 2. Current Data Entry JSON download: production

### Step 1: Open the export page

Sign in, open a currently approved survey and select **Export Data Entry MDB**.

Request: `GET /mdb-workflow/{batch}/data-entry-mdb`.

1. `routes/web.php` includes `routes/mdb-workflow.php`.
2. `routes/mdb-workflow.php` selects `DataEntryMdbExportController::show()`.
3. `app/Http/Controllers/DataEntryMdbExportController.php` checks export access using `WorkflowAccess` and prepares the approved data using `DataEntryMdbExport::prepare()`.
4. `resources/views/mdb-workflow/data-entry-export.blade.php` displays the transformer JSON links and the direct Data Entry MDB download button.

### Step 2: Click Download Transformer JSON

Request: `GET /mdb-workflow/{batch}/transformers/{transformer}/transfer-json?revision_sha256=...`.

The page supplies the real IDs and revision hash; do not construct these manually.

1. `DataEntryMdbExportController::transformerJson()` checks access.
2. `app/Services/Mdb/TransformerTransfer.php`, method `export()`, calls `DataEntryMdbExport::prepare($batch, $transformerId)`.
3. `app/Services/Mdb/DataEntryMdbExport.php`, method `prepare()`, checks the current approval and frozen snapshot checksum. Staged surveys also require independent verification.
4. `app/Services/Mdb/DataEntryPayload.php`, method `build()`, checks original observations, verified headers/rows, S/E pairing and phase consistency, and prepares the legacy field mapping.
5. `TransformerTransfer::export()` packages the original selected transformer, source batch/revision/hash/export time and a package checksum using `SnapshotService::hash()`.
6. The controller checks the requested revision hash and the 10 MB limit, then streams the `.json` download.

The source is the approved revision snapshot, not a fresh unapproved copy of live entry rows. The transfer checksum detects changes; it is not a digital signature proving origin.

## 3. Current Data Entry JSON upload and MDB generation: local Windows

### Step 3: Open JSON to MDB

Open your existing local site and sign in, then open `/mdb-workflow/offline-mdb`.

1. The route calls `app/Http/Controllers/OfflineMdbController.php`, method `index()`.
2. Its `authorizeConversion()` requires export permission, Windows, and the enabled offline conversion feature.
3. `resources/views/mdb-workflow/offline-mdb.blade.php` displays the upload form.

### Step 4: Upload and preview

Request: `POST /mdb-workflow/offline-mdb/preview`.

1. `OfflineMdbController::preview()` validates the `.json` upload, up to 10 MB.
2. `TransformerTransfer::read()` decodes JSON, validates its format/version/fields and recomputes the package checksum.
3. `DataEntryPayload::build()` maps the original observations to the legacy fields and checks S/E pairs and other required observations.
4. The view displays the preview and retains the original JSON in the generation form.

No survey or approval is imported into the local database.

### Step 5: Generate and download MDB

Request: `POST /mdb-workflow/offline-mdb/download`.

1. `OfflineMdbController::download()` reads and validates the package again.
2. It calls `DataEntryMdbExport::generate($payload, 'windows')`. This explicitly selects Windows generation, irrespective of the default export driver.
3. `config/data_entry_mdb.php` supplies PowerShell, timeout and other settings. `app/Services/Mdb/AccessPowerShell.php` supplies engine-compatible PowerShell selection when no explicit override exists.
4. `DataEntryMdbExport` writes a temporary payload under `storage/app/private/data-entry-mdb`.
5. PHP starts `scripts/mdb/export-data-entry.ps1` with the temporary payload, `resources/mdb/data-entry/empty.mdb`, `resources/mdb/data-entry/schema.json` and an output path.
6. PowerShell uses Microsoft Access DAO/ACE to write the MDB and read it back against the expected fields/schema.
7. PHP checks the reported validation, observation count and output SHA-256.
8. The controller downloads the MDB and schedules deletion of the temporary output after sending it. The temporary input is removed by the service.

The exact legacy format leaves GPS coordinates NULL and `GPS_Points` empty. Other mapping limitations are displayed in the preview.

## 4. Current SynerGEE JSON download and local conversion

### Step 1: Export on production

On the approved survey Review or generation details page, choose one transformer or the supported feeder scope, then click **Download SynerGEE JSON**.

Request: `GET /mdb-workflow/{batch}/synergee-json`, with the selected transformer and revision hash supplied by the form.

1. `resources/views/mdb-workflow/partials/synergee-transfer.blade.php` supplies the form.
2. `app/Http/Controllers/SynergeeOfflineController.php`, method `export()`, checks export access, staged generation access where applicable, and the requested approved revision under a database lock.
3. `app/Services/Mdb/SynergeeTransfer.php`, method `export()`, calls `ExportService::assertApproved()`, independently verifies the frozen revision checksum, checks the selected observations and verifies the clean template.
4. The package contains the complete frozen snapshot, selected output scope, coordinates, engineering settings, source hashes, schema fingerprint and the clean MDB template as Base64.
5. `app/Services/Mdb/ApprovedSchema.php` supplies the supported schema fingerprint. `SnapshotService` supplies hashing. `AuditService` records the export.
6. The controller streams JSON, limited to 30 MB. The embedded template is limited to 15 MB.

The package retains the full revision even for a one-transformer output; it may contain other transformers from that authorized batch.

### Step 2: Upload on Windows

Open `/mdb-workflow/offline-synergee`.

1. `SynergeeOfflineController::index()` checks local conversion access and renders `resources/views/mdb-workflow/offline-synergee.blade.php`.
2. Upload the SynerGEE JSON. An original GPX is optional; complete verified coordinates already travel inside JSON.
3. `POST /mdb-workflow/offline-synergee/preview` calls `SynergeeOfflineController::preview()`.
4. `SynergeeTransfer::read()` verifies package/revision/template hashes, supported schema, package identities and scope.
5. `SynergeeTransfer::inspect()` checks coordinates and S/E-to-section/PV mappings, calls `NetworkValidator`, and builds the table payload through `ExportPayloadBuilder` when valid.
6. If a GPX was supplied, `GpxParser` and the transfer service compare it with the frozen original source; it does not replace approved coordinates.
7. `ModelReadiness` supplies the completeness preview.

### Step 3: Generate on Windows

Request: `POST /mdb-workflow/offline-synergee/download`.

1. `SynergeeOfflineController::download()` re-reads the package and calls `SynergeeTransfer::generate()`.
2. The service repeats inspection and mapping, writes the embedded clean template into private temporary storage and calls `WindowsExporter::export()` directly.
3. `app/Services/Mdb/WindowsExporter.php` verifies the template hash, writes `payload.json`, and starts `scripts/mdb/export-reviewed-mdb.ps1` using `AccessPowerShell`.
4. The PowerShell writer uses `scripts/mdb/schema-contract.ps1` and `resources/mdb/approved-schema.json`, writes via DAO/ACE and returns readback evidence.
5. `app/Services/Mdb/ReadbackVerifier.php` checks the Jet MDB header, schema/readback flags, table counts, payload/template/revision hashes and output checksum.
6. The controller downloads the MDB. Temporary template/payload files are removed and the output is deleted after download.

Private working directory: `storage/app/private/synergee-offline/{uuid}` with the usual local disk configuration. No production export job or local survey records are created by offline conversion. SynerGEE opening, connectivity and load-flow acceptance remain separate.

## 5. PHP file inventory

This is the application feature inventory, not Laravel/vendor framework internals. Keep the complete application deployed; this is not a safe list for cherry-picking individual PHP files into an otherwise different revision.

### Entry points, pages and configuration

| File | Purpose |
| --- | --- |
| `routes/web.php` | Includes the workflow route file |
| `routes/mdb-workflow.php` | JSON, offline converter, direct export and queued export routes |
| `app/Http/Controllers/DataEntryMdbExportController.php` | Data Entry page, transformer JSON and direct MDB downloads |
| `app/Http/Controllers/OfflineMdbController.php` | Local Data Entry JSON preview and generation |
| `app/Http/Controllers/SynergeeOfflineController.php` | SynerGEE JSON export, preview and local generation |
| `app/Http/Controllers/MdbWorkflowController.php` | Survey/review screens and production queued SynerGEE generation/download |
| `resources/views/mdb-workflow/data-entry-export.blade.php` | Data Entry export buttons |
| `resources/views/mdb-workflow/offline-mdb.blade.php` | Data Entry upload/preview/generation forms |
| `resources/views/mdb-workflow/partials/synergee-transfer.blade.php` | SynerGEE JSON export form |
| `resources/views/mdb-workflow/offline-synergee.blade.php` | SynerGEE upload/preview/generation forms |
| `resources/views/mdb-workflow/review.blade.php`, `show.blade.php`, `advanced.blade.php` | Survey and generation screens; relevant partials are included according to screen |
| `config/data_entry_mdb.php` | Data Entry driver, worker URL/secret, timeout, PowerShell and offline switch |
| `config/mdb_workflow.php` | SynerGEE driver, queue, worker URL/secret, projection and offline switch |
| `app/Providers/AppServiceProvider.php` | Binds the queued export interface through `ExporterFactory` |

### Core services and supporting PHP

| File under `app/Services/Mdb/` | Purpose |
| --- | --- |
| `TransformerTransfer.php` | Data Entry JSON packaging and validation |
| `DataEntryPayload.php` | Original observations to legacy MDB field mapping |
| `DataEntryMdbExport.php` | Data Entry Windows execution or authenticated HTTPS worker call |
| `SynergeeTransfer.php` | SynerGEE package export/read/inspect/local generation |
| `SnapshotService.php` | Frozen snapshot capture/reconstruction and canonical hashing |
| `WorkflowAccess.php` | Workflow permission and batch access checks |
| `AccessPowerShell.php` | ACE-compatible process architecture/environment |
| `NetworkValidator.php` | Survey/network/engineering checks |
| `ExportPayloadBuilder.php` | Approved inputs to SynerGEE table records |
| `WindowsExporter.php` | SynerGEE PowerShell writer execution |
| `ReadbackVerifier.php` | Output and readback verification |
| `ApprovedSchema.php` | Supported canonical schema/fingerprint verification |
| `ExportService.php` | Production approval/source checks, export job lifecycle and completion |
| `Exporter.php`, `ExporterFactory.php` | Production exporter interface and driver selection |
| `HttpExporter.php` | Production signed HTTPS SynerGEE worker request and response verification |
| `DisabledExporter.php`, `WorkerNotConfigured.php` | Unconfigured writer handling |
| `GenerationMode.php` | Simple/full mode rules |
| `SurveyWaypoint.php`, `StartingPoleCoordinates.php` | Approved coordinate and starting-pair handling |
| `SurveyPhaseConsistency.php` | Phase consistency checks |
| `EntryService.php` | Original entry checks and snapshot-related entry processing |
| `SupplementalMapping.php` | Supplemental device/year mapping |
| `ProjectionService.php`, `Wgs84Utm.php`, `SagAllowance.php` | Coordinate projection and span lengths; current code has native WGS84 UTM support and a Python fallback for other supported projected CRSs |
| `GpxParser.php` | Optional original GPX verification |
| `ModelReadiness.php` | Preview completeness information |
| `EngineeringConfiguration.php`, `SchemaTemplate.php` | Configuration/schema-template rules used by snapshot and validation paths |

Supporting domain models live under `app/Models/Mdb/`: `SurveyBatch.php`, `Revision.php`, `Configuration.php`, `Template.php`, `NetworkTransformer.php`, `EntryRow.php`, `NetworkSection.php`, `Consumer.php`, `PvRecord.php`, `Waypoint.php`, `SourceFile.php`, `GenerationInput.php`, `VerificationDecision.php`, `ExportJob.php` and `ModelValidation.php`. A frozen local package is mapped from its arrays; it does not import these source models into local survey tables. Production also uses the existing User/Project/Feeder models and authorization/session infrastructure.

`app/Services/AuditService.php` records export/download events. `app/Services/DriveStorage/FileStorage.php` retrieves original sources for production source-integrity checks. `app/Jobs/Mdb/GenerateMdbExport.php` handles the production generation queue.

### Files that actually write MDB bytes

| File | Used for |
| --- | --- |
| `scripts/mdb/export-data-entry.ps1` | Data Entry DAO write/readback |
| `resources/mdb/data-entry/empty.mdb` | Data Entry clean template |
| `resources/mdb/data-entry/schema.json` | Data Entry reference schema |
| `scripts/mdb/export-reviewed-mdb.ps1` | SynerGEE DAO write/readback |
| `scripts/mdb/schema-contract.ps1` | SynerGEE schema checks |
| `resources/mdb/approved-schema.json` | Current SynerGEE schema contract; use the deployed manifest rather than old table counts in historical notes |
| `scripts/mdb/project_coordinates.py`, `scripts/mdb/requirements.txt` | Projection fallback where native UTM support does not apply |
| `scripts/mdb/worker_server.py` | HTTP worker for automatic conversion; not used by manual offline conversion |

PHP coordinates the conversion. PowerShell and the installed Access engine create the actual MDB.

## 6. Automatic connection using Cloudflare Tunnel

This replaces manual transfer with the already implemented signed worker protocol. It does not automate clicking the offline upload form.

```text
Production PHP -> HTTPS worker hostname -> Cloudflare Tunnel on Windows
               -> 127.0.0.1:8766 -> PowerShell + Access -> MDB response
               -> production verifies it -> browser downloads MDB
```

With this method, neither `192.168.1.70` nor `113.197.55.110` is entered into production's worker URL. The tunnel runs on the same PC as the worker. No inbound router forwarding or Caddy setup is needed.

### Step 1: Prepare a domain

Use a domain active in your Cloudflare account and choose `mdb-worker.yourdomain.com`. If adding an existing domain, preserve its website and mail DNS records. You can also use a separate Cloudflare-managed domain for the worker.

Official prerequisites and Windows connector installation: https://developers.cloudflare.com/tunnel/get-started/

### Step 2: Verify local components

Keep the already working local Access engine. Check Python:

```powershell
py -3 --version
```

If only `python` is installed, use that executable consistently instead of `py -3`. Use its actual installed executable path in unattended tasks.

### Step 3: Copy the worker outside the public web directory

Create `C:\PrivateMdbWorker`. Copy the complete `scripts\mdb` and `resources\mdb` directories from the same revision deployed to production, preserving their relative layout:

```text
C:\PrivateMdbWorker\scripts\mdb\worker_server.py
C:\PrivateMdbWorker\scripts\mdb\export-data-entry.ps1
C:\PrivateMdbWorker\scripts\mdb\export-reviewed-mdb.ps1
C:\PrivateMdbWorker\scripts\mdb\schema-contract.ps1
C:\PrivateMdbWorker\resources\mdb\data-entry\empty.mdb
C:\PrivateMdbWorker\resources\mdb\data-entry\schema.json
C:\PrivateMdbWorker\resources\mdb\approved-schema.json
C:\PrivateMdbWorker\resources\mdb\synergee-empty.mdb
```

Create an `exports` subdirectory. Limit folder access to administrators and the worker account.

### Step 4: Generate the worker secret

```powershell
py -3 -c "import secrets; print(secrets.token_hex(32))"
```

Retain the generated value privately. The tunnel token supplied by Cloudflare is a different credential.

### Step 5: Create the Windows worker startup file

Create `C:\PrivateMdbWorker\start-worker.ps1`:

```powershell
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath 'C:\PrivateMdbWorker'
$env:MDB_WORKER_ROOT = 'C:\PrivateMdbWorker\exports'
$env:MDB_WORKER_TEMPLATES = 'C:\PrivateMdbWorker\approved-templates.json'
$env:MDB_WORKER_PORT = '8766'
$env:MDB_EXPORT_WORKER_SECRET = 'REPLACE_WITH_GENERATED_WORKER_SECRET'
$env:MDB_EXPORT_POWERSHELL = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$env:MDB_EXPORT_TIMEOUT = '180'
$env:MDB_WORKER_MAX_INPUT_BYTES = '10485760'
$env:MDB_EXPORT_MAX_BYTES = '104857600'
& py -3 -u 'C:\PrivateMdbWorker\scripts\mdb\worker_server.py'
exit $LASTEXITCODE
```

For 32-bit ACE use `C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe` instead. Match the engine used by your working local conversion; historical documentation contains differing workstation architectures. The standalone Python worker does not use Laravel's automatic architecture selection or read Laravel's `.env`.

Start the script and leave it running during testing:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\PrivateMdbWorker\start-worker.ps1
```

Test from a second window:

```powershell
curl.exe -i -X POST http://127.0.0.1:8766/v1/data-entry-exports -H "Content-Type: application/json" --data "{}"
```

Expected: HTTP 401 and `Worker authentication failed.` This unsigned diagnostic checks the listener; it does not test actual generation.

### Step 6: Create the permanent Cloudflare tunnel

1. In Cloudflare, open **Networking > Tunnels** and create a named tunnel, for example `MDB-PC`.
2. Select Windows. Install the connector using the command displayed by Cloudflare, from an Administrator terminal on this PC. The service command is of the form `cloudflared.exe service install <TUNNEL_TOKEN>`.
3. Wait for the connector to show healthy.
4. On the tunnel's Routes tab, add a **Published application** route for `mdb-worker.yourdomain.com` with service URL `http://127.0.0.1:8766`.

Official dashboard procedure: https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/get-started/create-remote-tunnel/

The route points only to the MDB worker. Production authenticates with the existing `MDB-HMAC` protocol. An interactive Cloudflare Access login would prevent this unattended request unless service authentication is also implemented in the PHP client. The standard setup here uses the worker's existing authentication.

### Step 7: Configure production Data Entry MDB

In production `.env`:

```dotenv
DATA_ENTRY_MDB_DRIVER=http
DATA_ENTRY_MDB_WORKER_URL=https://mdb-worker.yourdomain.com
DATA_ENTRY_MDB_WORKER_SECRET=REPLACE_WITH_GENERATED_WORKER_SECRET
DATA_ENTRY_MDB_TIMEOUT=180
```

Use the worker base URL, without an endpoint suffix. Then run in the production application directory:

```bash
php artisan config:cache
```

Existing direct flow:

```text
data-entry-export.blade.php -> DataEntryMdbExportController::download()
 -> DataEntryMdbExport::prepare() -> DataEntryPayload::build()
 -> DataEntryMdbExport::generateHttp()
 -> POST https://mdb-worker.yourdomain.com/v1/data-entry-exports
 -> worker_server.py -> export-data-entry.ps1
 -> signed response -> PHP checks -> MDB download
```

`OfflineMdbController` and `TransformerTransfer` are bypassed in this automatic flow. The current direct Data Entry button exports the batch; the existing single-transformer JSON action is a separate feature. A direct single-transformer MDB button needs an application change.

### Step 8: Configure production SynerGEE MDB, if required

Also set:

```dotenv
MDB_EXPORT_DRIVER=http
MDB_EXPORT_WORKER_URL=https://mdb-worker.yourdomain.com
MDB_EXPORT_WORKER_SECRET=REPLACE_WITH_GENERATED_WORKER_SECRET
MDB_EXPORT_TIMEOUT=180
MDB_WORKFLOW_QUEUE=mdb
DB_QUEUE_RETRY_AFTER=360
REDIS_QUEUE_RETRY_AFTER=360
```

Create `C:\PrivateMdbWorker\approved-templates.json` with an entry for each template selected in production. The remote protocol uses a local registry, whereas the offline SynerGEE package embeds its template. Example for the system schema asset, only when this identity matches production:

```json
{
  "HAZECO-REFERENCE-SCHEMA:1": {
    "path": "C:\\PrivateMdbWorker\\resources\\mdb\\synergee-empty.mdb",
    "sha256": "REPLACE_WITH_ACTUAL_LOWERCASE_SHA256",
    "synergee_version": "SynerGEE Electric 5.0.0.324",
    "permitted_static_tables": []
  }
}
```

Get the lowercase file hash:

```powershell
(Get-FileHash -Algorithm SHA256 -LiteralPath 'C:\PrivateMdbWorker\resources\mdb\synergee-empty.mdb').Hash.ToLowerInvariant()
```

Use the exact code/version/product version/hash/static-table list frozen in production. Copy custom clean templates into private worker storage and register their matching identities. A registry entry does not approve engineering settings.

On production:

```bash
php artisan config:cache
php artisan queue:restart
```

Ensure a supervised Laravel process runs:

```bash
php artisan queue:work --queue=mdb --tries=3 --timeout=300
```

Existing automatic SynerGEE flow:

```text
MdbWorkflowController::export() -> ExportService::request()
 -> GenerateMdbExport queue job -> ExportService::run()
 -> ExportPayloadBuilder -> ExporterFactory selects HttpExporter
 -> POST https://mdb-worker.yourdomain.com/v1/exports
 -> worker_server.py -> registered template -> export-reviewed-mdb.ps1
 -> HttpExporter verifies response -> ReadbackVerifier -> private production output
 -> MdbWorkflowController::download()
```

The offline package/controller is bypassed. Projection/mapping dependencies still run on production where required. Data Entry alone needs neither this registry nor the production SynerGEE queue.

### Step 9: Test production and generation time

From production:

```bash
curl -i --connect-timeout 15 --max-time 30 -X POST https://mdb-worker.yourdomain.com/v1/data-entry-exports -H 'Content-Type: application/json' --data '{}'
```

Expect an authenticated-worker error response, HTTP 401, rather than a Cloudflare login/error page. Next download a small real approved batch, then test your largest normal batch.

Cloudflare currently documents a default proxy read timeout of 125 seconds. This worker returns its response after generation; queueing on production alone does not remove that limit on the production-to-worker HTTP call. Keep generation comfortably below the limit, including time waiting behind another conversion. A 180-second PHP timeout does not extend Cloudflare's limit. If conversions regularly exceed it, an asynchronous worker submission/status/download protocol or the direct HTTPS setup is needed; this repository's current worker endpoints are synchronous.

Source: https://developers.cloudflare.com/support/troubleshooting/http-status-codes/cloudflare-5xx-errors/error-524/

### Step 10: Enable unattended operation

The Cloudflare connector should run as a Windows service. Separately create a Windows Task Scheduler task for the MDB worker:

- Trigger: at startup, optionally with a short startup delay.
- Program: `C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe`.
- Arguments: `-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "C:\PrivateMdbWorker\start-worker.ps1"`.
- Start in: `C:\PrivateMdbWorker`.
- Run whether the account is logged on or not, under the account tested with Access.
- Restart after failure, do not start another instance, disable the task execution time limit and battery-related stop conditions if appropriate.
- Use the real Python executable path in the script if the task account cannot resolve `py`.

Stop the manual test process before starting the scheduled instance. Keep the PC awake and online. Reboot once and perform a real production download to confirm both processes start correctly.

## 7. Troubleshooting and rollback

| Symptom | Check |
| --- | --- |
| Local connection refused | Worker process, Python path, port 8766 already occupied |
| Cloudflare tunnel down | Connector service and outbound internet access |
| Cloudflare 502 | Tunnel service URL must be `http://127.0.0.1:8766`; worker must run on that PC |
| Login page / redirects / unexpected HTML | Interactive Access policy or another Cloudflare rule intercepting the worker request |
| Real export authentication error | Same worker secret, configuration cache, clocks within five minutes |
| Access COM error | Engine registration, matching PowerShell architecture, task account and writable TEMP/output directories |
| Schema mismatch | Same worker/resources/production revision |
| Template not registered or hash differs | Exact lowercase hash and template metadata in registry match production |
| Cloudflare 524 | Generation or serialized waiting exceeded the proxy response timeout |
| SynerGEE export stays queued | Production Laravel worker is listening to `mdb`; inspect private export status/log |
| Approval changed | Reopen production survey, resolve edits and obtain current approval |

Keep the existing manual JSON workflow available during rollout. If the automatic connection fails, the existing production JSON download and local Windows conversion remain the fallback. Changing worker environment settings does not create a new permission, approve data, import surveys or require a new migration by itself.

For this task, only this guide was added. No executable PHP/Python/PowerShell, environment settings, DNS, router rules or services were changed. Live connectivity and MDB generation over Cloudflare have not been tested.
