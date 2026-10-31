# Audit storage validation — 31 October 2026

Implementation and deployment instructions: [AUDIT_STORAGE.md](AUDIT_STORAGE.md).

## Follow-up: Super Admin storage view and manual clearing

The Audit Log page now shows total database allocation, audit table data/index allocation,
and exact record counts. Super Admins can preview all removable records or expired records,
download the exact ID list, then confirm their password and backup recovery before clearing.
Logging/table structure, assignment/Drive history and new records outside the preview remain intact.

**66 tests passed, 759 assertions**, covering the new 15-test `AuditLogManagementTest` plus
the audit storage, backup, Drive storage and assignment-management regression suites.
This includes access denial for other roles/guests, exact CSV lists without payloads,
confirmation/password validation, backup failure/reverification, ownership/expiry, batching,
one durable summary, interruption/retry safety, overlap protection, rollback on audit failure,
preview limits, undated records and protected/new-record preservation.

The actual allocation query also ran read-only against the local MySQL/MariaDB database:
5,996,544 allocated database bytes and 98,304 audit-table bytes (16,384 data / 81,920 indexes).
These are local measurements, not production figures. No local application or production logs
were cleared; all deletion tests used SQLite `:memory:`.

Chrome checks passed against actual Blade HTML from the isolated tests, with every HTTP request
intercepted: desktop/mobile layout, collapsed mobile navigation, no horizontal document overflow,
immediate loading status, two-batch progress, completion redirect, visible backup error, retry
controls and clearing the password input. See [web-ui-results.json](../output/audit-review/web-ui-results.json).
Screenshots are [desktop](../output/audit-review/web-desktop.png) and
[mobile](../output/audit-review/web-mobile.png). These use SQLite fixtures, so size cards correctly
say “Not available”; MySQL/MariaDB figures were verified separately above.

Additional files for this follow-up: `app/Services/Audit/WebCleanup.php`,
`tests/Feature/AuditLogManagementTest.php` and the browser-check script. Changes also extend
the existing audit controller, view, storage helper and `routes/web.php`. No migration or
package dependency is required.

## Results

| Check | Result |
| --- | --- |
| Final focused checks | **40 tests, 461 assertions, zero failures/errors** |
| Wider workflow checkpoint | 308 tests, 3,488 assertions; 34 failures, zero errors, two risky-test warnings |
| Original-code comparison | All 34 failing tests reproduced with original audit/MDB classes; identical failing-test name set |
| PHP syntax | Passed for all 20 changed/new application, configuration, console and test PHP files |
| Formatting | Pint passed for the new audit classes, commands, config and focused test changes |
| Whitespace | `git diff --check` passed |
| Mutation test database | SQLite `:memory:` only, enforced by `tests/TestCase.php` |
| Production | No access, deployment, deletion, compaction, optimization or business-record changes performed |

The wider suite is **not fully green**. Existing failures concern header fixtures missing required
inspector/service-category/capacity fields, legacy manual-survey expectations when automatic mode
is enabled, role/seed expectations, and an existing Drive-source authorization expectation. The
two risky warnings concern error/exception handlers in tests that already fail. These are recorded,
not hidden or changed as part of this storage fix.

For the comparison, the `HEAD` versions of `AuditService`, `MdbOperatorEntryController` and
`MdbWorkflowController` were loaded instead of the modified classes for every failing test.
No production or local application database was restored/reset. The exact baseline commit,
test names, matching failure set and representative size measurements are in
[validation.json](../output/audit-review/validation.json).

The final focused run also verifies behavior added after the broader checkpoint:

- A real S/E row correction keeps old/new pole height, excludes unrelated batch data and
  produces no audit for a repeated unchanged save.
- Section deletion keeps its exact affected section ID and handwritten consumer provenance,
  excludes an unrelated transformer, and leaves a frozen revision and its checksum unchanged.
- MDB batch action history does not load old/new JSON snapshots it never displays.
- Audit filtering/pagination still work; oversized legacy payloads are bounded and small
  legacy secrets are redacted for display without editing the originals.
- Security events with no change details remain recorded; audit maintenance cannot audit itself.
- Binary/secret fields, quoted credentials, nested API tokens and unnecessary relationships
  are excluded. Oversized payloads remain valid JSON and summaries remain stable on reprocessing.
- Expiration preserves recent, important, unknown, protected and undated records as intended.
  Invalid retention cannot shorten important history below routine retention.
- Dry runs do not modify records. Reviewed plans, checksums, current policy, bounds and cursors
  control execution. Missing/stale/wrong-database/incomplete-ID-coverage backups are rejected.
- Compaction preserves actor/action/record/timestamps and skips oversized/unreviewed values.
- Repeated unchanged import rows do not create duplicate update events. A compact import summary
  distinguishes unchanged rows from actual changed existing rows; the existing UI result remains compatible.
- Existing backup and duplicate-Drive-cleanup checks pass using temporary files and fake HTTP.

## Measurements and local dry runs

Read-only local MySQL/MariaDB measurements covered all **12** existing audit rows:

| Column | Logical bytes |
| --- | ---: |
| `old_values` | 238 |
| `new_values` | 4,741 |
| `reason` | 103 |
| `user_agent` | 1,323 |
| Total measured payload | **6,405** |

InnoDB reported 16,384 allocated data bytes and 81,920 allocated index bytes, with an estimated
row count of 10. This demonstrates why estimated rows/allocation cannot be treated as exact
live payload measurements. This database does **not** reproduce production's 1.6 GiB allocation.
The full metadata-only report is [local-report.json](../output/audit-review/local-report.json).

The local prune dry run found zero expired candidates. The local compact dry run examined
12 rows and identified two possible compactions. Neither operation was executed against that
database. Preview plans remain private under Laravel storage; they are not production plans.

The synthetic fixture measured **386,670 bytes before / 118 bytes after**, for 1,000 GPX points,
100 transformers and one changed location. See [audit-payload-example.json](../output/audit-payload-example.json).
The numbers compare serialized old/new details only, not total table allocation. Production
event totals and recoverable disk space remain unmeasured until the administrator runs the
documented read-only report there.

## Changed files

| Files | Purpose |
| --- | --- |
| `app/Services/AuditService.php`; `app/Services/Audit/Payload.php` | Changed fields, redaction, payload budgets and explicit omission summaries |
| `app/Services/Audit/Storage.php`; `app/Console/Commands/AuditReport.php` | Bounded read-only measurement, classification and safe viewer projections |
| `app/Console/Commands/AuditMaintain.php`; `app/Services/Audit/BackupGuard.php` | Exact-ID preview plans, guarded batched pruning and resumable compaction |
| `config/audit.php`; `.env.example`; `routes/console.php` | Defaults, event policy and daily opt-in cleanup |
| `app/Services/Backup/Runner.php` | Add database fingerprint and covered audit ID to the existing verified backup receipt |
| `app/Http/Controllers/MdbOperatorEntryController.php` | Replace frequent full snapshots with affected row/header values and compact review/deletion details |
| `app/Http/Controllers/MdbWorkflowController.php` | Avoid duplicate source-upload audits, preserve deleted-section identity and fetch metadata-only action history |
| `app/Http/Controllers/MdbSurveyReviewController.php` | Do not audit unchanged verification check states |
| `app/Http/Controllers/GpxCalculatorController.php` | Record verified survey identifiers instead of a complete survey dataset |
| `app/Services/FeederImportService.php` | Compact import outcome and real-change/unchanged counts |
| `app/Services/DriveStorage/DuplicateFiles.php` | Apply the same budget to the existing completion-audit update |
| `app/Http/Controllers/Admin/AuditLogController.php`; `resources/views/admin/audit/index.blade.php` | Keep audit filters/viewing with bounded legacy details and omission notices |
| `tests/Feature/AuditStorageTest.php`; `BackupTest.php`; `MdbOperatorEntryTest.php`; `MdbWorkflowTest.php` | Focused safety/behavior and compatibility tests |
| `.gitignore` | Exclude the runtime process-lock file |
| `docs/AUDIT_STORAGE.md`; this validation file; metadata/measurement JSON artifacts | Deployment, cPanel cron, backup prerequisites and evidence |

No database migration, new table, package dependency, queue infrastructure or dashboard was added.
