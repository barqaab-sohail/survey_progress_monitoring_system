# MDB worker deployment and protocol

The workflow defaults to `MDB_EXPORT_DRIVER=disabled`. The local development environment now explicitly uses the installed Windows DAO/ACE writer; see [LOCAL_ACTIVATION.md](LOCAL_ACTIVATION.md). A retained approved JSON payload is an intermediate artifact. Jobs show **MDB export worker not configured** until a real writer is configured; no output download or generation time is recorded for a payload alone.

## Local Windows writer

Install a supported Microsoft Access/ACE runtime that exposes `DAO.DBEngine.120`. PowerShell and ACE must have matching bitness. This environment has 32-bit DAO available through `C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe`; a 64-bit ACE installation instead needs the matching 64-bit PowerShell path. The PHP process does not need COM support itself: the queued process invokes PowerShell with a structured argument array.

```dotenv
MDB_EXPORT_DRIVER=windows
MDB_EXPORT_POWERSHELL=C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe
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

The approved template is selected from the module's administrator template registry in private storage, not `MDB_TEMPLATE` from the old builder. Each template requires an explicit code, immutable version, SynerGEE target version, SHA-256, reviewed equipment-library references and approval. Do not upload a populated sample model as a template. The writer rejects all nonempty network/control/project tables, linked tables and unapproved static table content. Only explicitly reviewed static library table names may retain content. MDB_WORKFLOW_DISK must be private; public disks are rejected. If the engine is absent, the job fails honestly with its error in the private export log.

Run the Laravel queue on a private filesystem with process execution permitted:

```powershell
php artisan queue:work --queue=mdb --tries=3 --timeout=300
```

Configure the queue connection's `retry_after` greater than 300 seconds; the HTTP/process timeout must be below the queue timeout. Configure a shared cache lock store when more than one Laravel queue worker is used. The relational status lease, overlap lock and unique idempotency key protect duplicate deliveries. Jobs automatically retry transient errors with 60/180/600-second backoff. A missing worker waits for configuration and an explicit **Retry**. Editing survey data or configuration supersedes existing exports; retries cannot restore an old approval.

## Remote Windows worker for Linux/cPanel

Copy `scripts/mdb/export-reviewed-mdb.ps1` and `scripts/mdb/worker_server.py` together onto an authorized Windows host with ACE and Python 3. Run the Python server as a supervised service account with private ACLs on its template registry, templates and output directory. It binds only `127.0.0.1`; expose it through a firewall-restricted HTTPS reverse proxy. Configure the Laravel worker URL to this HTTPS origin. Ensure all source preprocessing and coordinate projection dependencies are also available on the Laravel host.

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

Set these service environment variables without committing their secret value:

```dotenv
MDB_WORKER_ROOT=C:\PrivateMdbWorker\exports
MDB_WORKER_TEMPLATES=C:\PrivateMdbWorker\approved-templates.json
MDB_WORKER_PORT=8766
MDB_EXPORT_WORKER_SECRET=<random-secret-at-least-32-characters>
MDB_EXPORT_POWERSHELL=C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe
MDB_EXPORT_TIMEOUT=180
MDB_WORKER_MAX_INPUT_BYTES=10485760
MDB_EXPORT_MAX_BYTES=104857600
```

Launch `python scripts/mdb/worker_server.py` under the supervisor. Laravel uses:

```dotenv
MDB_EXPORT_DRIVER=http
MDB_EXPORT_WORKER_URL=https://your-authorized-worker.example
MDB_EXPORT_WORKER_SECRET=<same-secret-provided-through-deployment-secret-store>
MDB_EXPORT_TIMEOUT=180
```

Restart Laravel queue workers after changing cached configuration. Neither worker service nor reverse proxy was deployed by this implementation.

## Authenticated protocol v1

POST `/v1/exports` accepts exactly the versioned payload built from an immutable approved revision. Authorization is `MDB-HMAC <UTC-Unix-seconds>:<hex-signature>`, where signature is HMAC-SHA256(secret, timestamp + newline + SHA256(raw request bytes)). A five-minute timestamp window requires synchronized clocks. Payload version is `1`, mapping version `synergee-reviewed-v1`, and idempotency identity is 64 lowercase hexadecimal characters. Response bytes have a second HMAC-SHA256 in `X-MDB-Signature`. The HTTPS client verifies this signature before retaining any output. There is no callback URL or endpoint that can download a worker file by path.

The server matches the requested template code/version/hash/product/static-table allowlist to its local registry. It accepts only the six supported model table names and scalar values in existing fields. It receives no SQL, executable names, script names, arbitrary paths or outbound URLs. It creates an output folder from its fixed configured root and the validated hexadecimal identity. The exact request hash is retained: retries reuse the original artifact, and a reused identity with changed payload is rejected. Resource caps and serialized writes limit request size and parallel DAO use. Reverse proxy request/time limits should match these caps.

The writer copies the approved template, verifies it is clean, inserts all mapped values in a DAO transaction, explicitly NULLs omitted fields, commits, closes and reopens the resulting Access database. Readback compares every field, row count, schema/index/relationship definition and relevant node/feeder/section reference. The response contains database bytes, output hash, revision/template hashes, counts and verification evidence. Laravel also checks the Jet MDB header and output SHA-256, then rechecks current approval inside its completion transaction before exposing the file. Generation is separate from Analysis Team engineering acceptance.

The implementation uses the [Microsoft DAO transaction API](https://learn.microsoft.com/en-us/office/vba/access/Concepts/Data-Access-Objects/use-transactions-in-a-dao-recordset). A new mapping version is required to add tables such as generator instances; this writer does not guess PV parameters or conductor/transformer library contents.

## Verification performed

On 9 October 2026, PHP 8.3.33 / PHPUnit 11.5.56:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit --filter MdbExport
python tests\Worker\test_mdb_worker.py
```

The PHP export tests passed: 15 tests, 71 assertions. They cover branch mapping, conductor independence, fixed R/Y/B/N phase slots and blue-phase load mapping, signed measured kvar, exact demand and zero-count handling, missing source mappings/PV exclusions/load phases, immutable approvals, original source bytes before/after queueing and payload hashes, idempotency, retries, stale queue deliveries, honest disabled-worker status, disguised SQLite rejection and genuine DAO readback of the original one-transformer fixture. Three worker protocol tests passed, including authenticated genuine export/reuse, altered-payload rejection, unauthenticated requests, path traversal and invalid table rejection. Tests use SQLite `:memory:` and fake/private temporary storage; production/application data is not migrated or edited.

The remaining engineering inputs are reviewed source/HV/LV connectivity, all target equipment references/parameters, load methods and phase values, confirmed pole-height units, missing GPX coordinates 878/879 with verifier-approved provenance, approved frequency/voltage/CRS/template, and generator mapping or documented PV exclusion. All handwritten PDF rows still require manual verification. SynerGEE was not invoked; opening, connectivity and load-flow validation remain pending for the Analysis Team.
