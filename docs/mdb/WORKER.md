# MDB worker deployment and protocol

The workflow defaults to `MDB_EXPORT_DRIVER=disabled`. The local development environment now explicitly uses the installed Windows DAO/ACE writer; see [LOCAL_ACTIVATION.md](LOCAL_ACTIVATION.md). A retained approved JSON payload is an intermediate artifact. Jobs show **MDB export worker not configured** until a real writer is configured; no output download or generation time is recorded for a payload alone.

## Local Windows writer

Install a supported Microsoft Access/ACE runtime that exposes `DAO.DBEngine.120`. PowerShell and ACE must have matching bitness. Laravel automatically selects 32-bit or 64-bit PowerShell from the registered DAO DLL; `MDB_EXPORT_POWERSHELL` and the older builder's `MDB_POWERSHELL` remain explicit overrides. This workstation currently has 64-bit DAO, used through `C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe`. A 32-bit engine uses `SysWOW64` instead. The PHP process does not need COM support itself: the queued process invokes PowerShell with a structured argument array.

```dotenv
MDB_EXPORT_DRIVER=windows
# Optional override; otherwise Laravel detects the installed engine architecture.
MDB_EXPORT_POWERSHELL=C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe
MDB_EXPORT_TIMEOUT=180
MDB_EXPORT_MAX_BYTES=104857600
MDB_WORKFLOW_DISK=local
MDB_WORKFLOW_QUEUE=mdb
DB_QUEUE_RETRY_AFTER=360
REDIS_QUEUE_RETRY_AFTER=360
MDB_PROJECTION_PYTHON=python
MDB_PROJECTION_TIMEOUT=30
MDB_SOURCE_PYTHON=python
MDB_PDF_MAX_MB=100
```

The template is selected from the module's administrator template registry in private storage, not `MDB_TEMPLATE` from the old builder. Full generation requires an explicit code, immutable version, SynerGEE target version, SHA-256, reviewed equipment-library references and approval. Simple generation may use the system's unapproved schema-only template, registered as `HAZECO-REFERENCE-SCHEMA`, version `1`; this does not approve engineering values. Both modes require the canonical structure of the fifteen approved samples: 50 tables, 1,065 fields, 133 indexes and 41 relationships. Do not upload a populated sample model as a template. The writer rejects all nonempty network/control/project tables, linked tables and unapproved static table content. Only explicitly reviewed static library table names may retain content. MDB_WORKFLOW_DISK must be private; public disks are rejected. If the engine is absent, the job fails with its error in the private export log.

Run the Laravel queue on a private filesystem with process execution permitted:

```powershell
php artisan queue:work --queue=mdb --tries=3 --timeout=300
```

Configure the queue connection's `retry_after` greater than 300 seconds; the HTTP/process timeout must be below the queue timeout. Configure a shared cache lock store when more than one Laravel queue worker is used. The relational status lease, overlap lock and unique idempotency key protect duplicate deliveries. Jobs automatically retry transient errors with 60/180/600-second backoff. A missing worker waits for configuration and an explicit **Retry**. Editing survey data or configuration supersedes existing exports; retries cannot restore an old approval.

## Remote Windows worker for Linux/cPanel

Deploy these files onto an authorized Windows host with ACE and Python 3, preserving their relative directories:

```text
C:\PrivateMdbWorker\
  scripts\mdb\worker_server.py
  scripts\mdb\export-reviewed-mdb.ps1
  scripts\mdb\schema-contract.ps1
  resources\mdb\approved-schema.json
  resources\mdb\synergee-empty.mdb
  approved-templates.json
  templates\...approved engineering templates...
  exports\
```

The schema helper loads `../../resources/mdb/approved-schema.json` relative to `scripts/mdb`. Copy the writer, helper and manifest from the same application revision. The remote worker needs the updated files even when only the Laravel application was previously deployed. Restart its supervised process after deployment. Older worker responses without the canonical schema fingerprint are rejected by Laravel's readback verifier.

Run the Python server as a supervised service account with private ACLs on its template registry, templates and output directory. It binds only `127.0.0.1`; expose it through a firewall-restricted HTTPS reverse proxy. Configure the Laravel worker URL to this HTTPS origin. Ensure all source preprocessing and coordinate projection dependencies are also available on the Laravel host. There is no separate worker packaging script in this repository; include all files in this layout in the deployment package.

Create an operator-owned `approved-templates.json` file. Paths occur only in this local file; no HTTP request may nominate a path:

```json
{
  "approved-template:reviewed-version": {
    "path": "C:\\PrivateMdbWorker\\templates\\reviewed-version.mdb",
    "sha256": "REPLACE_WITH_VERIFIED_64_CHARACTER_SHA256",
    "synergee_version": "REPLACE_WITH_APPROVED_TARGET_VERSION",
    "permitted_static_tables": []
  }
}
```

When Simple generation uses the system fallback, register its identity separately in that same file:

```json
{
  "HAZECO-REFERENCE-SCHEMA:1": {
    "path": "C:\\PrivateMdbWorker\\resources\\mdb\\synergee-empty.mdb",
    "sha256": "REPLACE_WITH_VERIFIED_64_CHARACTER_FILE_SHA256",
    "synergee_version": "SynerGEE Electric 5.0.0.324",
    "permitted_static_tables": []
  }
}
```

Use the SHA-256 of the copied `synergee-empty.mdb`, matching the template identity retained in Laravel. Obtain it with `Get-FileHash -Algorithm SHA256 -LiteralPath 'C:\PrivateMdbWorker\resources\mdb\synergee-empty.mdb'`. This is the MDB file hash, distinct from the schema fingerprint. This worker allowlist entry permits use of the known schema asset; it grants no engineering approval. Keep entries for existing full-generation templates. A configured template selected by the Admin still needs its own matching worker registry entry in either mode.

Set these service environment variables without committing their secret value:

```dotenv
MDB_WORKER_ROOT=C:\PrivateMdbWorker\exports
MDB_WORKER_TEMPLATES=C:\PrivateMdbWorker\approved-templates.json
MDB_WORKER_PORT=8766
MDB_EXPORT_WORKER_SECRET=<random-secret-at-least-32-characters>
MDB_EXPORT_POWERSHELL=C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe
MDB_EXPORT_TIMEOUT=180
MDB_WORKER_MAX_INPUT_BYTES=10485760
MDB_EXPORT_MAX_BYTES=104857600
```

Set the worker's PowerShell path explicitly to match its installed DAO engine; unlike Laravel, the standalone Python worker retains its existing 32-bit default when the variable is omitted. Launch `python scripts/mdb/worker_server.py` from `C:\PrivateMdbWorker` under the supervisor. Laravel uses:

```dotenv
MDB_EXPORT_DRIVER=http
MDB_EXPORT_WORKER_URL=https://your-authorized-worker.example
MDB_EXPORT_WORKER_SECRET=<same-secret-provided-through-deployment-secret-store>
MDB_EXPORT_TIMEOUT=180
```

Restart Laravel queue workers after changing cached configuration. Neither worker service nor reverse proxy was deployed by this implementation.

## Authenticated protocol v1

POST `/v1/exports` accepts exactly the versioned payload built from an immutable approved survey revision. Authorization is `MDB-HMAC <UTC-Unix-seconds>:<hex-signature>`, where signature is HMAC-SHA256(secret, timestamp + newline + SHA256(raw request bytes)). A five-minute timestamp window requires synchronized clocks. Payload version is `1`; supported mapping versions are `synergee-reviewed-v1` and `synergee-reviewed-v2`, and idempotency identity is 64 lowercase hexadecimal characters. Response bytes have a second HMAC-SHA256 in `X-MDB-Signature`. The HTTPS client verifies this signature before retaining any output. There is no callback URL or endpoint that can download a worker file by path.

The server matches the requested template code/version/hash/product/static-table allowlist to its local registry. The v1 mapping populates only the six supported model tables; v2 also permits `InstMeterPoints`, `InstDGens` and `InstMymDGens`. All 50 schema tables exist in the resulting MDB in both versions and modes, including those with no rows. Only scalar values in existing fields are accepted. The server receives no SQL, executable names, script names, arbitrary paths or outbound URLs. It creates an output folder from its fixed configured root and the validated hexadecimal identity. The exact request hash is retained: retries reuse the original artifact, and a reused identity with changed payload is rejected. Resource caps and serialized writes limit request size and parallel DAO use. Reverse proxy request/time limits should match these caps.

The writer copies the selected template, verifies it is clean and matches the canonical schema, inserts all mapped values in a DAO transaction, explicitly NULLs omitted nullable fields, commits, closes and reopens the resulting Access database. Required text fields that permit zero-length values use an empty string when the corresponding nonessential value is missing; schema defaults never supply missing engineering parameters. Readback compares every field, row count, schema/index/relationship definition and relevant node/feeder/section reference. Both modes validate essential identifiers and network connectivity. The response contains database bytes, output hash, revision/template hashes, counts, the canonical schema fingerprint and verification evidence. Laravel also checks the Jet MDB header and output SHA-256, then rechecks current survey approval inside its completion transaction before exposing the file. Generation is separate from Analysis Team engineering acceptance.

The implementation uses the [Microsoft DAO transaction API](https://learn.microsoft.com/en-us/office/vba/access/Concepts/Data-Access-Objects/use-transactions-in-a-dao-recordset). Mapping versions govern which existing schema tables may receive model data. They do not change the database structure. This writer does not guess PV parameters or conductor/transformer library contents.

## Verification performed

On 9 October 2026, PHP 8.3.33 / PHPUnit 11.5.56:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit --filter MdbExport
python tests\Worker\test_mdb_worker.py
```

The PHP export tests passed: 15 tests, 71 assertions. They cover branch mapping, conductor independence, fixed R/Y/B/N phase slots and blue-phase load mapping, signed measured kvar, exact demand and zero-count handling, missing source mappings/PV exclusions/load phases, immutable approvals, original source bytes before/after queueing and payload hashes, idempotency, retries, stale queue deliveries, honest disabled-worker status, disguised SQLite rejection and genuine DAO readback of the original one-transformer fixture. Three worker protocol tests passed, including authenticated genuine export/reuse, altered-payload rejection, unauthenticated requests, path traversal and invalid table rejection. Tests use SQLite `:memory:` and fake/private temporary storage; production/application data is not migrated or edited.

The remaining engineering inputs are reviewed source/HV/LV connectivity, all target equipment references/parameters, load methods and phase values, confirmed pole-height units, missing GPX coordinates 878/879 with verifier-approved provenance, approved frequency/voltage/CRS/template, and generator mapping or documented PV exclusion. All handwritten PDF rows still require manual verification. SynerGEE was not invoked; opening, connectivity and load-flow validation remain pending for the Analysis Team.
