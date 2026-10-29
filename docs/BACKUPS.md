# Encrypted daily Google Drive recovery backups

## Implementation and inspected project

This project locks **Laravel 12.69.3**, requires PHP **8.2+**, uses Filament 5, and configures MySQL/MariaDB with InnoDB. The development CLI is PHP 8.3.33 on Windows/XAMPP. Production is described as Bluehost; the repository cannot identify its plan, cPanel account, PHP binary, quota or process limits. Deployment and an actual production/Google upload require that access.

The new Artisan commands use installed Laravel HTTP, notification and scheduler facilities plus PHP Sodium/Zip and the system `mysqldump`; no Composer dependencies or database migrations were added. `App\Services\DriveStorage\DriveClient::client()` is now protected so the backup adapter can reuse its encrypted application OAuth connection, refresh-token persistence and refresh lock. Personal user tokens and the survey-progress read-only connection are unsuitable for this job.

## What an archive contains

Each dated `survey-production-YYYYMMDDTHHMMSSZ-UUID.zip.backup.enc` decrypts to:

- `database.sql`: a complete, nonempty MySQL/MariaDB export, including schema, rows, binary data, views, triggers, routines and events. Every base table must be InnoDB. `--single-transaction --quick --skip-lock-tables` provides a consistent online database snapshot. MySQL-only GTID/column-statistics flags are added only when the installed dump binary supports them.
- `application/`: application PHP, configuration, migrations, resources, routes, scripts, deployed assets (including `public/build`, Filament and PDF.js), mobile source if present, `composer.json`/`composer.lock`, available JavaScript lock files, `artisan`, Vite configuration, `.htaccess`, `.env` if present, and persistent `storage/app` uploads/templates. Hidden files are included. `storage/*.key` is included for applications using additional key files.
- `drive-originals/ID`: verified copies of **every** managed `drive_stored_files` original, including originals already removed from Bluehost. The manifest records their original disk/path and Drive identity. A missing, unreadable, changed or corrupted original fails the backup.
- `recovery/effective-config.json`: resolved Laravel configuration, including the original `APP_KEY`, previous application keys, resolved database/service/mail/worker settings. This covers secrets injected by cPanel rather than saved in `.env`. This file is sensitive; it is never logged or uploaded unencrypted.
- `manifest.json`: snapshot time, framework/PHP versions, file sizes and SHA-256 hashes, plus original-file recovery mappings.

Vendor dependencies, node modules, Git metadata, caches, logs, temporary/worker files, generated disposable MDB exports, public storage symlinks and previous backup directories/archives are excluded. Dependency installation is reproducible from Composer's lock; deployed public assets are included even when ignored by Git. There is currently no JavaScript lock file in this checkout; existing built assets are therefore important. Do not generate a new dependency lock as part of a restore.

The exact persistent roots and exclusions are editable in `config/backups.php`. Standard uploads under `storage/app/private` and `storage/app/public` are covered. Before activation, check any custom local disks/external absolute paths or additional secret files: copy them into a persistent project root and add that root, or arrange a separate encrypted backup. Source symlinks are rejected rather than followed outside the project. Arbitrary Google Drive URLs stored as evidence links are references, not application-managed originals; this limited `drive.file` connection cannot read them automatically. If those external documents are essential, grant access through a suitable managed-file workflow or back up their owner accounts separately.

File inventories and managed-original identities are checked before and after the database snapshot/archive, and ZIP contents are hashed after compression. Concurrent uploads, replacements or deletions cause a safe failure when they change that inventory. Schedule deployments, schema changes, direct filesystem changes and bulk uploads outside the backup window. This is an online InnoDB snapshot with stable-file checks, not a storage/VM snapshot across unrelated external systems; no maintenance-mode outage is imposed.

## Keys and private staging

On a trusted recovery computer, outside Bluehost and outside any synced backup-archive directory:

```sh
php scripts/backup-recover.php keygen /secure/offline/survey-backup-keys
```

The destination must not exist. The utility prints **only the public key**. Set `BACKUP_PUBLIC_KEY` on Bluehost to that value. Store `backup-private.key` in an organization password manager/offline encrypted vault, with an independently protected second copy and named recovery custodians. Never copy it to Bluehost, put it in `.env`, Git, cron, mail, or the Drive backup folder. Keep older private keys when rotating public keys, and label their validity periods. Losing the private key makes backups unrecoverable. Preserve a recent receipt SHA-256 independently as an additional recovery check.

Archives use a fresh random symmetric key, authenticated XChaCha20-Poly1305 secretstream encryption in bounded frames, and a Sodium sealed box addressed to the public recovery key. Bluehost can encrypt without possessing the recovery private key. Alteration, truncation, wrong keys and trailing data are rejected on decryption. Do not use `APP_KEY` as the backup-encryption key.

`BACKUP_PATH` must be a dedicated directory outside the project, all served disks and **every web document root**, e.g. `/home/CPANEL_USER/private-survey-backups`, never `public_html`, `www` or `htdocs`. Common web-root paths are rejected, as are symlinks. For an unusual layout, add comma-separated absolute document roots to `BACKUP_WEB_ROOTS`. The runner enforces directory mode 0700, file mode 0600 and umask 0077 on Linux, and writes an Apache deny rule as defense in depth. Serve Laravel only from its `public` directory. On Windows, protect test/recovery directories with the user's NTFS ACLs.

Plain SQL/ZIP/config snapshots exist only in this restricted workspace while capturing/encrypting and are removed on success or handled failure. Encrypted pending archives are kept through upload/verification failure. Shutdown cleanup is best effort; an external SIGKILL or host crash can leave restricted plaintext until the next locked invocation removes it. Do not disable the cron during incident recovery without checking this directory. Remote decryption is intentionally performed only on the recovery computer.

## Google Drive setup

1. Use the existing Google Cloud web OAuth client: enable Drive API, configure consent, and register the exact production `GOOGLE_DRIVE_REDIRECT_URI` (currently documented as `https://hazeco.barqaab.pk/auth/google/callback`). Configure existing `GOOGLE_DRIVE_ENABLED`, `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, and redirect URI securely in production.
2. Sign in as a super administrator, open **Google Drive → Application file storage → Connect application storage account**, and consent with the organizational recovery/storage account. This must be the original account if existing managed uploads depend on it. The existing UI requests offline access and `drive.file`; a refresh token is mandatory for unattended backups. Tokens remain encrypted in `drive_storage_connections` using the original `APP_KEY`.
3. Use a production/internal consent configuration suitable for unattended access. External apps left in Google's **Testing** state normally receive refresh tokens expiring in seven days. Tokens can also be revoked; alerts must be monitored. See [Google OAuth token lifecycle](https://developers.google.com/identity/protocols/oauth2) and [offline authorization](https://developers.google.com/identity/protocols/oauth2/web-server).
4. Run `php artisan backup:folder` once. It creates a dedicated folder through this OAuth app and prints `BACKUP_DRIVE_FOLDER_ID=...`. Save that ID in the production environment and run `php artisan config:cache`. It does not modify the existing survey-source folder. The backup code creates no public sharing permissions; keep the folder restricted and inspect inherited/account sharing policies. Arbitrary existing folder IDs may be inaccessible under `drive.file`.
5. Ensure available Drive quota and outbound HTTPS to `oauth2.googleapis.com` and `www.googleapis.com`. Allow enough bandwidth for remote originals, archive upload and complete readback verification. Uploads use generated file identities and 8 MiB resumable chunks; retries reuse the identity. After an uncertain transfer, metadata is checked before another upload session starts. See [Google Drive upload protocol](https://developers.google.com/workspace/drive/api/guides/manage-uploads).

`GOOGLE_DRIVE_STORAGE_ENABLED` controls ordinary source transfers and need not be changed merely to enable backup. The application storage connection itself must exist even if transfers are disabled. Do not disconnect/revoke that connection while unattended backups or managed sources depend on it.

## Environment settings

Copy the documented block in `.env.example` into the **untracked production** environment, replacing all placeholders. Rebuild cached configuration after changes. No production `.env` or cached configuration was changed by this implementation.

| Variable | Default / purpose |
| --- | --- |
| `BACKUP_ENABLED` | `false`; enable only after a successful manual backup and restore rehearsal |
| `BACKUP_SCHEDULE` | `0 2 * * *`; cron expression evaluated inside the application |
| `BACKUP_TIMEZONE` | `Asia/Karachi` |
| `BACKUP_PATH` | Dedicated private path; explicitly set `/home/CPANEL_USER/private-survey-backups` |
| `BACKUP_PUBLIC_KEY` | Required base64 public key generated offline |
| `BACKUP_DRIVE_FOLDER_ID` | Required dedicated folder ID |
| `BACKUP_SET` | `survey-production`; stable unique identifier per application/environment |
| `BACKUP_RETENTION_DAYS` | `30`, minimum 1 |
| `BACKUP_ATTEMPTS` | `3`, clamped to 1–5 attempts per Drive operation |
| `BACKUP_RETRY_MINUTES` | `60`; delay between failed scheduled attempts |
| `BACKUP_TIMEOUT` | `1800` seconds total cooperative budget; set below the host's hard CLI limit |
| `BACKUP_HTTP_TIMEOUT` | `120` seconds per request, capped by remaining run time |
| `BACKUP_MAX_WORKSPACE_BYTES` | `5368709120` (5 GiB); dedicated-workspace budget |
| `BACKUP_MIN_FREE_BYTES` | `268435456` (256 MiB); filesystem free-space reserve |
| `BACKUP_MAX_AGE_HOURS` | `26`; freshness threshold measured from **snapshot**, not late upload time |
| `BACKUP_MYSQLDUMP` | `mysqldump`; example `/usr/bin/mysqldump`, verify on Bluehost |
| `BACKUP_ALERT_EMAIL` | Optional external operations mailbox, strongly recommended |
| `BACKUP_WEB_ROOTS` | Optional additional absolute web roots, comma-separated |

Existing `DB_*`/`DB_URL`, `APP_KEY`, Google OAuth settings and a working `MAIL_MAILER`/`MAIL_*` are reused. The inspected development mailer is `log`, which does **not** deliver email. Configure SMTP/provider delivery and set `BACKUP_ALERT_EMAIL`. Active super administrators receive synchronous Laravel database notifications; external mail is attempted separately, even if database notification delivery fails. Notification failures are logged. `backup:check --test-alert` sends an explicit delivery test; verify receipt in the application/database and mailbox. Its exit code still reflects backup freshness.

## Bluehost/cPanel deployment and exact cron entry

The runner needs neither root nor a daemon/queue worker. It is suitable for a Bluehost plan that exposes cPanel/portal cron and permits the following; confirm with the actual plan before enabling:

- PHP CLI 8.2+ with the application's extensions plus `sodium`, `zip`, `pdo_mysql`, HTTPS/cURL and enabled `proc_open`.
- A compatible `mysqldump` binary and database privileges for all tables/views/triggers/routines/events. Non-InnoDB tables are rejected. A failed export is never marked successful. Do not silently omit routines/events to bypass a permissions failure.
- A writable private home-directory path, sufficient disk **and inode/account quota**, and a CLI runtime/CPU allowance large enough for capture, compression, encryption, upload and readback. `disk_free_space` may not reflect account quotas; inspect cPanel usage and keep headroom. Compression/encryption are streamed or file-backed; the whole database/archive is not loaded in PHP memory.

Identify the real home/app/PHP/dump paths in cPanel Terminal/SSH. Verify `/usr/local/bin/php -v` (8.2+) and `command -v mysqldump`; use the host's appropriate EA-PHP CLI binary if different. PHP's `max_execution_time=0` does not override Bluehost CPU/process limits. If the plan forbids process execution or cannot finish a representative backup within its limits, this deployment cannot be activated unchanged: request an appropriate hosting tier or run a separate authorized backup host with secure database/filesystem access. The repository provides no evidence of the current plan's limits.

Prepare the path once:

```sh
mkdir -m 700 /home/CPANEL_USER/private-survey-backups
chmod 600 /home/CPANEL_USER/survey_progress_monitoring_system/.env
cd /home/CPANEL_USER/survey_progress_monitoring_system
php artisan config:cache
php artisan backup:doctor --remote
php artisan backup:run
php artisan backup:verify
php artisan backup:check --test-alert
```

Use the verified CLI binary for every `php` above. `backup:doctor --remote` checks configuration, private storage, binary availability, InnoDB, offline consent and folder readability; it does not prove write quota or the entire export. The manual run/readback is the deployment acceptance check. Follow the offline restore rehearsal below before setting `BACKUP_ENABLED=true` and rebuilding config again.

In **cPanel → Advanced → Cron Jobs** (or Bluehost Portal → Websites → Manage → Advanced → Cron Jobs), set **Minute `*/15`, Hour `*`, Day `*`, Month `*`, Weekday `*`**. Enter this exact command after replacing `CPANEL_USER`, app directory and PHP binary with the verified account paths:

```sh
cd /home/CPANEL_USER/survey_progress_monitoring_system && umask 077 && /usr/local/bin/php -d max_execution_time=0 artisan backup:run --scheduled >> /home/CPANEL_USER/private-survey-backups/cron.log 2>&1
```

Full crontab equivalent:

```cron
*/15 * * * * cd /home/CPANEL_USER/survey_progress_monitoring_system && umask 077 && /usr/local/bin/php -d max_execution_time=0 artisan backup:run --scheduled >> /home/CPANEL_USER/private-survey-backups/cron.log 2>&1
```

Polling evaluates `0 2 * * *` in **Asia/Karachi**, independent of the server cron timezone, and starts at **02:00 PKT (21:00 UTC the preceding day)** when cron is on time. A missed invocation catches up the most recent scheduled slot. A new installation first catches up immediately, so complete the manual run before enabling. Configured times not aligned to 15 minutes start on the next poll; use a minute-based scheduler only if your plan permits and exact finer timing is needed. For less-frequent schedules, adjust the freshness threshold accordingly.

If the application already has a supported every-minute `artisan schedule:run` cron, **keep that single scheduler** instead of adding the direct backup cron; `routes/console.php` invokes `backup:run --scheduled` every 15 minutes. Keep the scheduler frequency required by existing survey/Drive workers. Do not downgrade those unrelated workers to 15-minute intervals. The same file lock protects both scheduling paths and manual verification, and successful slots are not repeated.

Bluehost's [cron setup guide](https://www.bluehost.com/help/article/set-up-edit-add-remove-cron-jobs) describes account-specific setup; Laravel's [scheduler documentation](https://laravel.com/framework/docs/12.x/scheduling) explains the scheduler alternative. A literal account-specific command cannot be finalized without the Bluehost username, directory and PHP binary.

## Failure handling, retention and operations

`flock` holds a process-lifetime exclusive lock throughout capture, upload, verification and retention. It has no expiring TTL and is released by the kernel after a crash, so long runs cannot overlap when a cache lock expires. Keep one application host/workspace; cross-host coordination is not provided.

The runner bounds HTTP attempts with exponential delays, enforces a wall-time and workspace budget, estimates database/file headroom, checks free disk space, and verifies every decompressed ZIP entry. Every successful upload must pass Drive file-ID/parent/size/MD5 checks **and a full downloaded SHA-256 check**. Only then does it mark the file verified, persist a local success receipt, remove local encrypted staging and prune.

Retention is limited to verified files with this `BACKUP_SET` in this dedicated folder. It paginates, deletes verified archives older than the configured days, and always keeps the current receipt and newest verified file. Failed or unverified backups cannot trigger pruning. Existing successful receipts remain intact after failures. Files manually put in the folder, unverified interrupted uploads and different backup sets are not deleted; investigate orphaned uploads manually rather than auto-pruning unknown recovery material. Retention uses Drive creation time; very old snapshots uploaded late can stay longer than 30 days, conservatively.

One complete failed encrypted snapshot is retained in `pending.enc` with its receipt and stable Drive ID. Subsequent invocations retry and verify it before taking another snapshot. An old pending snapshot does not fulfill a new recovery slot; once uploaded, a fresh snapshot is made. A persistently failing pending transfer blocks new capture to avoid consuming unlimited local quota: resolve credentials/quota/network promptly and monitor freshness. Never delete it merely to make a failing status disappear. A changed/corrupted pending file fails verification rather than being silently replaced.

Private `storage/logs/backups-YYYY-MM-DD.log` files rotate after 14 days. They contain stage, fixed error reason, backup/file ID, byte counts and hashes; no SQL, configuration, API bodies, tokens, passwords or upload session URLs are logged. Rotate/truncate the private cron output log through hosting log management as needed. `state.json` and `receipt-*.json` in the private workspace contain recovery metadata, not credentials. A retention error can report failure even though the newest backup was successfully verified; check the receipt.

```sh
php artisan backup:run                 # immediate manual backup, even before enabling scheduling
php artisan backup:verify              # full readback of latest receipt, no private key needed on host
php artisan backup:check               # freshness, latest ID/SHA-256 and failure stage
php artisan backup:check --alert       # stale/interrupted alerts; health alerts throttled to six hours
```

Scheduled polling also checks freshness. External kills may prevent immediate notification; an unfinished-run marker is reported on recovery and a stale/time-budget check alerts on later polls. A process-exit cleanup failure is logged and retried. If cron/PHP/the entire hosting account stops, an application on that same host cannot send an alert: configure an independent uptime/dead-man monitor to check successful backup freshness, and enable cPanel Cron Email for PHP startup errors. Verify SMTP and cron delivery in the live account.

## Offline restoration and isolated production rehearsal

Never test by importing into production. Use a private recovery computer or separate staging host, a **new empty database and restricted test user**, and separate application directory. Disable mail, scheduled tasks, queues, webhooks and outbound integrations before booting a restored app. Revoke production write access for the test user. Keep the original recovery package offline; edit only a working copy.

1. Download a verified `.backup.enc` using its recorded Drive ID. Compare `sha256sum` with `backup:check`/the independently preserved receipt. Preserve the recovery utility and its `app/Services/Backup/{Failure,Cipher,Archive}.php` files outside Bluehost with the runbook; it needs PHP 8.2+, Sodium and Zip, and does not boot Laravel or contact a database.
2. On the recovery computer, authenticate/decrypt, verify all entries, and extract to a **new** private directory:

   ```sh
   php scripts/backup-recover.php decrypt downloaded.backup.enc /secure/offline/survey-backup-keys/backup-private.key recovered.zip
   php scripts/backup-recover.php verify recovered.zip
   php scripts/backup-recover.php extract recovered.zip /secure/restore-rehearsal
   ```

3. `application/.env` preserves the original file when one existed. Consult `recovery/effective-config.json` privately for settings injected outside `.env` and for the original `APP_KEY`/previous keys. Restore those application keys exactly; **do not run `key:generate`**. Keep the independent backup private key out of the restored app. Do not copy production cached configuration: it is excluded deliberately.
4. Before any Artisan/application boot, edit the working `.env` for staging: `APP_ENV=staging`, `APP_DEBUG=false`, private staging URL, **new test DB host/database/user/password**, remove/replace `DB_URL`, `MAIL_MAILER=log`, isolated file cache/session, `QUEUE_CONNECTION=sync`, `BACKUP_ENABLED=false`, `GOOGLE_DRIVE_ENABLED=false`, `GOOGLE_DRIVE_STORAGE_ENABLED=false`, `SURVEY_PROGRESS_AUTOMATIC=false`, and disable external MDB workers/webhooks. Do not start cron or a worker. The restored database contains encrypted production OAuth tokens; do not use them in a test environment.
5. Create the empty isolated schema with an administrative tool, assign only its test user, and import **explicitly into that schema**. The dump contains no `CREATE DATABASE`/`USE production_database` wrapper. Audit stored routines/events/views for schema-qualified names and `DEFINER` clauses when moving between servers/accounts; resolve incompatible definers with a DBA before import. Disable the target MySQL event scheduler during a rehearsal so restored events cannot run.

   ```sh
   mysql --host=TEST_HOST --user=RESTORE_TEST_USER --password RESTORE_TEST_DB < /secure/restore-rehearsal/database.sql
   ```

   `--password` prompts; never put the password value in the command line. Verify the target host/database/user independently before running. No automated command in this implementation imports into an existing production database.
6. Recover managed originals without depending on Google access:

   ```sh
   php scripts/backup-recover.php local-originals /secure/restore-rehearsal
   ```

   This copies verified `drive-originals` into the extracted app's standard local/public disks. Map any custom disk manually. In the **isolated restored database only**, reset managed storage pointers so the existing file-reading code uses these recovered local originals:

   ```sql
   UPDATE drive_stored_files
   SET status = 'pending', drive_file_id = NULL, account_id = NULL,
       uploaded_at = NULL, local_deleted_at = NULL, attempts = 0,
       next_attempt_at = NULL, last_error = NULL;
   DELETE FROM drive_storage_connections;
   DELETE FROM survey_progress_connections;
   UPDATE users SET google_drive_token = NULL;
   DELETE FROM jobs;
   DELETE FROM survey_progress_jobs;
   ```

   Check table existence against the archived schema; historical deployments may not contain every optional queue/integration table. Keep automatic transfers disabled while validating local recovery. For an actual disaster recovery, reconnect the intended storage account only after confirming originals and deciding whether to reuse existing Drive identities or re-upload recovered files. This isolated local-copy procedure intentionally clears external pointers; retain the original archive for reference.
7. From extracted `application`, first recreate writable `bootstrap/cache` and `storage/framework/{cache/data,sessions,views}` plus `storage/logs`, set appropriate ownership/permissions, then install production PHP dependencies with `composer install --no-dev --prefer-dist --no-interaction`. Run `php artisan config:clear` and `php artisan storage:link` on the isolated host. Serve only `application/public`. Public built assets are archived; if a JS rebuild is necessary, use an archived lock file with its package manager. Do not regenerate dependencies casually. Inspect `migrate:status`; do not run seeders, reset tables or apply newer migrations before checking the restored snapshot.
8. Compare table/row counts, verify login/roles, decrypt known application data, open representative local and recovered Drive attachments, inspect templates/assets, and exercise a representative survey read/export with external services disabled. Record snapshot age, total restore time and missing dependencies. Windows MDB generation still requires the existing separate Windows/Access worker; back up and provision that worker's installation/configuration separately. Only after a reviewed real recovery should you enable production integrations, cron and DNS cutover.

The offline tool refuses existing decrypt outputs/extract destinations, checks path safety, and never contacts a database. It does not automatically import SQL or overwrite an application directory.

## Verification performed in this workspace

On 2026-10-29, PHP lint and **31 focused PHPUnit tests (165 assertions)** passed, exercising streaming encryption/decryption, tampering/truncation/wrong-key rejection, the standalone offline recovery CLI, stable inventories, offline-consent refresh, encrypted token persistence, code/config/upload inclusion, remote-only originals, metadata mismatch, full readback corruption, failed-upload preservation/resume, chunked uploads/lost final responses, old-pending freshness, retention pagination/newest preservation, process locks, Karachi scheduling, quota failure, secret-free logs and alerts. Existing Drive-storage regression tests are included. Live Google responses are simulated in these tests, not a production upload.

The optional smoke script below runs **only** with `--run`, local/testing environment and loopback MySQL. It creates random disposable schemas, exports with the actual dump binary, encrypts/decrypts/verifies the archive, restores into its new schema, checks Unicode/binary/encrypted data, foreign keys, a view and a trigger, and drops only those generated schemas. It checks recovered code, upload fixture and `APP_KEY`; it does not restore production or test every application screen.

```sh
php scripts/backup-restore-smoke.php --run /usr/bin/mysqldump /usr/bin/mysql
```

In the Windows workspace this was run with `E:\xampp\mysql\bin\mysqldump.exe` and `mysql.exe`. Existing cached development configuration was bypassed only for test processes using `APP_CONFIG_CACHE` pointed to an absent temporary file; it was not cleared or rewritten. PHPUnit uses forced SQLite `:memory:` and test-only SQLite PHP extensions, preserving the existing database.

**Not completed without hosting/account access:** confirming the Bluehost plan/binaries/quota/runtime/cron, installing the public key and production environment, consenting/confirming the production Drive account, configuring SMTP recipients, creating the real backup folder, uploading/verifying a real production archive, checking live alert delivery, and rehearsing a full production-data restore on isolated infrastructure. Leave `BACKUP_ENABLED=false` until those acceptance checks pass.
