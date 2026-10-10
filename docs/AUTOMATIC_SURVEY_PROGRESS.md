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

Only apply this migration; do not run unrelated pending MDB migrations:

```console
php artisan migrate --path=database/migrations/2026_10_10_010000_create_automatic_survey_progress_tables.php --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Configure the Google OAuth client with the callback shown by `SURVEY_PROGRESS_GOOGLE_REDIRECT_URI` (default `{APP_URL}/survey-progress/drive/callback`). In **Automatic Survey Progress**, connect the Admin's separate read-only Drive authorization. The account must have read access to the parent folder and descendants; Google OAuth must issue an offline refresh token. Existing MDB `drive.file` authorization and user tokens are untouched. Restricted read-only scopes may require Google consent-screen verification. OAuth testing-mode refresh tokens may expire; use a suitable production OAuth configuration for unattended operation.

Daily command:

```console
php artisan survey-progress:sync
```

The Laravel scheduler runs this command daily at 02:00 Asia/Karachi. Ensure `php artisan schedule:run` is executed every minute by the host scheduler (or run `php artisan schedule:work` under a process manager). A schedule definition alone does not create a host scheduled task.

The separate database queue uses `survey_progress_jobs` and a 3,700-second retry interval. Run its dedicated worker:

```console
php artisan queue:work survey-progress --queue=survey-progress --timeout=3600 --tries=1
```

Admin **Synchronize now** and **Calculate LT Length** enqueue tasks on this connection. Start only one dedicated progress worker. Existing MDB queue connection/table and workers retain their settings. Survey progress tasks share a bounded application lock to keep inventories consistent. A task that cannot acquire the lock is logged as skipped; retry after the running task finishes. If a worker dies, inspect its log and failed-job record before retrying. A queued task should not be deleted blindly.

Manual survey progress writes are disabled by default at both route and service boundaries. `SURVEY_PROGRESS_AUTOMATIC=false` provides a controlled rollback to historical reporting; it does not delete automatic records. The overview used for MDB and its historical survey prerequisites retain their original data sources; the dedicated automatic survey dashboard and survey-team landing page display the new counts.
