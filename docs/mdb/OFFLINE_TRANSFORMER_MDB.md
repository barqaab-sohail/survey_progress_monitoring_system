# Export one transformer in production, convert to MDB locally

This workflow runs on Linux/cPanel without Windows, a remote MDB worker, a public IP, port forwarding or shared worker credentials. Only the local conversion step requires Windows and the existing Access DAO/ACE installation.

## Production

1. Open the approved survey and select **Export Data Entry MDB**.
2. In **Download Transformer JSON for local MDB conversion**, choose **Download Transformer JSON** beside the required transformer.
3. Transfer that `.json` file to your Windows computer.

Each file contains one transformer's original approved header, S/E observations, PV details and saved transformer metadata. Source batch, revision number, revision checksum and export time identify the source. Blank values, zero counts and string identifiers with leading zeros stay distinct. Export requires the existing batch export permission and current independent approval.

The versioned package includes a SHA-256 checksum to detect accidental edits or corruption. This checksum is not a digital signature or proof of origin. Conversion never imports the package's verification flags as local survey approvals. Handle these files as survey data and use files downloaded from your authorized production application.

## Local Windows application

1. Open `http://127.0.0.1:8000` and sign in with a user allowed to export MDBs.
2. Select **JSON to MDB** in the navigation, or open `/mdb-workflow/offline-mdb`.
3. Upload the `.json` file and select **Upload and preview**.
4. Check the transformer code, source revision, observation count and MDB format limitations.
5. Select **Generate and download MDB**.

The converter uses local Windows PowerShell and Access DAO/ACE directly. No local approved batch, production database connection, background worker or network connection to production is needed. Nothing is imported into local survey tables. The existing writer validates the reference MDB schema and reads back all mapped fields. The transfer preserves the complete original data; the legacy MDB still has the displayed mapping limitations, including no GPS coordinates and only one PV-detail slot per observation.

Invalid JSON, incompatible versions, checksum changes and incomplete S/E pairs are rejected. Maximum JSON size is 10 MB. If necessary, set local PHP `upload_max_filesize=10M` and `post_max_size=40M` and restart the local web server. The larger POST limit accommodates the preview form's encoded package. The converter is enabled by default only on Windows; `DATA_ENTRY_MDB_OFFLINE_ENABLED=false` disables it.

## Git deployment

Pull the updated commit in both the production and local application directories. In each application directory run:

```sh
git pull --ff-only origin main
php artisan route:clear
php artisan view:clear
php artisan config:cache
```

No database migration, new permission seeder or additional dependency is needed. Linux only downloads JSON; it does not execute PowerShell. Windows continues to use `DATA_ENTRY_MDB_POWERSHELL` when set, otherwise the existing automatic ACE-compatible PowerShell selection. Existing direct MDB downloads remain available.

Tests are in `DataEntryMdbExportTest`: single-transformer isolation, full JSON fidelity, unchanged source tables, stale/unauthorized downloads, malformed or modified packages, feature disabling and genuine local MDB conversion with no approved local survey. Run with an isolated SQLite configuration and uncached routes; the actual MDB test requires Windows Access DAO/ACE.
