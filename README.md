# HAZECO T&D Losses Progress Monitoring System

Responsive Laravel web application for monitoring the HAZECO baseline through field survey, verification, MDB creation, assignment, and MDB processing/analysis.

The central principle is **minimum data entry, maximum management visibility**. Engineering files remain in Google Drive; this system stores quantities, responsibility, dates, workflow state, and secure links.

## What is included

- Secure session authentication, active-account checks, login throttling, six roles, and Filament Shield permissions
- Organizations with strict third-party assignment isolation
- Project → Circle → Division → Sub-Division → Grid Station → Feeder master hierarchy
- Native HAZECO XLSX import with imported/updated/rejected counts, row errors, and repeatable updates
- Survey teams, MDB teams, processing teams, memberships, and feeder assignments
- Multi-feeder daily survey entry, row-level verification, return, correction, and resubmission history
- MDB creation limited to verified survey capacity
- Internal/third-party processing assignments limited to unreserved created MDB capacity
- Processing progress limited by both assignment quantity and created MDB quantity
- Management and third-party dashboards, KPIs, backlog formulas, feeder status, daily trend, today/week/month totals
- Audit log, database notifications for returned surveys, CSV/Excel-compatible export, and print/PDF report output
- Mobile-first operational forms, responsive analytical views, and safe PWA shell readiness
- Filament control centre for users, roles, organizations, hierarchy, feeders, and import history
- Critical workflow, authorization, and XLSX-import tests

The complete architecture, ERD, table catalog, permission matrix, calculations, screen map, and delivery sequence are in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Runtime requirements

Production target:

- PHP 8.3 or newer with Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, and XML
- MySQL 8.0+ or MariaDB 10.6+
- Composer 2.7+
- Apache 2.4 or Nginx

This repository currently uses Laravel 12 because the supplied XAMPP runtime is PHP 8.2.12. Laravel 13 is the current major and requires PHP 8.3. Upgrade PHP first, run the full suite, then move `laravel/framework` to `^13.0` as the initial deployment gate. No domain design depends on Laravel 12-specific behavior.

Node is not required at runtime: the Phase 1 interface ships a small, dependency-free CSS/JavaScript shell. The Laravel Vite scaffold remains available for future asset expansion.

## Local installation

```bash
composer install
copy .env.example .env
php artisan key:generate
```

For quick evaluation without MySQL, change `DB_CONNECTION=sqlite`, remove the other `DB_*` values, and create the local file:

```bash
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate:fresh --seed
php artisan serve
```

Open `http://127.0.0.1:8000`.

### MySQL configuration

Create an empty utf8mb4 database, then update `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hazeco_td_losses
DB_USERNAME=hazeco_app
DB_PASSWORD=use-a-strong-secret
```

Then run:

```bash
php artisan migrate --force
php artisan db:seed --force
```

Do not run the development seeder in a live environment after real data exists.

## Development users

All seeded accounts use `Password123!` locally. Change or remove them before deployment.

| Role | Email |
|---|---|
| Super Admin | `admin@hazeco.test` |
| Project Manager | `manager@hazeco.test` |
| Survey Team Leader | `survey@hazeco.test` |
| MDB Team User | `mdb@hazeco.test` |
| Internal Processing User | `processor@hazeco.test` |
| Third-Party Processing User | `thirdparty@hazeco.test` |
| Management Viewer | `viewer@hazeco.test` |

## Filament control centre

Open `http://127.0.0.1:8000/admin` and sign in with an authorized active account. The seeded Super Admin can:

- create and update users, organizations, projects, and the full feeder hierarchy;
- add or update HT feeder records and verified transformer baselines;
- upload the native HAZECO feeder workbook under **HT Data Imports**;
- manage granular resource permissions under **Shield → Roles**.

The legacy administration screens remain at `/legacy-admin` during the transition. Filament is the primary backend.

The supplied `HT GIS Status Feederwise.xlsx` has been loaded into MySQL: 153 feeder rows, 2 circles, 7 divisions, 34 subdivisions, and 66 grid stations. Three source feeder codes were blank, so their stable internal codes are marked `PENDING-*`. The workbook contains load and consumer totals but no transformer baseline; imported feeders therefore start at `total_transformers = 0` with `baseline_pending = true` until an authorized user enters the verified baseline.

## Management decision dashboard

The application landing page now opens with a management-first visual summary:

- recommended survey or MDB resource action based on backlog and the latest seven-day output rate;
- survey completed/pending and verification-pending quantities;
- MDB files created versus verified surveys waiting for MDB creation;
- estimated days to clear each backlog;
- circle-level priorities and highest-backlog feeders;
- a 7/14/30-day production trend and period totals.

Eighteen imported feeders currently contain clearly flagged sample baselines and sample survey/MDB transactions for demonstration. A blue notice remains visible while sample data is active. Manage it with:

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
5. Project Manager or Super Admin assigns unreserved created MDB work to an internal or third-party organization.
6. Processing users record progress only against their organization’s assignments.
7. Management dashboard totals and backlogs are derived directly from transaction rows.

Critical writes use database transactions and row locks. Concurrent requests therefore cannot oversurvey a baseline, create MDB beyond verified capacity, double-assign created MDB, or over-process an assignment.

## Roles

- **Super Admin:** all administration, master data, operational correction, dashboard, audit, and reports.
- **Project Manager:** global dashboard/reports and processing assignment.
- **Survey Team Leader:** assigned feeders, own submissions, returned corrections.
- **MDB Team User:** pending verification and MDB creation.
- **MDB Processing User:** only its organization’s assignments and processing history.
- **Management Viewer:** read-only management dashboard and reports.

## HAZECO HT workbook import

In Filament, go to **HT Data Imports → New HT Data Import** and upload an `.xlsx` file containing the `27_Feeders` worksheet. Matching uses `project + feeder code`; existing feeders update, new feeders insert, and bad rows report independently. Blank feeder codes receive stable `PENDING-{serial}` codes.

The same process is available from the command line:

```bash
php artisan hazeco:import-ht-data "C:/path/to/HT GIS Status Feederwise.xlsx" --user=admin@hazeco.test
```

Imports preserve source serial, feeder code/name, load, consumer count, grid, circle, division, subdivision, nature, file hash, timestamps, and row-level errors.

## Google Drive

Phase 1 stores validated HTTPS URLs only. Configure survey, MDB, and processing folder URLs at feeder level so daily users rarely paste a link. Optional per-entry URLs override the feeder default.

Placeholders are available in `.env`:

```dotenv
GOOGLE_DRIVE_ENABLED=false
GOOGLE_DRIVE_CLIENT_ID=
GOOGLE_DRIVE_CLIENT_SECRET=
```

No Drive API credentials are required until direct folder/API integration is implemented.

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

Tests use in-memory SQLite and cover authentication/roles, Filament access, native XLSX imports and idempotent updates, survey limits, return/resubmission, verification, MDB capacity, assignment reservation, processing limits, organization isolation, and dashboard/backlog calculations.

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

1. Provision PHP 8.3+, MySQL, HTTPS, a dedicated least-privilege database user, and a non-root application user.
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
