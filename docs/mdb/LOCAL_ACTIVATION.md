# Local MDB module activation

Activated on 9 October 2026 in `E:\xampp\htdocs\survey_progress_monitoring_system` after the user confirmed MySQL was available. The application environment was verified as `local`, using MySQL. Production was not deployed or modified.

## Database and access

Only the new module migration was pending. The following completed successfully:

```powershell
php artisan migrate --path=database/migrations/2026_10_09_000100_create_mdb_workflow_tables.php
php artisan db:seed --class=MdbWorkflowPermissionSeeder
```

The initial thirteen `mdb_workflow_*` tables and seven module permissions were verified. Existing and new role grants match the additive seeder. Existing accounts were not changed or assigned extra roles. Use the existing role administration for approved team assignments. The later operator-entry migration is recorded below.

## Local runtime

The ignored local `.env` now contains:

```dotenv
DB_QUEUE_RETRY_AFTER=420
MDB_EXPORT_DRIVER=windows
```

The existing database queue, private local disk and database cache are in use. Python imports verified PyMuPDF 1.28.2 and pyproj 3.8.0. The installed 32-bit PowerShell successfully instantiated `DAO.DBEngine.120`, reporting DAO/ACE engine 15.0. Laravel resolves the Windows exporter as configured. Genuine MDB writing/readback had already passed the fixture tests documented in [TEST_RESULTS.md](TEST_RESULTS.md).

A hidden development queue process was started for `mdb-sources,mdb`, with three tries, a 300-second timeout and three-second idle sleep. Its activation-time process ID was 1248. Logs are:

- `storage/logs/mdb-workflow-queue.out.log`
- `storage/logs/mdb-workflow-queue.err.log`

This is a local process, not an installed Windows service. After restarting Windows or stopping it, run the following from the application directory (or configure the usual service supervisor):

```powershell
php artisan queue:work database --queue=mdb-sources,mdb --tries=3 --timeout=300 --sleep=3
```

Avoid duplicate workers unless the shared lock/retry configuration is retained. Restart workers after changes to code or configuration.

The existing development web server is available at [http://127.0.0.1:8000/mdb-workflow](http://127.0.0.1:8000/mdb-workflow). Sign in with an authorized existing account.

## Activation checks

- Migration status: all applied.
- Schema: thirteen module tables.
- Permissions: seven abilities; expected grants verified for all eight module roles.
- Database queue worker: running without startup errors; no module jobs were pending at activation.
- Dashboard, batch creation and project configuration: HTTP 200 with expected content using the actual MySQL connection, in-memory authentication/cache and read-only transactions. These checks executed 25 SELECT queries with zero data write attempts.
- Anonymous HTTP request: redirected to the existing login page.
- Exporter: installed DAO/ACE available and Laravel Windows driver configured.

No survey batches, source uploads, approved configurations or templates were created during activation. No new generated model or SynerGEE acceptance is claimed. Approved engineering settings/templates, verified PDF entry and missing coordinates such as 878/879 remain necessary before sample export. Browser visual QA and SynerGEE opening/connectivity/load-flow checks remain pending.

## Simplified operator entry activation

Later on 9 October 2026, the additive migration for individual S/E observations completed on the same local MySQL connection:

```powershell
php artisan migrate --path=database/migrations/2026_10_09_000200_add_mdb_operator_entry_rows.php
```

There are now fourteen module tables. The new `mdb_workflow_entry_rows` table retains per-row observations, pairing, source pages and copy provenance. Two nullable columns, `entry_row_id` and `service_load_kw`, were added to existing PV records. All local migrations are applied. Existing sections and PV observations were not converted or rewritten.

An authorized MySQL smoke check created a temporary header and S/E pair inside an outer transaction, saved each row twice and rendered the simple, review and advanced screens. It verified exact `001`/`010` references, one section, separate neutral notation and a single RS count of three. The transaction was rolled back; before/after batch and entry-row counts matched, and no QA survey records, approvals or templates were retained.

The previous queue worker was restarted gracefully after the code change. The updated hidden development worker's verification-time process ID was **23864**, using the same queues, timeout and logs above. It was running without startup errors. PDF.js module and worker assets returned HTTP 200 with JavaScript content types from the local server.

See [OPERATOR_VERIFICATION.md](OPERATOR_VERIFICATION.md) for test evidence, inspected PDF pages and the outstanding browser visual check.

## Composite survey identity and compact entry

The additive `2026_10_09_000300_add_mdb_survey_identity.php` migration was subsequently applied to local MySQL. It adds nullable complete-identifier, identity-version and application-entry-sequence columns to the existing row table. No rows were backfilled and the module still has fourteen tables.

A second MySQL transaction checked the two example identifiers, different S/E dates, leading zeros, automatic source/page metadata, entry sequence and duplicate-save protection. Simple, review and advanced pages rendered successfully. The check used in-memory fixture settings only; actual project configuration was untouched. All temporary records were rolled back, with matching before/after batch and row counts.

The updated queue worker was restarted gracefully after the identity/export changes. Its verification-time process ID is **7952**, using the same queues and log paths above. It was running without startup errors. The new integrated-select JavaScript asset returned HTTP 200.

Verified units and the two-digit-year window still need to be selected in each project's entry settings; no source unit or century was guessed. See [COMPACT_ENTRY_VERIFICATION.md](COMPACT_ENTRY_VERIFICATION.md).
