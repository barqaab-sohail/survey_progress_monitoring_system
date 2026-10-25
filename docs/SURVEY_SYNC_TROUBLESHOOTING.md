# Get the Google Drive sync information from production

These first three checks only read information. You do not need to change cron, reconnect Google, retry jobs, or edit data to collect it.

## 1. Copy the current cron commands

1. Sign in to your hosting **cPanel**.
2. Search for **Cron Jobs** and open it, usually under **Advanced**.
3. Scroll to **Current Cron Jobs**.
4. Copy the five schedule values and the command for Laravel `schedule:run`, `survey-progress`, or `queue:work` entries. If none are listed, report **No cron jobs found**.
5. Do not add, edit or delete entries while collecting this information.

Example of the information needed (your path may be different):

```text
Minute: * | Hour: * | Day: * | Month: * | Weekday: *
Command: cd /home/ACCOUNT/APP_FOLDER && /path/to/php artisan schedule:run ...
```

## 2. Read the latest application run

1. Sign in to `https://hazeco.barqaab.pk` as Super Admin.
2. Open **Survey Progress**.
3. Scroll down to **Synchronization and calculation history**.
4. Copy the newest **sync** run's number, status, start/finish times and error.
5. Expand **Validation results**, if available, and copy any messages. If the table is empty, report **No sync runs shown**.

If you already clicked Sync, do not repeatedly click it while collecting this information. A **Queued** status means a task was saved but has not started. **Running** means it started; a very old running time can also indicate an interrupted worker. **Failed** means the error/log needs review. Google showing **Connected** does not establish that cron is running or the connected account can list the survey folder.

## 3. Find Laravel's private logs

1. In cPanel, open **File Manager**.
2. Find the Laravel application folder. It contains `artisan`, `app`, `bootstrap`, `routes` and `storage`; it may be outside `public_html`. Do not assume `public_html` itself is the application folder.
3. Open `storage`, then `logs`.
4. Use **View** for `laravel.log`, or the newest `laravel-YYYY-MM-DD.log` if the application uses daily logs.
5. Copy the recent error around the time Sync was clicked, including its timestamp and first error message. Useful terms are `Survey progress`, `Google`, `queue`, `OAuth`, `timeout`, `SQLSTATE`, `permission denied` and `cURL`.
6. If present, also read the last lines of `survey-progress-worker.log` and `scheduler.log`. These files may not exist until the new runner/cron is deployed.

Do not send the entire log. Remove passwords, access/refresh tokens, client secrets, cookies and authorization headers. Do not send `.env`. Do not edit/delete logs or create a publicly accessible diagnostic PHP page.

## Optional: cPanel Terminal

If cPanel provides **Terminal**, change into the application folder containing `artisan`, then run each command separately:

```sh
pwd
php -v
php artisan schedule:list
php artisan queue:failed
tail -n 80 storage/logs/laravel.log
```

These commands inspect the application; they do not start sync or retry failed jobs. If there is a dated log, use its actual filename in `tail`. If `php` is unavailable, too old, or has different extensions than the website, ask the host for the absolute PHP 8.2+ CLI path and substitute it. If Terminal is absent, use the three cPanel/application checks above or ask hosting support for the command output. Do not run `migrate:fresh`, `db:wipe`, `queue:flush`, `queue:clear` or broad cache clearing.

After the sync repair has been deployed, this additional diagnostic is available:

```sh
php artisan survey-progress:diagnose --check-drive
```

It verifies read access and prints safe configuration/queue information. It does not write survey progress, assignments or MDB records; it can refresh the separate Google token. To inspect filename matching for one feeder without saving progress, add `--feeder=407` using the actual feeder code. The command does not exist in older deployed code.

## What to send back

```text
Current cron schedule and command:
Latest sync run number/status:
Started / finished time:
Visible error / validation message:
Recent related Laravel log lines:
PHP version (if Terminal is available):
schedule:list and queue:failed output (if available):
```

## Verify after deployment and cron setup

1. Confirm `schedule:list` includes `survey-progress:work` every minute. Keep a single hosting cron invoking Laravel `schedule:run` every minute with the correct PHP executable and application path. Preserve unrelated cron entries/workers.
2. Wait for a hosting cron tick. Confirm the dashboard reports a recent runner heartbeat and the private worker log has no startup errors. A schedule listing alone does not prove the host invokes it.
3. Click **Synchronize now** once. Confirm loading appears immediately, then Queued/Running, scanned files and feeder updates, and finally Completed or a clear warning/error. Check at least one known feeder against its matched PDF/GPX submission quantities.
4. Close the dashboard while a task is queued/running. After several cron ticks, reopen it and confirm saved progress has continued. Check the private logs for interrupted jobs or hosting resource limits.
5. After the next configured daily time (default 02:00 Asia/Karachi), confirm a scheduler-requested run appears and finishes without visiting the page. A catch-up run should also start if cron resumes later that day. Do not change the server clock or approved records to test this.
6. Repeat a deliberate manual scan only after the first finishes. Confirm unchanged Drive contents produce the same counts rather than adding the quantities again. Confirm assignments, feeder baselines and MDB exports are unchanged.

If a task remains stuck, collect its status and logs rather than deleting its run/queue rows. If the host kills a single complete feeder inventory or length calculation, confirm CLI limits with hosting support; `--max-time=50` stops between jobs and cannot guarantee every job completes within 50 seconds.

## Production evidence supplied on 25 October 2026

The supplied crontab includes the Laravel minute scheduler at `/home/barqaabp/public_html/hazeco.barqaab.pk`, and the private Laravel log confirms that the daily `survey-progress:sync` command was invoked at 02:00 on 23, 24 and 25 October. This rules out an entirely absent scheduler for those invocations. Exit code 1 is still ambiguous: the deployed command returns it for both `failed` and `partial` runs, so inspect the stored run error/issues before diagnosing authorization or resetting counts.

The supplied crontab has a worker for `drive-storage` / `mdb-sources`, but none for the separate `survey-progress` connection/queue. Unless a separate process manager runs that queue, manual Sync tasks will remain queued. The supplied worker line also contains `storage/framework/queue-worker.lock/usr/local/bin/php`: the lock filename and PHP executable need a separating space. This is a separate upload/MDB worker issue, not a survey-queue consumer. Preserve its queue choices and the HRMS cron entries; do not bulk-retry existing failed jobs as a survey-sync fix.

For the currently deployed daily-sync code, a dedicated survey worker can be added in cPanel with all five schedule fields set to `*`. This command processes queued **survey progress tasks**, so it deliberately updates progress results through the existing logic. It does not consume the upload/MDB queues or retry failed jobs:

```sh
cd /home/barqaabp/public_html/hazeco.barqaab.pk && /usr/bin/flock -n storage/framework/survey-progress-worker.lock /usr/local/bin/php artisan queue:work survey-progress --queue=survey-progress --stop-when-empty --max-time=50 --sleep=1 --tries=1 --timeout=3600 >> storage/logs/survey-progress-worker.log 2>&1
```

Keep the existing `schedule:run` entry. If a process manager already runs this survey queue, review that process instead of adding another worker. This dedicated worker cron is an alternative to the repaired application's scheduled `survey-progress:work` runner; once that new runner is deployed and verified, keep one intended runner arrangement rather than accumulating duplicate cron entries. The external lock prevents overlapping instances of this command; `--max-time` is checked between jobs. Old complete-inventory jobs can run longer than 50 seconds and remain subject to hosting limits. The local repair's new per-feeder job checkpoints are not present until deployed.

To read the latest three sync run summaries on the existing deployment without changing records or starting a job:

```sh
php artisan tinker --execute='dump(App\Models\SurveyProgress\Run::where("type", "sync")->latest("id")->limit(3)->get()->map(fn($r) => ["id" => $r->id, "status" => $r->status, "error" => $r->error, "issues" => array_slice($r->result["issues"] ?? [], 0, 10)])->all());'
```

This uses the existing Tinker dependency and shows a bounded issue sample, not tokens or full inventories. Alternatively, copy those fields from the dashboard's synchronization history. The supplied `laravel.log` excerpt only contains Laravel's scheduler failure wrapper, not the survey run's underlying error or source-validation issues.
