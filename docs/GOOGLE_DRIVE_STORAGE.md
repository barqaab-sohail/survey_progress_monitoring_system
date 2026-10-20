# Automatic storage of survey source files

The application remains on its current host and database. The installed framework is Laravel **12.69.3** (`composer.lock`), PHP **8.2+** is required by `composer.json`. No Google SDK, Flysystem extension, hosting migration or database migration to Google is required. The implementation uses Laravel's HTTP client and the existing Google OAuth integration.

## Coverage and operation

Automatic storage covers PDFs and GPX files uploaded through **Create survey batch**, **source uploads/replacement versions**, **personal Drive imports**, the **transformer MDB workspace**, and **PDF sketch attachments** in Android field surveys and field-survey tests. Existing forms, route permissions and workflow roles are preserved. PDFs containing several transformers stay associated through their existing source IDs, transformer header page associations and S/E rows; the system does not assume one transformer per PDF.

Unrelated photos/image attachments, templates, MDB outputs and the separate read-only survey-progress scanner are unchanged.

1. The server validates the file and saves a **private, temporary recovery original**, plus a database transfer record, in the same application workflow.
2. A queue worker reserves a Drive ID and persists it before transfer. The worker uploads into an application-controlled private folder using a resumable session and verifies Google's size and MD5 against the original.
3. Only after saving the confirmed Drive reference does the worker delete the recovery original. A cleanup crash is recoverable without uploading another copy.
4. Failed transfers retain the original. Jobs retry with delays, and the scheduler recovers failed/missed dispatches. This means an outage can temporarily consume local disk space; monitor the backlog and disk capacity. The application never deletes an unconfirmed original merely because it is old.
5. Viewing, downloading and PDF/GPX processing use the existing authenticated routes. Remote files are downloaded into a short-lived private snapshot and checked with SHA-256. Worker snapshots are deleted in `finally`; response snapshots are deleted after serving, including range responses. The recovery command removes abandoned work snapshots older than 24 hours after a killed worker or request. These disposable work snapshots are separate from retained pending originals.

The database is still the source of truth. `drive_stored_files` stores Drive ID, original filename, MIME type, size, SHA-256/MD5, storage account identity, status, attempts, timestamps, recovery path and a context snapshot (project, feeder, batch/source or transformer workspace/code). `mdb_workflow_sources.drive_stored_file_id` and the workspace PDF/GPX reference columns link to it. Existing transformer/source associations continue to provide the current detailed relationships. A Drive ID is not an access grant; no public sharing permissions are created, and access/refresh tokens never go to the browser.

## Google credentials and consent

1. In the Google Cloud project belonging to your organization, enable **Google Drive API** and configure the OAuth consent screen. Create an OAuth client of type **Web application**.
2. Register the exact callback URL for this deployment, including any application subdirectory, e.g. `https://your-host.example/auth/google/callback`. Use HTTPS on production. The redirect URI in Google and Laravel must match exactly. Local testing can use an allowed localhost callback.
3. Use the least-privilege `https://www.googleapis.com/auth/drive.file` scope. The app requests offline access and consent so the worker can refresh credentials. OAuth state is tied to the signed-in user, expires after ten minutes and is consumed once; the flow also uses PKCE S256.
4. Use a durable organizational Google account with sufficient Drive quota. The API uploads into that account (or an explicitly authorized shared-drive folder). A service account alone is not a substitute for an account with suitable storage/Drive permissions.
5. If the Google consent app is in Testing, account/test-user restrictions and token lifetimes can interrupt unattended jobs. Configure an appropriate production/internal consent status before relying on daily unattended storage. Meet Google's consent and verification requirements for your actual deployment.

Official references: [uploads and safe retries with pre-generated IDs](https://developers.google.com/workspace/drive/api/guides/manage-uploads), [OAuth web-server flow and offline access](https://developers.google.com/identity/protocols/oauth2/web-server), [Drive API scopes](https://developers.google.com/workspace/drive/api/guides/api-specific-auth).

## Host configuration and activation

Back up the database, current private originals and `APP_KEY`. Use the current production key: encrypted Drive credentials cannot be recovered without it. Keep credentials in the server environment or untracked `.env`, outside the web document root; serve only Laravel's `public` directory. Restrict `.env`, backups, private storage and database access to the deployment account. Do not put secrets in JavaScript, Git or setup screenshots.

```dotenv
GOOGLE_DRIVE_ENABLED=true
GOOGLE_DRIVE_CLIENT_ID=your-web-client-id
GOOGLE_DRIVE_CLIENT_SECRET=your-web-client-secret
GOOGLE_DRIVE_REDIRECT_URI=https://your-host.example/auth/google/callback
GOOGLE_DRIVE_STORAGE_ENABLED=false
GOOGLE_DRIVE_STORAGE_FOLDER_NAME="Survey source files"
GOOGLE_DRIVE_STORAGE_FOLDER_ID=
GOOGLE_DRIVE_STORAGE_QUEUE_CONNECTION=drive-storage
GOOGLE_DRIVE_STORAGE_QUEUE=drive-storage
GOOGLE_DRIVE_STORAGE_TIMEOUT=180
```

Run on the current hosting, from the project directory:

```sh
php artisan migrate --force
php artisan config:cache
```

For a checkout with unrelated pending development migrations, inspect `php artisan migrate:status` first. The added schema can be applied alone with:

```sh
php artisan migrate --path=database/migrations/2026_10_15_010000_create_drive_storage_tables.php --force
```

Sign in as a **super administrator**, open **Google Drive → Application file storage**, and press **Connect application storage account**. Consent using the organizational storage account. This connection is encrypted in `drive_storage_connections` and is separate from personal Drive imports and the survey-progress read-only connection. Reconnecting requires the same Google account so previously stored files cannot be stranded. Personal **Disconnect Google Drive** does not delete or disconnect application storage.

Leave `GOOGLE_DRIVE_STORAGE_FOLDER_ID` empty to let the app create and remember a private folder. This is the simplest path with `drive.file`. Pasting an arbitrary folder ID does not authorize that folder for the app. If using a pre-existing folder or shared drive, ensure this OAuth app/account is actually authorized for it and can add files; otherwise the transfer fails safely and retains the staging original. The existing upload forms do not create public links.

Start a supervised worker using the **dedicated connection**, whose reservation timeout is 600 seconds:

```sh
php artisan queue:work drive-storage --queue=drive-storage,mdb-sources --sleep=3 --tries=5 --timeout=420
```

Keep existing workers needed for exports and other queues. Do not have an older worker with a 90-second reservation handling these same long-running queues; configure any worker consuming `drive-storage` or remote `mdb-sources` jobs with a reservation longer than the 420-second timeout. If overriding the connection, configure its `retry_after` to at least 600 seconds. PHP CLI needs the application's normal database driver; PDF inspection still requires the existing Python/PDF dependencies. The host needs outbound HTTPS to Google's OAuth/API endpoints and writable private temporary storage.

Configure the existing Laravel scheduler cron (one entry only), for example:

```cron
* * * * * cd /absolute/path/to/app && /usr/bin/php artisan schedule:run >> /absolute/path/to/private-scheduler.log 2>&1
```

Use the PHP executable/path appropriate to your host. If hosting does not support persistent workers, run `queue:work drive-storage --queue=drive-storage,mdb-sources --stop-when-empty --timeout=420` from a hosting cron with a non-overlap lock and sufficient runtime. Transfers are asynchronous; requests do not wait for Google. Review the cron job execution limits before enabling.

Finally set `GOOGLE_DRIVE_STORAGE_ENABLED=true`, run `php artisan config:cache` and `php artisan queue:restart`, and upload a small PDF/GPX pair. Confirm the transfer status is `uploaded`, both old application view/download URLs work, the processing job succeeds, and the corresponding private recovery originals have disappeared. Test with a non-authorized account as well. No real Google credentials are included in the repository or automated tests.

## Failure recovery and monitoring

### Connected account but production PDF does not display

Connection confirms OAuth consent; it does not confirm that queue workers are running. A source marked `queued` and files awaiting transfer require the worker above and the scheduler. Pending originals should still preview through the authenticated source route before processing or transfer completes.

Click **Open PDF** first. If the original opens there but the embedded viewer fails, check browser Network requests for `vendor/pdfjs/build/pdf.min.mjs` and `pdf.worker.min.mjs`. Both must return HTTP 200 and a JavaScript Content-Type, rather than HTML or `application/octet-stream`. Deploy the entire `public/vendor/pdfjs` directory. The supplied `public/.htaccess` sets `.mjs` to `text/javascript` on Apache when `mod_mime` and overrides are enabled. For Nginx or Apache configurations that ignore `.htaccess`, configure the equivalent MIME mapping in the host configuration. Also check that generated asset/source URLs use the production HTTPS host and correct application subdirectory.

When PDF.js cannot load a document, entry and verification pages automatically embed the same authenticated source in the browser's PDF viewer. Use that viewer's own toolbar for navigation; interactive row overlays and line drawing require PDF.js to recover. The fallback is removed on source changes and does not modify saved records or drafts. Deploy `public/js/mdb-pdf-errors.js` together with both updated viewer scripts.

If **Open PDF** also fails, inspect that source request's status: 401/403 indicates session/access, 404 a missing original/reference, and 503 unavailable storage or failed integrity verification. Inspect the retained original and storage account before changing any data. Reconnecting OAuth does not repair missing files or start workers.

After deploying the viewer fix, clear compiled views and restart managed queue workers:

```sh
php artisan view:clear
php artisan config:cache
php artisan queue:restart
php artisan drive-storage:retry
php artisan queue:failed
```

`queue:restart` only signals existing workers; ensure the process manager actually runs `php artisan queue:work drive-storage --queue=drive-storage,mdb-sources --sleep=3 --tries=5 --timeout=420`. For shared hosting, use the cron alternative above. Hard-refresh the PDF entry page after deployment.

The administrator Drive page reports the pending/failed transfer count. Inspect `drive_stored_files.status`, `attempts`, `last_error`, `next_attempt_at`, `uploaded_at` and `local_deleted_at`, as well as worker health, free staging disk and the Laravel failed jobs store. Alert if the backlog grows or an `uploaded` row has no `local_deleted_at`. Error messages intentionally omit Google responses, access tokens and resumable session URLs.

```sh
php artisan drive-storage:retry
php artisan drive-storage:retry --id=123 --force
php artisan queue:failed
```

`--id` means the **drive_stored_files database row ID**, not a Google file ID. The recovery command runs every five minutes through Laravel's scheduler and works even after normal job retry exhaustion. Fix expired consent by reconnecting the original account, quota failures by increasing/freeing Drive capacity, and connectivity failures by checking the host's outbound HTTPS. Do not delete pending originals, change their recorded checksums, clear the file table or change the storage account to hide an error. Google files should be treated as immutable originals; editing/trashing/deleting them externally causes access or integrity checks to fail. Existing source-version uploads create separate archived originals.

Disabling `GOOGLE_DRIVE_STORAGE_ENABLED` stops new automatic registrations/transfers; previously archived files continue to be read through the storage account, and confirmed cleanup still runs. Keep that connection, migrations, application encryption key and read integration in place. A rollback that removes this integration requires first restoring remote originals locally; merely disabling the flag does not download the archive back to disk. Back up metadata/keys and arrange an appropriate organizational backup/retention policy for Drive files; Drive storage alone is not an application/database backup.

## Existing originals

### Feeder folders

New uploads with an associated feeder are stored as:

```text
Survey Source Files/
  407-Nawan Shehra/
    A_10102026_05.pdf
    A_10102026_05.gpx
  408-Other Feeder/
    ...
```

The child folder uses the application's feeder code and name. It is shared by that feeder's batches, dates and upload types; original filenames are preserved. The root is still controlled by `GOOGLE_DRIVE_STORAGE_FOLDER_ID` or created by the application when that value is empty. Files with no feeder association stay at the root. Feeder IDs, rather than filenames or folder-name searches, determine associations. A durable `drive_storage_folders` mapping separates accounts, storage roots and feeders and reuses reserved Google folder IDs after interrupted requests. An unavailable, trashed or externally moved feeder folder blocks that upload safely rather than silently saving in the wrong location.

After deploying the updated `DriveClient`, source service, folder model, organizer command and migration, run as the application account from the project root:

```sh
php artisan migrate --path=database/migrations/2026_10_17_020000_create_drive_storage_folders_table.php --force
php artisan config:cache
php artisan queue:restart
php artisan drive-storage:retry --force
php artisan drive-storage:organize --limit=100
```

The organizer moves already uploaded application originals out of the current root into their feeder folders. It verifies the original account and remote size/checksum, updates only Google parent metadata and checks the resulting parent. File IDs, source URLs, filenames and contents stay the same; no local recovery original or second upload is needed. Re-running is safe, including after an interrupted move. Separate uploads with identical names remain separate originals. Files outside the current root, mismatched accounts or failed integrity checks are reported for inspection rather than moved. Files without a feeder are skipped.

For larger archives, use the last inspected stored-file ID printed by the command:

```sh
php artisan drive-storage:organize --after-id=100 --limit=100
php artisan drive-storage:organize --id=123
```

`--id` and `--after-id` refer to `drive_stored_files` row IDs. The organizer is an explicit maintenance command, not a recurring scheduler task. No additional Google OAuth scope or manually created folder is required for app-created feeder folders. Confirm a new PDF/GPX upload and an existing source's authenticated view/download after activation.

Existing local originals continue working without changes. No bulk deletion or automatic migration occurs when the schema is applied or the feature is enabled. To move existing source originals after the small-file checks succeed:

```sh
php artisan drive-storage:migrate-existing --limit=100
php artisan drive-storage:migrate-existing --limit=100 --include-workspaces
```

Repeat in batches until eligible originals have a storage reference; monitor transfers before continuing. Each original remains available during transfer and its existing route stays the same afterward. Original file versions are retained in Drive. Missing or checksum-invalid local survey sources are reported and left unchanged. The optional workspace migration skips workspaces without an eligible original. Existing PDF sketch attachments remain local and accessible until separately migrated by an administrator; newly uploaded PDF sketches are archived automatically.

## PDF viewer returns 503

The 2026-10-20 production logs exposed a batch deletion failure: the old controller deleted a staging file, then tried to delete its archive row while `drive_file_hash_claims` still referenced it. This left an archive record pointing to a missing original. Identical later uploads reused that record and discarded their fresh staging copies.

Deploy `app/Http/Controllers/MdbWorkflowController.php` and `app/Services/DriveStorage/FileStorage.php` together. Batch deletion now retains the reusable archive record and its staging original; Drive transfer cleanup still removes staging after a confirmed upload. Re-uploading an identical PDF/GPX restores a missing or invalid staging copy on a pending/failed archive without changing its ID or existing source references. Uploaded archives are never replaced by this recovery path. Restart the workers, re-upload the original files through the source upload form, and retry failed source processing as needed. No database reset or OAuth credential replacement is required by this fix.

After deploying `FileStorage.php`, reproduce the failed preview and inspect the application log for `Source storage read failed.`. The entry records only the stored-file ID, failure stage and exception class; do not enable public debug output or share OAuth credentials.

- `temporary_storage`: ensure the PHP application account can create private files in `storage/app/private/drive-storage/work` (or the configured local disk root).
- `storage_lock`: check the configured cache/lock backend and whether a transfer is still running. Retry after it completes; do not clear active locks while transfers run.
- `storage_record`: check database availability and the stored-file record.
- `drive_account`: reconnect the original application storage account.
- `drive_download`: check the application storage connection, outbound Google HTTPS access, and remote file availability.
- `staged_original`: check that the original staging file still exists and is readable by the PHP application account.
- `integrity_verification`: investigate the file size/hash mismatch; restore the correct original rather than bypassing verification.

A `queued` PDF also requires the source worker described above. With the default Drive queue settings, start `php artisan queue:work drive-storage --queue=drive-storage,mdb-sources --sleep=3 --tries=5 --timeout=420` under the host's process manager, or use the documented cron alternative. PDF inspection additionally requires the configured Python executable and PyMuPDF.

## Validation performed

Automated tests use fake Google HTTP responses, not production credentials. They cover verified transfer/cleanup, ambiguous upload completion and retry without duplicates, checksum rejection, temporary-file cleanup, pending access during an outage, encrypted token refresh, admin-only storage consent, separate personal disconnect, source processing after local removal and existing route access controls. A real OAuth/upload/download acceptance check on the deployment account remains necessary during activation.
