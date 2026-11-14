# Convert legacy LT conductor codes

Run from the application directory. Take a database backup before applying and pause operator entry during the run.

Preview all MDB workflow batches without updating records:

```powershell
php artisan mdb:normalize-lt-conductors
```

Apply using an existing active Super Admin user ID (replace `ADMIN_ID`):

```powershell
php artisan mdb:normalize-lt-conductors --apply --admin=ADMIN_ID
```

Append `--batch=55` to limit either command to a batch, or `--project=PROJECT_ID` to limit it to a project.

Exact conversions: `A` to `ANT`, `W` to `WASP`, `G` and `GN` to `GNAT`. All targets must exist in the approved warehouse. Only live MDB entry-row/section conductor arrays and service-cable conductor codes are converted. Existing approved IDs, blank/absent values and unmapped values are retained; unmapped values are listed for separate review. Other engineering fields, imported KMZ transformer records, field-survey source observations, original-entry evidence, frozen revisions and historical files are preserved.

Apply records audited before/after conductor values and uses one transaction per batch. It advances each changed batch's revision once, invalidates approval, supersedes old exports, and clears affected transformer review checks. Review and resubmit changed batches and regenerate exports. If interrupted, completed batches remain committed; run the same command again to finish the remaining conversions. Re-running after completion makes no further changes.

The preview is read-only. Changes require `--apply`; no production changes are performed by installing this command.
