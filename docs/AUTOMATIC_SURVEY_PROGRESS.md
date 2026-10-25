# HAZECO automatic survey progress and LT length

This feature reads the configured Google Drive parent folder and writes only to new `survey_progress_*` tables and private `survey-progress/` storage. It does not import into MDB tables, use MDB entry rows, call MDB source parsers, change feeder baselines, or regenerate MDB files. Existing manual survey reports remain historical records and are not added to automatic counts.

## Counting and matching

`A_10102026_05.pdf` plus the matching GPX reports **five transformers**, not one. Matching is case-insensitive and normalizes the quantity's leading zeros. A unique group/date/quantity submission is counted once. Exact identical copies count once and are flagged. Incomplete pairs, conflicting file contents, invalid dates, and conflicting quantities for the same group/date submission are excluded. Counts are **Reported Surveyed Transformers**; filenames do not establish unique physical transformers. Feeder, group, date, and project totals are retained.

Folder names follow `FeederCode-FeederName`. Matching uses the code before the first hyphen against the configured project's master feeder code. Leading zeros normalize only for feeder codes; full 11-digit waypoint references retain every digit. Ambiguous folder or master-code matches need explicit Admin mapping. Names are never used as feeder identifiers. All discovered feeder folders are inventoried, including unmapped folders; unmapped inventories remain in synchronization history and do not affect a feeder's count.

The configured project's existing code is `HAZECO-TDL`. Expected immediate feeder folders: 154. A different count is reported as incomplete coverage; discovered mapped feeders can still synchronize. A failed or incomplete API listing never replaces the feeder's previous successful count. Successful complete inventories reconcile removed files and mark changed length calculations stale.

## Reviewed transcription

Survey PDFs are scanned/handwritten. No OCR service is enabled. Admin opens the original checksum-preserved PDF from **Review PDF S/E records** and enters CSV rows with five fields:

```text
11131222104,11131222104,01101026001,1,1
11131222104,01101026001,01101026003,1,2
```

Fields: historical transformer GPS_No, Start GPS_No, End GPS_No, PDF page, PDF row. Every branch is entered explicitly. The ending pole is connected to a subsequent starting pole only when the reviewed PDF specifies those spans. Incomplete references can be saved as drafts; approval requires all complete references and all transformer networks reported by that filename. If a surveyed transformer has no documented LT spans, keep it unverified rather than inventing a connection.

Each save creates an immutable version with author, reviewer, time, remarks, and PDF/GPX Drive IDs and checksums. A later draft supersedes a prior approval. Source changes invalidate approval for calculation until re-review. Original PDFs and downloaded calculation source evidence remain privately stored. Future OCR integration must create drafts for explicit Admin review; it must never bypass this reviewed transcription requirement.

## Length validation

KMZ attributes can be KML ExtendedData or an HTML table in the placemark description. Transformer identification uses `Equip_Type = Transformer`; the historical reference is `GPS_No`. `Pole Number`, `Equipment Number = T-{GPS_No}`, and feeder code are checked where applicable. Historical roots use the KMZ location; new poles use the corresponding submission's GPX waypoints. Duplicated or missing waypoint references are rejected for confirmed spans.

Only explicit reviewed S/E edges are measured. Branch connectivity is checked against the historical root. Undirected span keys remove reversed or repeated edges. Conflicting repeated coordinates, disconnected spans, and ambiguous roots are unverified. No connection is inferred from GPX order or from KMZ line geometry.

Horizontal distances use the WGS84 ellipsoid inverse geodesic (Vincenty). A geodesic that fails to converge is flagged and not confirmed. Elevation-adjusted distance is `sqrt(horizontal_m² + (end_elevation_m - start_elevation_m)²)`, converted to kilometers. These are point-to-point surveyed span lengths, not conductor sag measurements. Elevations must be meters in compatible vertical datums. Unknown GPX elevations stay null. KMZ zero altitude and non-absolute/ground-clamped altitude are not accepted as measured historical elevations. Missing elevations do not become zero. Unverified horizontal estimates and spans with no estimate remain separate from confirmed totals.

Counts remain filename-reported quantities regardless of transcription review. Lengths can be partial confirmed subtotals with outstanding validation warnings. A scanned submission without reviewed transcription has no calculated length. Stale or failed calculation results are excluded from current overall totals, while their history remains visible.

## Activation

For a first installation only, apply the separate migration if its tables do not exist. This sync repair adds **no migration**. Do not reset or re-import existing data or run unrelated pending MDB migrations:

```console
php artisan migrate --path=database/migrations/2026_10_10_010000_create_automatic_survey_progress_tables.php --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Configure the Google OAuth client with the callback shown by `SURVEY_PROGRESS_GOOGLE_REDIRECT_URI` (default `{APP_URL}/survey-progress/drive/callback`). In **Automatic Survey Progress**, connect the Admin's separate read-only Drive authorization. The account must have read access to the parent folder and descendants; Google OAuth must issue an offline refresh token. Existing MDB `drive.file` authorization and user tokens are untouched. Restricted read-only scopes may require Google consent-screen verification. OAuth testing-mode refresh tokens may expire; use a suitable production OAuth configuration for unattended operation.

The application scheduler now runs `survey-progress:work` every minute. That command queues the daily synchronization at/after 02:00 Asia/Karachi, catches up a missed tick, and drains **only** the dedicated survey-progress queue. Manual Sync also uses that queue, so it is processed without leaving the dashboard open. Normal completed scans and scans with source-validation warnings run once per day; failed API scans can retry after 15 minutes. Existing active runs are respected and historical statuses are not reset automatically.

In cPanel, keep one Laravel scheduler cron entry running every minute. Use the host's actual PHP 8.2+ CLI executable and Laravel directory containing `artisan`. The following is a template, **not a command with your actual paths**:

```cron
* * * * * cd /home/CPANEL_USER/PATH_TO_LARAVEL && /ABSOLUTE/PATH/TO/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

cPanel has separate fields for the five schedule values and command. Select **Once Per Minute** and enter only the command beginning with `cd` in its Command field. If this scheduler entry already exists, inspect/fix it instead of creating a duplicate. Keep other application cron entries and MDB workers. The host can confirm the PHP CLI path; it may differ from `php` used by a terminal. The command must run as the application owner, with write access to private `storage` and `bootstrap/cache`. Retain the application's persistent database/file/Redis cache; an `array` cache cannot share locks or heartbeat between cron and web requests.

The cron runner stops between jobs after about 50 seconds, leaving remaining jobs for the next cron tick. Each sync job inventories one complete feeder folder and saves a checkpoint. **50 seconds is not a hard limit for an individual job**. Sync job timeout is 300 seconds when PHP supports PCNTL; existing length jobs retain their 3,600-second timeout. Confirm the host permits these CLI processes, sufficient memory and outbound HTTPS to Google. A host that kills a single folder scan early needs a higher CLI limit or a suitable worker host; a browser visit will not solve that hosting limit.

Run diagnostics after deployment:

```console
php artisan schedule:list
php artisan survey-progress:diagnose
php artisan survey-progress:diagnose --check-drive
php artisan survey-progress:diagnose --check-drive --feeder=407
```

The last command is an optional read-only inventory of one feeder to report naming issues. It does not save counts, assign work, rename files, or change MDB records. A Drive access check may refresh the separate encrypted OAuth token. Diagnostics print configuration booleans, queue details, runner heartbeat, runtime and safe errors, never OAuth secrets or tokens. Basic diagnostics do not contact Google. Before sharing output, review it for private filenames. A healthy configuration report alone does not prove cron is installed; verify its heartbeat updates and an unattended sync finishes.

The dedicated database queue uses `survey_progress_jobs` and a 3,700-second retry interval. A persistent worker is optional on a host that supports it, using the same dedicated connection/queue; the minute runner is the default for cPanel. Default and MDB queue jobs are not drained by this runner.

Existing synchronous CLI entry point (this **does update progress counts**, so use only when intentionally synchronizing):

```console
php artisan survey-progress:sync
```

To enqueue instead of executing the complete inventory in that CLI request:

```console
php artisan survey-progress:sync --queue
```

Admin **Synchronize now** immediately shows loading, then polls a restricted status endpoint for files scanned, reported transformer quantities, feeder records updated, folder progress and warnings/errors. It shows the last successful feeder synchronization time. Repeated Sync clicks reuse an equivalent active task instead of creating another one. When the run finishes, the dashboard refreshes its totals. Without JavaScript, POST/redirect and manual refresh remain available. The browser does not perform the Drive inventory or keep the runner alive.

Survey progress tasks share bounded locks to keep inventories consistent. A sync job encountering a busy inventory lock is deferred without marking the run failed. Existing length calculation behavior is retained. If a process dies, inspect `storage/logs/survey-progress-worker.log`, `storage/logs/scheduler.log`, the private Laravel log and `php artisan queue:failed` before retrying. Existing queued/running records are not reset or deleted automatically. A stale run needs evidence that its process/job is no longer active before an administrator repairs it; do not clear all cache locks, delete queue rows or change historical records blindly.

See [the cPanel troubleshooting guide](SURVEY_SYNC_TROUBLESHOOTING.md) for collecting production evidence and verifying manual/unattended execution. This repair was tested with mocked Drive responses and an isolated SQLite database; production cron, API permissions and host limits must be verified on the actual host.

Local verification on 25 October 2026: 52 selected PHP tests passed (357 assertions), covering synchronization, inventory rules, geometry and assignment management. Five JavaScript tests and an intercepted Chrome desktop/mobile check passed. The broader application suite ran 556 tests and reported 44 failures plus two risky tests; it is not a clean full-suite result. Representative failures reproduce independently: legacy manual-survey/dashboard expectations conflict with the existing automatic-mode setting, and a staged MDB header fixture omits inspector/service-category fields required by the existing controller. Those rules and workflows were not relaxed by this sync repair. Review the outstanding application-suite failures before a general production deployment; actual host cron/Drive access remains unverified.

References: [Laravel scheduler](https://laravel.com/docs/12.x/scheduling), [Laravel queue workers](https://laravel.com/docs/12.x/queues), [cPanel Cron Jobs](https://docs.cpanel.net/cpanel/advanced/cron-jobs/), [Google offline OAuth access](https://developers.google.com/identity/protocols/oauth2/web-server#offline).

Manual survey progress writes are disabled by default at both route and service boundaries. `SURVEY_PROGRESS_AUTOMATIC=false` provides a controlled rollback to historical reporting; it does not delete automatic records. The overview used for MDB and its historical survey prerequisites retain their original data sources; the dedicated automatic survey dashboard and survey-team landing page display the new counts.
