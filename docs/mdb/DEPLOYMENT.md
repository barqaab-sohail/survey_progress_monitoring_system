# MDB workflow deployment

The implementation and follow-up activation are local development work. The configured local MySQL database now contains the module's tables and permission grants. A local queue worker is running and Windows DAO/ACE export is enabled. See [LOCAL_ACTIVATION.md](LOCAL_ACTIVATION.md) for the checks and restart instructions. Sample survey data has not been imported and engineering values have not been approved.

The repository uses Laravel 12.69.3, PHP 8.3.33, Filament 5, Spatie permissions, MySQL, private local storage and database queues. No AGENTS.md was present in the repository or its parent workspace directories. Existing survey progress, mobile survey, Google Drive OAuth, role and audit services remain in use. The new batch workflow lives at `/mdb-workflow`, alongside the existing transformer builder and daily progress records. Existing local changes were preserved.

MySQL was unavailable during initial implementation testing, which used isolated SQLite databases. After MySQL became available on 9 October 2026, the sole pending module migration and additive permission seeder were applied to the application's verified local environment. Dashboard, batch creation and configuration renders were then checked against MySQL using read-only transactions.

## Install and migrate in the intended development or staging environment

Use the existing Composer installation. No additional PHP package is required. Enable PHP DOM, fileinfo, mbstring, PDO and the driver for the selected database. SQLite PDO is required for the test suite only.

```powershell
python -m pip install -r scripts/mdb/requirements.txt -r scripts/mdb-workflow/requirements.txt
php artisan migrate
php artisan db:seed --class=MdbWorkflowPermissionSeeder
```

The initial migration adds thirteen `mdb_workflow_*` tables. The operator-entry migration adds `mdb_workflow_entry_rows` and two nullable PV columns, without rewriting existing records. The survey-identity migration adds three nullable columns to that row table, with no backfill. All three are applied locally. Review pending migrations in another environment before running `migrate`; other unfinished local changes already existed. Back up the intended database using its usual procedure. Local activation used explicit migration paths; all local migrations are now applied. Configure the verified height unit and two-digit-year window explicitly in each project's entry settings.

The permission seeder adds grants without removing existing permissions. It gives the existing survey role source-upload access, the existing MDB role data-entry/export access, and the existing processor role verification access. It creates `mdb_creation_team`, `mdb_verifier` and `analysis_team` Spatie roles. Use the existing Filament Roles administration to assign their permissions to approved accounts, or attach these additional roles using the application's normal Spatie administration. Primary enum roles remain unchanged. Profile/Google Drive token updates now preserve additional roles.

Project managers and administrators can see their permitted batches; other teams need active project membership through the existing survey, MDB or processing teams. A survey uploader also needs an active feeder assignment to create a batch. Direct role or permission assignment does not grant another project's source files.

## Environment and private storage

See [environment.example](environment.example). It contains placeholders only, and no electrical defaults or secrets.

Sources and templates are stored below `storage/app/private/mdb-workflow`. Keep that directory outside public aliases/symlinks, writable by the web and queue accounts. Downloads use authenticated, project-scoped controller routes. Preserve uploaded originals, database records, revision snapshots, audit logs and generated artifacts together in backups.

Output/payload storage defaults to the private `local` disk. A public disk is rejected. A remote private disk may be used for export payloads/output, while source preprocessing currently requires the local private filesystem. Do not expose the intermediate payload as a finished MDB. Hashes are checked at source processing/download, export request, generation and completion.

PDFs default to 100 MB; GPX defaults to 10 MB and 10,000 waypoints. Configure PHP `upload_max_filesize`, `post_max_size` and reverse-proxy request limits to match the intended batch size. Multiple uploads require a larger total POST allowance. PDF inspection runs in the background using PyMuPDF; an encrypted/malformed PDF fails processing and cannot receive approval. GPX is safely parsed by PHP without DTD/entity expansion. OCR is not required or configured.

The existing Google Drive integration is reused. An uploader supplies an authorized file ID from their connected account. Laravel checks download capability, refreshes its encrypted OAuth token where available, retrieves the bytes through Google's fixed API origin, and preserves a hashed private processing copy plus Drive version metadata. Arbitrary shared links/URLs do not become processing sources. The `drive.file` OAuth scope allows files authorized to this app; reconnect/re-authorize unavailable files through the existing integration.

## Queue and projection workers

```powershell
php artisan queue:work --queue=mdb-sources,mdb --tries=3 --timeout=300
```

Use a supervisor/service for this process. Set the selected queue connection's `retry_after` above 300 seconds, for example 420. Use a shared supported cache lock store with multiple workers. The source job has a 180-second timeout and export job a 300-second timeout. Process/HTTP timeouts must stay below their job timeout. Jobs dispatch after database commit. Restart queue workers after configuration/code changes with the normal `php artisan queue:restart` deployment procedure.

The PROJ worker uses pyproj with an explicitly configured projected EPSG code and metre units. It preserves WGS84 source coordinates and rejects coordinates outside the chosen CRS area. There is no Zone 43 default. The sample declares Zone 43N but some supplied points lie west of that zone; an engineer must resolve the appropriate CRS rather than treating the sample declaration as approval. Timestamp observations stay UTC; screens display Asia/Karachi.

If Python or PROJ is unavailable on a Linux/cPanel host, source/projection processing reports the specific failure. Provide these workers or an authorized environment that supports process execution before approving/exporting a network.

## Genuine MDB worker and engineering inputs

Choose the local Windows DAO writer or the authenticated remote Windows worker described in [WORKER.md](WORKER.md). The default driver is disabled and shows **MDB export worker not configured**. Installing the Laravel workflow alone does not produce an MDB.

Register an approved, clean, versioned SynerGEE MDB template. The worker preserves the actual schema, indexes and relationships and verifies every mapped field after reopening the output. The schema-only file in `resources/mdb` is a technical fixture; it is never registered or approved automatically. Equipment-library references must be confirmed against the installed SynerGEE library. Review the explicit source/HV/LV topology and [mapping contract](MAPPING.md).

Remaining project inputs are the verified handwritten headers/rows and continuation associations, missing waypoint coordinates such as 878/879 with provenance, approved frequency/voltages/CRS, transformer and conductor parameters/library references, pole-height units, measured demand or an approved documented estimation method, and explicit phase allocation. LC's printed category meaning and Int's interpretation require confirmation. Positive PV requires a generator mapping extension or a documented, approved model-scope exclusion; this mapping version does not invent generator parameters.

After generation, the Analysis Team must open the output in the target SynerGEE version, check connectivity and load flow, and record acceptance or return it for correction. Application readback is a separate technical check. SynerGEE validation remains pending in this environment.

## Operational verification

Use [USER_GUIDE.md](USER_GUIDE.md) for each team's workflow and [TEST_RESULTS.md](TEST_RESULTS.md) for development checks and their limits. No PDF row is claimed as fully extracted until it has been manually checked. Visual browser QA remains pending because the in-app browser was unavailable; server-rendered views and rich workflow interactions are covered by isolated feature tests.
