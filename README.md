# HAZECO T&D Losses Progress Monitoring System

Responsive Laravel web application for monitoring the HAZECO baseline through field survey, survey verification, MDB creation, and third-party MDB verification.

The central principle is **minimum data entry, maximum management visibility**. Engineering files remain in Google Drive; this system stores quantities, responsibility, dates, workflow state, and secure links.

## What is included

- Secure session authentication, active-account checks, login throttling, six roles, and Filament Shield permissions
- Active third-party organizations for MDB review access
- Project → Circle → Division → Sub-Division → Grid Station → Feeder master hierarchy
- Native HAZECO XLSX import with imported/updated/rejected counts, row errors, and repeatable updates
- Transactional bulk KMZ import for transformer GIS points, explicitly linked to the Excel feeder master
- Survey teams, MDB teams, memberships, and feeder assignments
- Multi-feeder daily survey entry, row-level verification, return, correction, and resubmission history
- MDB creation limited to verified survey capacity
- MDB entries appear automatically in the third-party verification queue
- Third-party verification, required return reasons, MDB corrections and resubmission history
- Management and third-party dashboards, KPIs, backlog formulas, feeder status, daily trend, today/week/month totals
- Audit log, database notifications for returned surveys, CSV/Excel-compatible export, and print/PDF report output
- Mobile-first operational forms, responsive analytical views, and safe PWA shell readiness
- Filament control centre for users, roles, organizations, hierarchy, feeders, and import history
- Critical workflow, authorization, and XLSX-import tests

The complete architecture, ERD, table catalog, permission matrix, calculations, screen map, and delivery sequence are in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Deploy on another computer

Use this section for a new Windows/XAMPP computer, a Linux server, or a fresh clone from GitHub. The Git repository contains the application code and database migrations, but it does **not** contain `.env`, MySQL records, passwords/secrets, or the HAZECO workbook uploaded on another computer.

### 1. Install the prerequisites

- Git
- 64-bit PHP 8.2 or newer
- Composer 2
- MySQL 8.0+ or MariaDB 10.6+
- Apache 2.4 or Nginx for a permanent installation

Enable these PHP extensions: `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `intl`, `json`, `libxml`, `openssl`, `pdo_mysql`, `session`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, and `zlib`. XAMPP already includes most of them; enable missing extensions in the `php.ini` used by both Apache and the command line.

Node.js is not required to run the current interface because its application CSS and JavaScript are committed under `public/`. Install Node only when rebuilding Vite-managed assets.

### 2. Clone the repository and install PHP packages

Replace `<repository-url>` with the GitHub repository URL:

```bash
git clone <repository-url> survey_progress_monitoring_system
cd survey_progress_monitoring_system
composer install
```

Create the environment file.

Windows PowerShell:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Linux/macOS:

```bash
cp .env.example .env
php artisan key:generate
```

Never commit `.env` to GitHub. For an exact transfer of an existing live installation, securely copy its `APP_KEY` instead of generating a different one.

### 3. Create the MySQL database

Run the following as a MySQL administrator and replace the password:

```sql
CREATE DATABASE hazeco_td_losses CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hazeco_app'@'localhost' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT ALL PRIVILEGES ON hazeco_td_losses.* TO 'hazeco_app'@'localhost';
FLUSH PRIVILEGES;
```

Update the database and application settings in `.env`:

```dotenv
APP_NAME="HAZECO Transmission and Distribution Losses Calculation Project"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Karachi

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hazeco_td_losses
DB_USERNAME=hazeco_app
DB_PASSWORD=replace-with-a-strong-password

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

For a local XAMPP installation, `DB_USERNAME=root` and a blank `DB_PASSWORD` may work with the default MySQL configuration. Create a dedicated user before exposing the application to a network.

After changing `.env`, clear any old cached configuration:

```bash
php artisan optimize:clear
```

### 4. Choose how to load data

Choose **one** of the following paths.

#### Option A: clean installation

Use this for a new database. The seeder creates the roles, permissions, initial organizations, project, teams, and development users:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
```

Run `db:seed` only once on an empty database; the main seeder is not intended to be rerun over live data. It does not include the HAZECO feeder workbook.

Import the feeder workbook after seeding. Either sign in at `/admin`, open **HT Data Imports**, and upload the `.xlsx` file, or use:

```bash
php artisan hazeco:import-ht-data "/absolute/path/HT GIS Status Feederwise.xlsx" --project=HAZECO-TDL --user=admin@hazeco.test
```

On Windows, an absolute path can look like this:

```powershell
php artisan hazeco:import-ht-data "C:\Users\YourName\Downloads\HT GIS Status Feederwise.xlsx" --project=HAZECO-TDL --user=admin@hazeco.test
```

Optional sample dashboard data can be loaded for demonstration and removed before entering live progress:

```bash
php artisan hazeco:dashboard-demo load
php artisan hazeco:dashboard-demo remove
```

#### Option B: transfer the current database

Use this when the new computer must contain the same users, feeder baselines, survey entries, MDB records, permissions, and audit history as the old computer.

Export on the old computer:

```bash
mysqldump --single-transaction --routines --triggers -u hazeco_app -p hazeco_td_losses > hazeco_td_losses.sql
```

Import on the new computer after creating the empty database:

```bash
mysql -u hazeco_app -p hazeco_td_losses < hazeco_td_losses.sql
php artisan migrate --force
php artisan db:seed --class=ShieldPermissionSeeder --force
```

With XAMPP, the executables are normally `C:\xampp\mysql\bin\mysqldump.exe` and `C:\xampp\mysql\bin\mysql.exe`. Do **not** run the full `DatabaseSeeder` after restoring this backup; the specific permission seeder above is idempotent and adds permissions introduced by newer releases. Transfer any required files under `storage/app` separately; engineering files referenced by Google Drive links are not stored in this repository.

Keep SQL backups outside the public web directory and never commit them to GitHub.

### 5. Start and verify the application

For a quick local test:

```bash
php artisan serve
```

Open these addresses:

- Application: `http://127.0.0.1:8000`
- Filament administration: `http://127.0.0.1:8000/admin`
- Laravel health check: `http://127.0.0.1:8000/up`
- API health check: `http://127.0.0.1:8000/api/v1/health`

For a clean seeded installation, sign in initially with `admin@hazeco.test` and `Password123!`, then immediately change the password. Seeded credentials are for first-time setup only.

Run the deployment checks:

```bash
php artisan about
php artisan migrate:status
php artisan test
composer check-platform-reqs
```

### 6. Configure Apache/XAMPP

The web server document root must point to the repository's `public` directory, never to the repository root. Example XAMPP virtual host:

```apache
<VirtualHost *:80>
    ServerName hazeco.test
    DocumentRoot "C:/xampp/htdocs/survey_progress_monitoring_system/public"

    <Directory "C:/xampp/htdocs/survey_progress_monitoring_system/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Enable Apache `mod_rewrite`, restart Apache, and add this line to the Windows hosts file at `C:\Windows\System32\drivers\etc\hosts`:

```text
127.0.0.1 hazeco.test
```

Then set `APP_URL=http://hazeco.test`, run `php artisan optimize:clear`, and open `http://hazeco.test`.

### 7. Production finishing steps

Use HTTPS and production values:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning
```

Install and optimize the release:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan storage:link
php artisan filament:assets
php artisan optimize
php artisan filament:optimize
```

On Linux, make only Laravel's runtime directories writable by the web-server user:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

Run the database queue worker under Supervisor or systemd:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Schedule Laravel every minute:

```cron
* * * * * cd /var/www/hazeco && php artisan schedule:run >> /dev/null 2>&1
```

### Updating an existing installation from GitHub

Back up MySQL first, then run from the project directory:

```bash
php artisan down
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan db:seed --class=ShieldPermissionSeeder --force
php artisan filament:assets
php artisan optimize
php artisan queue:restart
php artisan up
```

If `git pull --ff-only` reports local changes, review and preserve them instead of forcing or resetting the deployment directory. Keep machine-specific settings in `.env`, which is intentionally ignored by Git.

`ShieldPermissionSeeder` is safe to run during an upgrade: it adds newly introduced Filament resource permissions and refreshes the Super Admin permissions. Do not run the full `DatabaseSeeder` over a populated live database.

### Common deployment problems

| Problem | Check or command |
|---|---|
| `No application encryption key` | Run `php artisan key:generate` for a fresh installation. |
| `could not find driver` | Enable `pdo_mysql` in the active CLI and Apache `php.ini`. |
| Database/session/cache table error | Confirm `.env`, then run `php artisan migrate --force`. |
| MDB login fails with `Unknown column 'status'` | Deploy and apply `2026_10_04_000100_add_mdb_verification_workflow.php`; the newer dashboard requires MDB review fields in the database. See the upgrade steps below. |
| CSS or Filament interface missing | Run `php artisan filament:assets` and hard-refresh the browser. |
| Changes to `.env` are ignored | Run `php artisan optimize:clear`; optimize again in production. |
| HTTP 500 on Linux | Check `storage/logs/laravel.log` and permissions on `storage` and `bootstrap/cache`. |
| Apache shows a directory or 404 | Point `DocumentRoot` to `public` and enable `mod_rewrite`. |
| Login redirects to `/public/` and shows `GET /` supports only `HEAD` | Run `php artisan route:clear` for immediate recovery. Deploy `app/Http/Middleware/PreserveSubdirectoryRootRoute.php` and its registration in `bootstrap/app.php` before rebuilding the route cache. |
| New installation has no feeders | Import the HAZECO workbook or restore the old MySQL backup. |
| Transformer KMZ menus are missing after an upgrade | Run `php artisan db:seed --class=ShieldPermissionSeeder --force`, then sign in again. |

Laravel's cached matcher removes the trailing slash from a directory installation's root, such as `/public/`. Symfony then loses the request's base path and the cached root route fails, although the dashboard correctly supports both `GET` and `HEAD`. `PreserveSubdirectoryRootRoute` makes that existing cached dashboard route available through Laravel's dynamic-route fallback for directory-root requests. The original authentication, active-account checks, and allowed methods still apply; ordinary routes keep using the compiled cache. This also covers the dashboard opened after signing in with a reset password. No additional password reset is needed. Verify by opening `/public/` as a guest (redirect to `/public/login`) and signing in (dashboard loads), including after `php artisan route:cache`.

### Upgrade the MDB verification database

An MDB user signing in loads dashboard totals for submitted, verified, and returned MDB files. The original `mdb_daily_entry_items` table has no `status` column; `database/migrations/2026_10_04_000100_add_mdb_verification_workflow.php` adds it with a `submitted` default, nullable review fields, and the `mdb_verification_histories` table. Deploying the updated PHP files without applying this database migration causes MySQL error `42S22 / 1054` on the dashboard, even though authentication succeeds. Clearing caches alone cannot add missing columns.

On production, ensure that migration file is uploaded and take a database backup. From the folder containing `artisan`, run:

```bash
php artisan optimize:clear
php artisan migrate:status
php artisan migrate --force
php artisan migrate:status
```

The MDB verification migration must show **Ran** for the database used by the website. Existing feeder, survey, and MDB IDs, quantities, links, and timestamps are preserved; legacy MDB items become submitted for review. Sign in as the MDB user and confirm the dashboard loads, then check the third-party MDB review queue. Run the usual release optimization after verifying the upgrade. Do not use `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `db:seed` on a populated production database. If the migration already shows **Ran** while `status` is absent, check that the CLI and website use the same `.env` and database, especially after restoring an older database backup, before applying any repair.

## Runtime requirements

Production target:

- PHP 8.2 or newer with the extensions listed in the deployment section
- MySQL 8.0+ or MariaDB 10.6+
- Composer 2.7+
- Apache 2.4 or Nginx

This repository uses Laravel 12, Filament 5, Filament Shield, and MySQL. Install the versions locked in `composer.lock`; do not change framework versions during deployment.

Node is not required at runtime: the Phase 1 interface ships a small, dependency-free CSS/JavaScript shell. The Laravel Vite scaffold remains available for future asset expansion.

## Local installation

Follow [Deploy on another computer](#deploy-on-another-computer). This project is configured for MySQL. Use the clean-install path for a new database or the backup/restore path when the new computer must retain existing project records.

## Development users

All seeded accounts use `Password123!` locally. Change or remove them before deployment.

| Role | Email |
|---|---|
| Super Admin | `admin@hazeco.test` |
| Project Manager | `manager@hazeco.test` |
| Survey Team Leader | `survey@hazeco.test` |
| MDB User | `mdb@hazeco.test` |
| Third-Party Processor | `thirdparty@hazeco.test` |
| Management Viewer | `viewer@hazeco.test` |

## Filament control centre

Open `http://127.0.0.1:8000/admin` and sign in with an authorized active account. The seeded Super Admin can:

- create and update users, organizations, projects, and the full feeder hierarchy;
- add or update HT feeder records and verified transformer baselines;
- upload the native HAZECO feeder workbook under **HT Data Imports**;
- bulk-upload client transformer files under **Transformer KMZ Imports** and review the resulting **Transformers**;
- manage granular resource permissions under **Shield → Roles**.

The legacy administration screens remain at `/legacy-admin` during the transition. Filament is the primary backend.

The source `HT GIS Status Feederwise.xlsx` imports 153 feeder rows, 2 circles, 7 divisions, 34 subdivisions, and 66 grid stations. Three source feeder codes are blank, so their stable internal codes are marked `PENDING-*`. The workbook contains load and consumer totals but no transformer baseline; imported feeders therefore start at `total_transformers = 0` with `baseline_pending = true` until an authorized user enters the verified baseline. The workbook and imported MySQL rows are not automatically included in a Git clone.

## Management decision dashboard

The application landing page now opens with a management-first visual summary:

- recommended survey or MDB resource action based on backlog and the latest seven-day output rate;
- survey completed/pending and verification-pending quantities;
- MDB files created versus verified surveys waiting for MDB creation;
- estimated days to clear each backlog;
- circle-level priorities and highest-backlog feeders;
- a 7/14/30-day production trend and period totals.

The optional demo command adds clearly flagged sample baselines and sample survey/MDB transactions to 18 imported feeders. A blue notice remains visible while sample data is active. Manage it with:

```bash
php artisan hazeco:dashboard-demo load
php artisan hazeco:dashboard-demo remove
```

Run `remove` before live progress entry. It deletes only records marked `[SAMPLE DASHBOARD]` and restores only feeders marked as sample baselines.

## Business workflow

1. Super Admin imports the HAZECO hierarchy and transformer baseline, creates users/teams, and assigns feeders.
2. Survey Team Leader submits one daily header with one or more feeder quantities.
3. MDB Team opens Drive evidence and verifies each row or returns it with a required reason.
4. Only verified survey quantity becomes available for MDB creation.
5. Saved MDB entries appear directly in the third-party review queue; the MDB user may edit before review.
6. Third-Party Processor verifies MDB files or returns an item with a required reason. The MDB user corrects and resubmits returned items; verified records are locked.
7. Management dashboard totals and backlogs are derived directly from transaction rows.

Critical writes use database transactions and row locks. Returned MDB quantities remain reserved against verified survey capacity while corrections are pending. Review actions and corrections preserve an audit trail.

Each feeder row in a survey or MDB entry requires a Google Drive URL when creating, editing, or correcting a returned item. Empty or invalid URLs prevent submission; entered dates, quantities, remarks, and links remain available for correction. Links must use HTTP or HTTPS and contain no more than 2,000 characters.

### Assign all feeders to one surveyor

In **Administration → Teams & Assignments**, create or choose an active survey team in the required project. Under **Assign member**, choose **Survey**, select the team by name and the surveyor's account, and mark **Survey team leader**. The account must already have the Survey Team Leader role. A team containing only that surveyor gives that person access to its feeders.

Under **Assign survey feeders**, select that team and **All active feeders in this project**, enter the assignment dates, and click **Assign feeders**. The action covers the project's current active feeders. Existing active assignments to the same team are skipped, so repeating it after another Excel import adds only newly available feeders. Other teams' assignments and earlier survey records are preserved. You can still choose **One feeder**. Assignments require an active team and project, and individual feeders must belong to that project.

On **Add Daily Survey**, users belonging to multiple teams select the intended **Survey team** and click **Load feeders** before entering quantities. Only their active memberships in active projects are available; a Super Admin may select any active team. Validation errors retain the selected team and entered form values. Each survey entry remains linked to one team.

## Roles

- **Super Admin:** all administration, master data, operational correction, dashboard, audit, and reports.
- **Project Manager:** global dashboard and reports.
- **Survey Team Leader:** assigned feeders, own submissions, returned corrections.
- **MDB User:** verify or return survey entries, create MDB entries, edit before review, and correct returned MDB items.
- **Third-Party Processor:** verify or return MDB items and view MDB review history; no survey/MDB creation or processing-progress entry.
- **Management Viewer:** read-only management dashboard and reports.

## Forgot password

Use **Forgot password?** on the sign-in page. Reset links are sent only to email addresses belonging to active accounts already in the `users` database table. Unregistered or inactive email addresses show an error and receive no reset email. Repeated requests within the account cooldown also show an error. A registered email address alone cannot reset the password: the owner must use the emailed token, which expires after 60 minutes and can be used once.

Successful resets rotate remembered-login credentials and remove the account's database sessions. Passwords are never included in the reset audit log. Requests are rate limited, and a new link for the same account cannot be generated more than once per minute.

Configure an outgoing mail service before expecting inbox delivery. `MAIL_MAILER=log` cannot deliver reset emails; the form reports that email delivery is unconfigured and does not generate a reset token. For SMTP, set `MAIL_MAILER=smtp` and the SMTP `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, and `MAIL_FROM_ADDRESS` values in `.env`. Keep credentials out of Git. Set `APP_URL` to the full URL where the application is accessible, including its subdirectory if applicable; reset emails deliberately use that configured address. Then run `php artisan config:clear`. Registered account emails must be real inbox addresses.

If the application opens under `/public/login`, its base URL includes `/public`. For the current HTTPS HAZECO deployment, use `APP_URL=https://hazeco.barqaab.pk/public`, run `php artisan optimize:clear` on the production server, and request a new reset email. A web-server 404 for `/reset-password/...` while `/public/forgot-password` works means the link is missing the application base path; it is not a password-token validation error. Reset links also preserve the current request's deployment path when `APP_URL` contains only an origin. An explicit path in `APP_URL` takes precedence and is not duplicated. The preferred permanent deployment still points the web-server document root to Laravel's `public` directory, allowing root-level URLs with a matching `APP_URL`.

## HAZECO HT workbook import

In Filament, go to **HT Data Imports → New HT Data Import** and upload an `.xlsx` file containing the `27_Feeders` worksheet. Matching uses `project + feeder code`; existing feeders update, new feeders insert, and bad rows report independently. Blank feeder codes receive stable `PENDING-{serial}` codes.

The same process is available from the command line:

```bash
php artisan hazeco:import-ht-data "C:/path/to/HT GIS Status Feederwise.xlsx" --user=admin@hazeco.test
```

Imports preserve source serial, feeder code/name, load, consumer count, grid, circle, division, subdivision, nature, file hash, timestamps, and row-level errors.

## Transformer KMZ import

Import the Excel feeder master first. Then open **Filament Administration → Transformer KMZ Imports**, choose **Bulk import KMZ files**, and add one row for every feeder file. For each row, select the exact existing Excel feeder and attach that feeder's `.kmz` file. The source feeder name inside the KMZ is kept separately for audit; the importer deliberately does not use fuzzy name matching.

Each file is validated in full before its feeder data changes. Only point placemarks whose `Equipment Type` is `Transformer` are imported; line sections, poles, substations, reclosers, and capacitors are excluded. Transformer number, capacity, coordinates, duplicate codes, coordinate-field agreement, one-source-feeder consistency, archive size, and KML structure are checked. A failed file changes no transformer or feeder baseline records. Other valid files in the same batch may still complete and have their own import history.

A successful re-import synchronizes that feeder to the current KMZ: existing transformer codes update, new codes insert, and codes no longer in the file are removed. The feeder's transformer baseline is updated to the validated count, but an import is rejected if that count is below survey or MDB quantities already recorded in the workflow. Use the import history to review source feeder/substation, file SHA-256, created/updated/removed counts, and any failure message.

Survey Team Leaders can open **Transformer GIS Data** for their actively assigned feeders. MDB users can use the same screen across imported feeders. It includes transformer/pole details, consumer counts, GPS waypoint, and a map link. Filament access remains controlled by the `Transformer` and `TransformerKmzImport` Shield permissions.

Validate a KMZ without changing MySQL before upload:

```bash
php artisan hazeco:inspect-kmz "/absolute/path/Feeder Name.kmz"
```

The command reports the source feeder/substation and counts for all placemarks, point placemarks, and validated transformers. Uploaded source files are stored on Laravel's private `local` disk under `storage/app/private/transformer-kmz-imports`; include that directory in file backups when import-source retention is required.

## Google Drive

Survey and MDB entries store validated HTTPS evidence URLs. Google Drive OAuth connection is available for signed-in active users at `/google-drive`; it does not change existing evidence links or upload files automatically.

Enable the Google Drive API in your Google Cloud project, configure the consent screen (add test users while in testing), and create an OAuth client of type **Web application**. Add this exact **Authorized redirect URI** in Google Cloud:

`https://hazeco.barqaab.pk/auth/google/callback`

Set these values in the production `.env`:

```dotenv
APP_URL=https://hazeco.barqaab.pk
GOOGLE_DRIVE_ENABLED=true
GOOGLE_DRIVE_CLIENT_ID=your-google-client-id
GOOGLE_DRIVE_CLIENT_SECRET=your-google-client-secret
GOOGLE_DRIVE_REDIRECT_URI=https://hazeco.barqaab.pk/auth/google/callback
```

Deploy the application with the subdomain document root pointing to `public`, then run `php artisan migrate --force`, `php artisan config:clear`, and `php artisan route:clear`. Sign in and open **Google Drive** to authorize your account. Authorization requests use `drive.file` scope and offline access. Tokens are encrypted with `APP_KEY` and excluded from user serialization; retain the production key across deployments. Disconnect removes the locally stored credentials; users can also revoke permission in their Google account. Existing arbitrary Drive links are not granted API access by this limited scope.

## Queue and scheduler

Database queue tables are included. No critical Phase 1 write depends on an asynchronous worker, but production should run one for future notifications/exports:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Run the Laravel scheduler every minute:

```cron
* * * * * cd /var/www/hazeco && php artisan schedule:run >> /dev/null 2>&1
```

Use Supervisor/systemd for the worker and restart it during deployments with `php artisan queue:restart`.

## Testing and quality checks

```bash
php artisan test
php artisan route:list
php artisan view:cache
vendor/bin/pint --test
```

Tests require in-memory SQLite and cover authentication/roles, Filament access, native XLSX imports, survey limits and corrections, MDB review/correction/resubmission, quantity reservations, immutable verified records, and dashboard/report calculations. Clear the configuration cache before testing. On Windows/XAMPP, if SQLite is not enabled in php.ini, run `php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit`.

## Apache / XAMPP setup

Point the virtual host document root to this project’s `public` directory, never the repository root. Enable `mod_rewrite` and allow `.htaccess` overrides. Ensure the PHP version used by Apache matches the deployment CLI.

Example development URL configuration:

```apache
<VirtualHost *:80>
    ServerName hazeco.test
    DocumentRoot "D:/xampp/htdocs/survey_progress_monitoring_system/public"
    <Directory "D:/xampp/htdocs/survey_progress_monitoring_system/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Production deployment

1. Provision PHP 8.2+, MySQL, HTTPS, a dedicated least-privilege database user, and a non-root application user.
2. Deploy to a release directory; make `storage` and `bootstrap/cache` writable by the web user.
3. Set production secrets in the environment. Use `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and the public HTTPS `APP_URL`.
4. Run `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` and `php artisan migrate --force`.
5. Run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`.
6. Point the web server to `/public`, start/restart queue workers, run health checks at `/up` and `/api/v1/health`, then shift traffic.
7. Keep application logs and MySQL audit/slow-query monitoring outside the web root.

Session and CSRF protections are enabled. Third-party isolation is enforced in queries/services, not only the navigation. Large Drive files are never proxied through PHP or stored in MySQL.

## Backup and recovery

- Take encrypted daily MySQL backups with at least 30 days retention and a weekly off-site copy.
- Back up `.env` secrets through the organization’s secrets system, not source control.
- Drive engineering folders need their own versioning/retention policy; database backups contain links, not engineering files.
- Test restoration quarterly into an isolated environment. Verify feeder baseline, transaction totals, users, and audit logs after each drill.
- Before a release that changes schema, take an on-demand backup and document rollback compatibility.

## Reports and PDF

The report screen exports standards-compliant CSV for Excel and supplies a print layout for the browser’s **Save as PDF** feature. This avoids server-side binary dependencies in the initial deployment. A queued native XLSX/PDF adapter can be added later behind the same report service.

## PWA and offline behavior

The manifest and service worker allow the browser shell to become installable where supported. Only CSS, the icon, and the offline explanation are cached. Authenticated project data and forms are deliberately not cached; offline data entry remains a future phase requiring conflict resolution and secure local storage.

## Future API / Flutter integration

`/api/v1` is reserved and currently exposes a health response. Domain rules live in services rather than Blade controllers. A Flutter phase should add Laravel Sanctum, API Form Requests/resources/controllers, and call the existing Survey, MDB, Processing, and Dashboard services. The normalized database requires no redesign.

Recommended future endpoints:

- `POST /api/v1/login`, `GET /api/v1/me`
- `GET /api/v1/feeders`
- `POST /api/v1/survey-entries`
- `POST /api/v1/mdb-entries`
- `GET /api/v1/processing-assignments`
- `POST /api/v1/processing-entries`
- `GET /api/v1/dashboard/summary`
