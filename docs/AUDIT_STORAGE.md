# Audit storage: deployment, diagnosis and safe maintenance

This change reduces new audit payloads immediately. It does not edit business records,
approved MDB revisions, survey data, assignments or Google Drive files. No schema migration
or table rebuild is required. Automatic deletion starts **disabled**.

## What was found

`AuditService` previously stored every supplied before/after array without comparison,
redaction, size limits or retention. The operator row save, row deletion, header save,
transformer deletion and review actions supplied **two complete batch snapshots**.
Each snapshot includes all batch waypoints, transformers, sections, consumers, entry rows,
source metadata and engineering configuration. A small edit repeatedly copied unrelated
data. The general MDB editor also supplies snapshots; these now become recursive changes
instead of two stored datasets. Frozen revision snapshots used for MDB generation remain
unchanged.

There was also a second generic batch-change audit for source uploads already audited by
`SourceService`; the duplicate is removed. Repeat checkbox checks no longer create audit
events. Repeated unchanged feeder import rows no longer create update events. Import
results have one compact count/error summary, with changed feeder fields retained for
individual traceability.

Inspection found one application writer (`AuditService`), no audit-producing global model
observer, HTTP request/polling middleware or recursive job logger. `DuplicateFiles` updates
its own compact completion audit directly and now applies the same payload policy. Snapshot sources contain metadata, not complete
PDF bytes; offline-transfer base64 is a separate export path. The problem confirmed in code
is repeated datasets, not evidence that PDFs are embedded in production audit rows.

The schema has JSON `old_values`/`new_values`, text `reason`/`user_agent`, actor/organization,
action, affected model/ID, IP and timestamps. Existing indexes cover action, affected record,
actor/organization and `(created_at,user_id)`. Reuse these; adding an index or rewriting the
1.6 GiB table is unnecessary for this fix.

**Production measurement is still required.** The screenshot shows allocated InnoDB space
and an estimated row count. It does not establish live JSON bytes or exactly 4,010 rows.
Read-only local measurement found 12 records: old JSON 238 B, new JSON 4,741 B, reasons
103 B and user agents 1,323 B; total 6,405 B. Local allocation was 16,384 B of data plus
81,920 B of indexes, with an estimated count of 10. This local database does not reproduce
production size.

A synthetic regression fixture containing 1,000 GPX points and 100 transformers, with one
location correction, measured **386,670 B before / 118 B after** for serialized old/new
details. This is representative evidence, not a promised production reduction or disk size.

## Configuration

Add these to production `.env` (do not replace the file):

```dotenv
AUDIT_PAYLOAD_BYTES=16384
AUDIT_ROUTINE_DAYS=30
AUDIT_IMPORTANT_DAYS=180
AUDIT_CLEANUP_ENABLED=false
```

`config/audit.php` contains the small event allowlist and internal batch/time settings.

- The combined old/new JSON budget is 16 KiB, minimum 2 KiB. Actor, action, affected model/ID,
  timestamp and IP remain separate columns. Reason is bounded to 2,048 bytes; user agent to
  512 bytes. Summaries retain count/size/hash where applicable and mark omitted paths.
  Large text is replaced by a valid JSON summary; JSON is never cut mid-string. Outcome is
  retained when available. Oversized reasons have an explicit omitted-text marker.
- Updates keep recursive changed fields and relevant old/new values. Timestamp-only,
  save-token-only and revision-only differences do not create events. Password-reset and
  download actions still record meaningful security events even without change details.
- Passwords, tokens, API/private keys, credentials, cookies, file contents/base64 and
  unnecessary loaded relationships are excluded. Bulk initial datasets become summaries.
- **Routine, 30 days:** PDF guide lines/layout/progress, verification value checks, GPX
  calculation and verified-survey loading. These are explicitly named in configuration.
- **Important, 180 days:** every other action, including unknown future action names,
  data entry/corrections, approvals, users/security, imports and exports.
- **Protected, no automatic expiry or compaction:** `mdb.workflow_task_assigned` and
  `drive.duplicate_cleanup_requested`. Existing assignment/history views depend on these
  records. Do not remove this protection without separately preserving that application data.
- Undated legacy logs are retained. Changing the allowlist or retention takes effect on
  the next run, including rechecking eligibility when executing an older plan.
  Invalid retention values fail closed: important history cannot expire sooner than routine logs.

The existing audit viewer keeps its URL, action filter and pagination. New details show
changes/summaries. Oversized legacy JSON is not fetched into PHP for display; a notice says
the original remains in the database. Small legacy details are redacted for display only.
The MDB batch's action-history list fetches only the metadata that it displays, avoiding
unnecessary loading of legacy snapshots there too.

## Super Admin: storage sizes and clearing records from the page

Open **Administration → Audit Log** as a Super Admin. The page shows:

- Total allocated database size (all data tables and indexes).
- Allocated `audit_logs` size, with separate data/index sizes.
- The exact audit-record count, or exact matching count when an action filter is applied.

Sizes come from MySQL/MariaDB allocation metadata, without loading audit payloads. Unsupported
connections or unavailable metadata show **Not available**, rather than an invented size.
Size estimates can remain unchanged after deletion because InnoDB reuses allocated space.

To clean logs:

1. Choose **All removable audit records, including recent records**, or **Expired records only**.
   This selection applies to the whole table, independently of the action filter.
2. Click **Preview cleanup**. No deletion happens at this step. Review the selected/protected
   counts and download the complete CSV list of exact IDs, actions, affected records and dates.
3. Verify recovery of your daily application backup. It must be at most 48 hours old and
   cover every selected ID. A daily backup taken before today's new records may not cover
   an “all records” cleanup; run `php artisan backup:run` for a fresh verified backup if needed.
   Existing receipts created before this update also need one fresh application backup.
4. Type **CLEAR LOGS**, enter your current Super Admin password and tick the backup confirmation.
5. Click **Clear listed logs**. The page immediately shows activity, then counts and progress.
   It clears at most 100 records per request. If interrupted, refresh/continue within the
   preview's 30-minute validity, or prepare a new preview. Without JavaScript, each confirmed
   submission clears one batch and displays a continuation message.

One preview covers at most 10,000 records (or the smaller configured `audit.max_records`).
Larger tables require another preview/pass; the page states this clearly. A new preview
replaces that account/session's previous selection. Previews are private metadata-only files
under `storage/app/private/audit-maintenance/web`; completed/replaced previews are removed,
and old abandoned previews receive bounded housekeeping when a new preview is prepared.

This manual action works independently of `AUDIT_CLEANUP_ENABLED`. It never drops/truncates
the table and logging continues. Assignment/Drive-cleanup history and previous cleanup
summaries are protected from this web action. Records created after the preview are excluded.
Consequently, the table need not become empty. Recent business/security audit records can be
removed only by the explicitly confirmed “all” choice; automatic retention rules are unchanged.

The same maintenance process lock prevents overlap with the CLI/scheduler. Password, CSRF,
Super Admin access, preview ownership/expiry, database identity and verified backup checks
run on the server. Backup verification is reused within the operation and rechecked after
a five-minute pause. Deletion and its compact progress/summary audit commit in the same
transaction, so retries cannot double-count a batch or create an audit for every removed row.
An audit-write failure rolls back that batch. Other business tables and approved MDB files
are not modified. One compact `audit.logs_cleared` event records who cleared the logs and how
many were cleared/skipped. That event is retained by manual cleanup and follows the important
event retention policy for scheduled expiry.

## Deploy the code, without deleting existing logs

Commit/push the changed files from the development checkout using the normal release process.
Then run in the cPanel Terminal:

```bash
cd /home/barqaabp/public_html/hazeco.barqaab.pk
git pull --ff-only
/usr/local/bin/php artisan config:cache
/usr/local/bin/php artisan route:clear
/usr/local/bin/php artisan view:clear
/usr/local/bin/php artisan queue:restart
/usr/local/bin/php artisan schedule:list
/usr/local/bin/php artisan audit:report --limit=5000 --seconds=45
```

No Composer dependency, migration, database reset or frontend build is required by this change.
Keep existing cron and worker entries. Deploying these defaults cannot delete historical logs.

The report outputs only IDs, event names, schema/index information, counts and byte sizes.
It never selects raw payloads, reasons, credentials or user names. It uses primary-key batches
of at most 25 records and a cooperative time budget between queries. A single SQL statement
may still take longer on a busy server. `column_bytes` measures logical serialized column
bytes, not InnoDB page allocation or exact JSON binary storage overhead.

If `complete_range` is false, run again with the printed `next_after_id`:

```bash
/usr/local/bin/php artisan audit:report --after-id=NEXT_ID --limit=5000 --seconds=45
```

Add column/event totals for non-overlapping ranges. `rows_measured` summed across complete
ranges is the measured count through `snapshot_max_id`; new concurrent writes may add rows.
Compare largest event types and old/new sizes with `allocated.data_bytes`, `index_bytes` and
`free_bytes`. Send the report output for a production-specific diagnosis before cleanup.

## Preview existing logs: no audit records are changed

```bash
/usr/local/bin/php artisan audit:maintain prune --dry-run --limit=1000
/usr/local/bin/php artisan audit:maintain compact --dry-run --limit=1000
```

Each command writes a private, bounded plan under
`storage/app/private/audit-maintenance/`, with a random filename and mode 0600. Output shows
the plan filename, SHA256, cutoff dates, eligible count **within this scan**, next ID and
whether the range is complete. The file lists **every affected ID, action, date and payload
byte sizes**. Compaction plans also show before/after bytes and a fingerprint for each row.
There are no raw payloads in the plan. Inspect the actual plan before execution:

```bash
cat storage/app/private/audit-maintenance/PRUNE_OR_COMPACT_PLAN.json
```

Use `--after-id=NEXT_ID` to preview the next range. A prune preview scans expired candidates;
a compact preview scans unprotected records. Counts are not falsely presented as whole-table
totals when a row/time limit stops the scan. Review all ranges needed for the intended cleanup.

Compaction is optional and separate from deletion. It applies the same diff, redaction and
summary policy, preserving actor, action, model/ID, reason (subject to redaction/budget), IP
and original timestamps. It skips records above **2 MiB combined payload** and reports their
IDs, rather than risking PHP memory exhaustion or erasing unreviewed history. Those records
need a separate reviewed investigation. No automatic legacy compaction runs during deployment.

## Backup prerequisite and explicit execution

Use the application's existing verified ZIP-to-Drive backup workflow, documented in
[BACKUPS.md](BACKUPS.md). Its receipt now records this database's fingerprint and a conservative
audit ID boundary captured before the consistent database dump starts.

```bash
/usr/local/bin/php artisan backup:run
```

Require a successful backup. Confirm the ZIP is retrievable, contains `database.sql` including
`audit_logs`, and restores successfully to an isolated temporary database. Never test a restore
against production. Keep the backup independently recoverable. Large SQL exports need hosting
quota and temporary disk space; the existing backup preflight refuses insufficient capacity.
If backup fails, resolve it with the host first; cleanup is intentionally blocked.

Execution checks that the uploaded ZIP still exists, is not trashed, has the verified size/MD5,
is in the intended folder, belongs to this database, is no more than **48 hours** old and covers
every ID in the plan. Older backup receipts need a new `backup:run`. The command cannot itself
prove a human has completed a recovery drill; that remains a deployment prerequisite.

After reviewing the exact plan and verifying backup/recovery, substitute its printed filename
and SHA256. These are explicit one-time commands; do not paste placeholder values literally:

```bash
/usr/local/bin/php artisan audit:maintain prune --execute --plan=prune-EXACT_NAME.json --plan-sha256=EXACT_SHA256
/usr/local/bin/php artisan audit:maintain compact --execute --plan=compact-EXACT_NAME.json --plan-sha256=EXACT_SHA256
```

Use the matching operation and plan. Deletion only removes listed IDs still expired under the
current policy, matching their reviewed action/date. Compaction checks the original fingerprint
and compares original values atomically; changed or already compacted rows are skipped. No model
delete/save observers fire for maintenance, so it cannot create one audit per removed record.

Defaults: maximum 10,000 records per invocation, 100 deletes per SQL batch, 45 seconds between
queries (hard cap on the requested cooperative budget: 120 seconds). There is no table-wide
transaction. The database may finish an in-flight statement after the time budget. A process
lock protects manual and scheduled commands; it releases automatically when PHP exits.

If the limit/time budget stops execution, use the same plan/checksum and the printed cursor:

```bash
/usr/local/bin/php artisan audit:maintain compact --execute --plan=compact-EXACT_NAME.json --plan-sha256=EXACT_SHA256 --after-id=LAST_PROCESSED_ID
```

The same applies to pruning. Re-running a plan is safe; a fresh backup may be required after
48 hours. Create a new preview to include new IDs. Keep reviewed plans privately with the
backup until the maintenance has been checked; remove old plan files manually when no longer needed.

## Enable daily retention on Bluehost/cPanel

Only after the dry-run and recovery checks, change:

```dotenv
AUDIT_CLEANUP_ENABLED=true
```

Then:

```bash
/usr/local/bin/php artisan config:cache
/usr/local/bin/php artisan schedule:list
```

The task runs at **04:00 Asia/Karachi**, using Laravel overlap protection and its own shared
process lock. It creates a private exact-ID plan before deleting and still checks a fresh
verified backup every run. With no valid backup, it fails closed. Daily runs catch up in small
windows; no scheduler visit through the browser is required. Scheduled preview work gets at most
10 seconds of the shared budget, leaving time to verify backup and delete. Monitor whether eligible
records accumulate faster than cleanup; the report/preview shows remaining ranges. The command's
limits deliberately take precedence over processing an unlimited daily backlog.

The existing cPanel scheduler entry is sufficient (do not add a duplicate):

```cron
* * * * * cd /home/barqaabp/public_html/hazeco.barqaab.pk && /usr/local/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

If the hosting plan only permits 15-minute cron, use the same command with `*/15 * * * *`.
It includes minute 00, so the 04:00 task still runs. Cron is configured in the server timezone;
Laravel evaluates the task in Asia/Karachi. Do not replace other backup/sync/worker cron entries.
Check `storage/logs/audit-maintenance.log`, then re-run `audit:report` to compare logical bytes.
To stop future deletion, set `AUDIT_CLEANUP_ENABLED=false` and rebuild the configuration cache.

## InnoDB space after cleanup

Deleting records or shrinking JSON generally makes space reusable inside InnoDB after purge.
It does **not** guarantee that phpMyAdmin's allocated table size or the `.ibd` file shrinks.
`DATA_FREE` is not an exact sum of all reusable bytes. Disk reclamation depends on the actual
server/version and tablespace layout.

Do not run `OPTIMIZE TABLE` automatically. Rebuilding a file-per-table table can return space
to the operating system, but requires temporary disk headroom, host privileges and metadata
locks (including exclusive phases). Some configurations copy/lock the table; shared/system
tablespace space cannot simply be returned to disk by optimizing one table. Ask Bluehost to
confirm tablespace mode, available temporary quota, locking behavior and a maintenance window
after backup and measured cleanup. This change never runs `OPTIMIZE`, `TRUNCATE` or a table rebuild.

References: [MySQL table statistics](https://dev.mysql.com/doc/mysql-infoschema-excerpt/8.0/en/information-schema-tables-table.html),
[MySQL OPTIMIZE TABLE](https://dev.mysql.com/doc/refman/8.0/en/optimize-table.html),
[Laravel scheduling](https://laravel.com/framework/docs/12.x/scheduling).

## Verification

Tests use SQLite `:memory:` only; no mutation tests target the local application database or
production. [AUDIT_STORAGE_VALIDATION.md](AUDIT_STORAGE_VALIDATION.md) records the results.
`tests/Feature/AuditStorageTest.php` covers changed values, no-op saves, meaningful
security events, recursion prevention, secrets/binary exclusion, payload budgets, dataset
summaries, representative before/after sizes, repeat import behavior, retention classes,
protected/undated history, dry-run IDs, backup refusal, modified plans, policy rechecks,
bounded/resumable execution, legacy compaction and viewer filtering/redaction.

`BackupTest` additionally verifies the maintenance receipt accepts the correct fresh backup
and rejects insufficient ID coverage, a different database and stale backup metadata.
Existing Drive, assignment, engineering, entry and verification regression tests are also
checked. See the validation record for exact results and pre-existing failures.

Run tests in PowerShell using separate cache names so production-style local caches are ignored:

```powershell
$env:APP_CONFIG_CACHE='bootstrap/cache/config-audit-tests.php'
$env:APP_ROUTES_CACHE='bootstrap/cache/routes-audit-tests.php'
php -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/AuditStorageTest.php tests/Feature/BackupTest.php tests/Feature/DriveStorageTest.php
```
