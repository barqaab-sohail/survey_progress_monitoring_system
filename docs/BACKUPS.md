# Daily application code and database backups to Google Drive

The existing `backup:run` command now exports the complete MySQL/MariaDB database to `database.sql`, then creates a normal ZIP named like **`backup-2026-10-29-0200.zip`** (Pakistan time). Download and extract it with any ordinary ZIP tool. No backup encryption key, custom recovery program, mail configuration or retention setup is required.

The ZIP contains `database.sql` and `application/` with application source, migrations, resources, dependency manifests/lock files and deployed public assets, including `public/build`, Filament and PDF.js. Composer `vendor`, `node_modules`, logs, caches, temporary files, previous archives, `.env` variants, common credential/key files and all `storage` uploads are excluded. No resolved configuration snapshot or `APP_KEY` is written into the ZIP. Keep custom secret files out of application source; if needed, add their paths to `config/backups.php` exclusions.

Existing Drive files, previous ZIPs and encrypted backups are **never deleted or pruned**. Uploaded originals on Drive are not downloaded or repackaged. Local uploads are outside this code/database-only backup. Restored database records retain their Drive references.

## One-time setup

1. Reuse the application's **Google Drive → Application file storage → Connect application storage account** connection. If it is already connected and working, leave it connected. Its encrypted database token and automatic OAuth refresh are reused; personal imports and survey-progress read-only connections are separate. Unattended access needs a valid refresh token and existing `GOOGLE_DRIVE_CLIENT_ID`/`GOOGLE_DRIVE_CLIENT_SECRET`. External OAuth apps left in Google's Testing state may require reconnection after token expiry.
2. Keep the existing `BACKUP_DRIVE_FOLDER_ID` if it is already a dedicated private folder. Otherwise run `php artisan backup:folder` once using the hosting PHP CLI; save the printed folder ID. It creates **Application backups** without adding public sharing permissions. In Drive, confirm **General access: Restricted**. A manually created folder may not be accessible to the existing limited `drive.file` authorization.
3. Create `/home/CPANEL_USER/private-survey-backups` outside the application and **outside all web roots** (`public_html`, `www`, `htdocs`, etc.). Make it writable by the hosting account with permissions 0700. SQL/ZIP files use 0600. The runner checks the private path and uses a process-lifetime lock.
4. Set only these backup values in the production environment:

   ```dotenv
   BACKUP_ENABLED=true
   BACKUP_PATH=/home/CPANEL_USER/private-survey-backups
   BACKUP_DRIVE_FOLDER_ID=YOUR_DEDICATED_FOLDER_ID
   # Optional: omit when mysqldump is already on PATH.
   BACKUP_MYSQLDUMP=/usr/bin/mysqldump
   ```

   Existing application/database/Google credentials are reused. Old `BACKUP_PUBLIC_KEY`, retention, alert and scheduling environment settings are no longer used. Do not change the application's actual `APP_KEY`.
5. From the application directory run `php artisan config:cache`, then the manual command below. Confirm the ZIP appears in Drive and opens normally before enabling cron.

Bluehost CLI needs PHP 8.2+ with Zip/PDO MySQL and the application's usual extensions, enabled `proc_open`, a working `mysqldump`, outbound Google HTTPS access, and enough quota/runtime. The exporter includes tables, data, views, triggers, routines and events; it fails rather than silently omitting objects when privileges are insufficient. Current online consistency checks require InnoDB tables. No extra Composer package, root access, daemon or backup queue worker is needed.

## Manual backup

From the directory containing `artisan`:

```sh
php artisan backup:run
```

Use the same PHP executable as the website/cron if the host's default `php` differs. Manual runs work even while `BACKUP_ENABLED=false` and can retry after a failure. Each upload has a fresh Drive file ID, so same-minute filenames never overwrite an earlier backup.

## Exact Bluehost cron setup

In **cPanel → Advanced → Cron Jobs**, set **Minute `*/15`, Hour `*`, Day `*`, Month `*`, Weekday `*`**. Replace `CPANEL_USER`, the app path and PHP binary below with the actual verified hosting paths. Use this command:

```sh
cd /home/CPANEL_USER/survey_progress_monitoring_system && umask 077 && /usr/local/bin/php -d max_execution_time=0 artisan backup:run --scheduled >> /home/CPANEL_USER/private-survey-backups/cron.log 2>&1
```

Full crontab equivalent:

```cron
*/15 * * * * cd /home/CPANEL_USER/survey_progress_monitoring_system && umask 077 && /usr/local/bin/php -d max_execution_time=0 artisan backup:run --scheduled >> /home/CPANEL_USER/private-survey-backups/cron.log 2>&1
```

The command polls every 15 minutes but starts **one scheduled backup per Pakistan date, at 02:00 Asia/Karachi**, independent of server timezone. It catches up at the next invocation if 02:00 was missed. Scheduled failures log once and wait until the next day; use the manual command to retry sooner. Both paths use the same exclusive process lock. On a fresh installation enabled after 02:00, the first poll catches up that day's backup.

If an existing Laravel `schedule:run` cron already runs reliably, keep it instead: `routes/console.php` already calls the same scheduled backup command every 15 minutes. **Choose one scheduling path.** Keep unrelated application workers/schedulers running at their existing frequencies.

Bluehost's [cron setup instructions](https://www.bluehost.com/help/article/set-up-edit-add-remove-cron-jobs) explain the account-specific controls. Confirm the actual PHP/dump paths and plan's CPU/CLI runtime with Bluehost; `max_execution_time=0` does not override hosting limits. The literal username/app directory cannot be finalized without hosting access.

## Success, failure and local cleanup

- A successful, nonempty database export is required before creating/uploading the ZIP. The ZIP's contents are checked after compression.
- Uploads use the existing Drive client and bounded retries. Drive must confirm the correct file ID, folder, size and MD5 checksum before **this run's** private SQL/ZIP directory is deleted. No download of existing files is needed.
- `Backup successful.` or `Backup failed.` is written to the **existing application log** (`storage/logs/laravel.log` with the usual single logger; daily logging uses dated files). Error context contains a safe stage/reason, not SQL, tokens, credentials or Google responses. Cron stdout also reports the result.
- On failure, any staged SQL/ZIP stays in a private `BACKUP_PATH/run-...` directory. It is not overwritten or deleted by later runs. After resolving the issue, run the manual command; inspect/remove an older failed directory only once its files are no longer needed. Old encrypted staging and old receipts/state are left alone. There is no automatic cleanup of previous backups.
- Internal defaults retain a 30-minute cooperative runtime budget, 5 GiB workspace budget, 256 MiB free-space reserve and three Drive attempts. If your real project exceeds these or the hosting plan's limits, adjust `config/backups.php` deliberately after confirming available resources. Hosting/account quota may differ from filesystem free space. Avoid deploying/changing source files or database schema during capture.

The ZIP is intentionally unencrypted and includes sensitive database contents. Restrict the Drive folder and local backup directory to the intended recovery administrators.

## Preserve essential configuration separately

Keep a secure, current copy of the production **`.env`**, settings injected through cPanel, and any external credential/certificate files in your organization's password manager or protected off-host recovery storage. Preserve the **original `APP_KEY`** and any previous application keys exactly; they decrypt existing OAuth tokens and other stored encrypted data. Do not put these files into the ZIP, Git or a public directory, and do not run `key:generate` when restoring existing encrypted data.

## Brief restoration

1. Download a successful `backup-...zip` from Drive and extract it normally. Start with an isolated directory and **new test database**, never the production database for a rehearsal.
2. Restore `application/`, recreate the separately preserved `.env`/server settings and original `APP_KEY`, and point database settings at the intended recovery database. Keep cron, outbound integrations and workers disabled while testing. Existing Drive originals stay where they are; do not clear or rewrite their restored database references.
3. Import `database.sql` using cPanel/phpMyAdmin **Import**, or `mysql --host=TEST_HOST --user=RESTORE_USER --password NEW_TEST_DATABASE < database.sql` (`--password` prompts). Confirm the destination first. A different host/account may require a DBA to adjust stored-object definers; disable MySQL's event scheduler during an isolated rehearsal.
4. Recreate writable `bootstrap/cache`, `storage/framework/{cache/data,sessions,views}` and `storage/logs` directories with appropriate ownership, run `composer install --no-dev --prefer-dist --no-interaction`, then `php artisan config:clear` and `php artisan storage:link`. Serve only `application/public`. Deployed assets are already included; use the archived dependency lock files if rebuilding is required.
5. Check login, database records, assets and authorized Drive file access. Local uploads are not in this ZIP. Restore them separately if required. Do not run database-reset commands or seeders on restored application data. Enable production jobs/integrations only after verification.

New ZIPs have **no custom recovery-tool requirement**. Existing encrypted archives remain unchanged and still require their original private key; retain that key for those older archives. `scripts/backup-recover.php` and `Cipher.php` are kept solely as optional legacy readers, and are not called by the daily backup.

## Verification and remaining hosting work

Focused tests verify normal ZIP extraction/import, code/lock/deployed-asset inclusion, secret/upload/dependency exclusions, successful cleanup, failed export/upload/checksum retention, Pakistan scheduling, overlap protection, normal application logging, preservation of previous backups and existing Drive-storage behavior. Legacy encrypted-reader tests remain so older archives can still be read. Google responses are simulated in these tests.

The local opt-in `scripts/backup-restore-smoke.php` also passed with the actual XAMPP MySQL/MariaDB dump/import binaries: it created disposable schemas, generated a normal ZIP, imported its SQL into a new schema, and checked code, Unicode/binary/encrypted database data, foreign keys, a view and a trigger. It does not use a backup encryption key or overwrite existing databases.

**Still requires Bluehost/Google access:** confirm actual CLI paths, plan limits, database object privileges and private-directory permissions; install the settings and cron; confirm the production application storage connection/folder access; perform a real production upload/checksum check and an isolated restoration. No production `.env`, old backup archives or Drive originals were modified by this simplification.
