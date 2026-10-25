# Production JSON to local SynerGEE MDB

## What was established before implementation

The working data-entry path is `TransformerTransfer` → `OfflineMdbController` → `DataEntryMdbExport`/`export-data-entry.ps1`. It exports original manually verified PDF observations and writes the separate legacy `Field_Data`/`GPS_Points` schema. It is unchanged by this feature.

PDF entry is stored in `mdb_workflow_*` batches, transformers, entry rows, network sections, consumers, PV records, sources and waypoints. Independent verification freezes a `Revision.snapshot` with a canonical SHA-256 and binds approval to it. The snapshot also contains the applicable engineering configuration, equipment references, template identity, source provenance and approved coordinate corrections. Existing `NetworkValidator`, `ExportPayloadBuilder`, `WindowsExporter` and `ReadbackVerifier` already implement the engineering mapping and DAO writer. The offline path reuses them without importing records or approvals into the Windows database.

### Read-only reference inspection, 24 October 2026

Originals in `One Data for A0808` were read through DAO without running saved queries/macros. Hashes were checked before and after inspection; no source file changed. Raw inspections remain in ignored `tmp/synergee-offline-reference/` and are not bundled with the application.

| Reference | SHA-256 | Findings |
| --- | --- | --- |
| approved_data_entry.mdb | `f04b81ceeda206608908ce7766e3742e403b275b39a5656df2f2cc94aba1bf47` | 2 tables, no relationships; `Field_Data`: 69 fields, 58 rows; `GPS_Points`: 7 fields, empty. All observations identify Kot Madina / 132 kv Khanewal / T-8160421145. |
| survey.gpx | `ed0055525e5a7aaa3fa66dc7266e9b1169099dbb48183dfea6b56d17cea9dbb0` | GPX 1.1; 62 waypoints named `001`–`062`, WGS84 lat/lon, metre elevations and UTC timestamps. These are names, not electrical connections. |
| valid_synergee.mdb | `1065f140ba2c964c1fb30d4e0e73aa581db03913b5e0ca90f3b1bae5c731bf3a` | 50 user tables, 41 relationships; the existing approved-schema DAO assertion passed. Populated: Node 33, InstSection 31, Loads 30, and one each of InstFeeders, InstPrimaryTransformers, SAI_Control, InstMeterPoints, InstDGens, InstMymDGens. |

The two MDBs identify the same feeder/substation/transformer. GPX timestamps correspond to the 8 August 2025 observations, but the first data-entry observation is from 16 April 2021 and refers to waypoint `145`, absent from this GPX. For PDF-entered S/E rows, the requested first-pair rule below now supplies the first S coordinate from its explicitly paired first E observation. No waypoint is selected by GPX file order or copied from a reference MDB.

The target has genuine node/section/feeder/transformer/load relationships and optional meter/generator tables. It identifies `SynerGEE Electric 5.0.0.324`, metric units and WGS 1984 UTM Zone 43N. Its 60 Hz setting, 4.16 transformer voltage setting, 1 m supply connectors, phase conductor strings (including whitespace), equipment names, demand allocation and PV phase values describe that model. They are not copied into a different survey or installed as project defaults. The file has no equipment-library tables establishing external conductor/transformer physical parameters.

## Production export

1. Complete PDF entry and independent verification. Engineering settings and the clean template must be approved for the selected project; engineering changes require survey reapproval.
2. Open **Review** or **MDB generation details**. Under **Generate SynerGEE MDB on Windows**, choose one transformer or the whole feeder and click **Download SynerGEE JSON**. Whole-feeder generation additionally requires a reviewed consolidated mapping.
3. Retain the JSON as downloaded. The old **Download Transformer JSON** action remains the data-entry format and is not accepted by this converter.

The new export is `hazeco-synergee-transfer`, version 1. It carries the complete approved snapshot, batch/project/feeder identity, explicit output scope, revision/hash, source GPX hashes, verified WGS84 coordinates/elevations, section endpoints/geometry, engineering settings, original entry observations/PV and the exact approved clean MDB template as Base64. Including the template removes local template registration or manual copying. The full revision remains present for integrity checking; only the chosen transformer(s) are mapped. This means the JSON may also contain other transformer observations from that same authorized batch. Export a separate batch if those observations must not be transferred together.

Source paths inside the frozen snapshot are provenance only. The local converter never opens paths named by JSON, downloads a URL from it or executes file content. Export checks current approval, independent verifier, current/frozen revision hashes and original source integrity, and records the export in the audit log. It does not create a Windows job or require PowerShell/Python on production merely to package the data. The JSON is limited to 30 MB; the clean template to 15 MB. Templates listing external `required_base_files` are blocked explicitly because this transfer does not package those dependencies.

## Local Windows setup and use

Update the same repository on the Windows computer. Keep the existing working PHP/Laravel local installation and local authorized user account. Required components:

- Windows Access DAO/ACE with matching PowerShell architecture. This computer uses 32-bit `C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe`; automatic selection is already implemented in `AccessPowerShell`.
- Python with `pyproj`, used by the existing coordinate projection helper (`python -m pip install -r scripts/mdb/requirements.txt` if setting up a new machine).
- PHP upload limits sufficient for the package: `upload_max_filesize=30M`, `post_max_size=64M`, `memory_limit=256M` or higher. Restart the local PHP server after changes.

If auto-detection needs an explicit path, configure the local Laravel `.env`:

```dotenv
MDB_EXPORT_POWERSHELL=C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe
MDB_PROJECTION_PYTHON=python
MDB_EXPORT_TIMEOUT=180
SYNERGEE_OFFLINE_ENABLED=true
```

No public IP, router forwarding, worker secret, production database connection or running MDB worker is required.

```powershell
cd E:\xampp\htdocs\survey_progress_monitoring_system
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan serve --host=127.0.0.1 --port=8000
```

Sign in locally, choose **JSON to SynerGEE**, or open `http://127.0.0.1:8000/mdb-workflow/offline-synergee`.

1. Select the production SynerGEE JSON. Optionally select an original matching GPX to verify its SHA-256, waypoint names, coordinates and elevation against the frozen source. No GPX selection is needed when embedded verified coordinates are complete. An arbitrary GPX cannot fill missing coordinates or alter approved values locally.
2. Select **Check survey and engineering inputs**. The preview lists all network validation issues, with transformer/section/PDF context when available. Resolve errors in production, obtain renewed verification and re-export. The local page does not guess or approve settings.
3. When validation passes, review table counts and notes, then select **Generate and download SynerGEE MDB**. Download revalidates the package and rebuilds the mapping; it does not trust a previous preview. It copies the embedded clean template, writes explicit mapped values and verifies every mapped value, table counts, schema, relationships, model references and all output hashes before delivery. Temporary template/payload files are removed. No local survey/revision/template records are created.
4. Open the MDB in the matching SynerGEE installation with the reviewed equipment libraries and perform connectivity/load-flow checks. Record engineering acceptance through the existing analysis workflow as applicable. Local downloads do not automatically create a production ExportJob or acceptance record.

## GPX and engineering mapping

### First S/E coordinate pair of each transformer

The first explicitly entered pair (lowest pair number within that transformer) uses its E row's unique GPX match for **both S and E latitude, longitude and recorded elevation**. S keeps its original handwritten reference, group and survey date; it does not need a KMZ coordinate or its own GPX match. A similarly named GPX waypoint cannot replace this rule. A missing or invalid first E, an ambiguous GPX match, or a missing first E row blocks verification/generation with a specific error. A later E row is never substituted.

This applies in the production snapshot/JSON, network validation and local/server SynerGEE mapper. The two observations retain separate logical node IDs at the same coordinates, so the first pair can have a zero calculated span without becoming a self-loop or dropping a record. All remaining pairs retain their own GPX matches and coordinates. GPX source records are never overwritten. The preview explains this coordinate rule; the separate data-entry MDB retains its existing original-observation schema and coordinate policy.

After updating production and the local Windows application, reopen/resave the survey as needed, obtain approval of the current revision and download a fresh JSON. Existing approved snapshots remain immutable; a change in derived mapping requires renewed approval for production exports.

Files changed for the first-pair coordinate rule:

| Area | Files |
| --- | --- |
| Survey mapping and snapshot | `app/Services/Mdb/SurveyWaypoint.php`, `app/Services/Mdb/EntryService.php`, `app/Services/Mdb/SnapshotService.php` |
| Validation and SynerGEE output | `app/Services/Mdb/NetworkValidator.php`, `app/Services/Mdb/ExportPayloadBuilder.php`, `app/Services/Mdb/SynergeeTransfer.php` |
| Entry and review | `app/Http/Controllers/MdbOperatorEntryController.php`, `app/Services/Mdb/ReviewService.php`, `public/js/mdb-entry.js`, `resources/views/mdb-workflow/show.blade.php`, `resources/views/mdb-workflow/review.blade.php` |
| Regression coverage | `tests/Unit/MdbSurveyIdentityTest.php`, `tests/Unit/MdbExportPayloadTest.php`, `tests/Feature/MdbOperatorEntryTest.php`, `tests/Feature/SynergeeOfflineTest.php`, `tests/JavaScript/mdb-entry.test.mjs` |
| Instructions | `docs/mdb/OFFLINE_SYNERGEE.md` |

Checks: 81 focused PHP tests (582 assertions) and 4 targeted JavaScript tests pass, including JSON import, equal first-pair coordinates with distinct node IDs, independent transformers, later-pair preservation, invalid/missing/ambiguous first E, and the unchanged data-entry MDB path. The broader pre-existing suites have separate header/fixture/UI failures; the unrelated failing PHP cases were also reproduced against the prior implementation.

| Frozen input | Output |
| --- | --- |
| Approved projection, frequency and product mapping | `SAI_Control`; values must match the chosen template/product. |
| Explicit surveyed endpoints, source aliases and approved coordinates | `Node`, `InstFeeders`, `InstSection`; generated stable identifiers preserve actual graph references. FeederId references a real source node. |
| Verified section phases/conductors, geometry and approved settings | Fixed R/Y/B/N phase slots, separate conductor columns, configuration and metre lengths in `InstSection`. |
| Transformer code/capacity with approved equipment/connection/impedance mapping | `InstPrimaryTransformers`; no transformer type is inferred from a sample of the same capacity. |
| Separately verified consumer counts and approved phase demand | `Loads`; no kW/kvar/kVA or phase split is derived from counts without the approved method frozen in production. |
| Reviewed v2 device mappings | `InstMeterPoints`, `InstDGens`, `InstMymDGens`; missing generator mapping blocks positive PV, subject to an explicit reviewed exclusion. |
| Original PDF entries, sources, dates, hashes and validation notes | Preserved in JSON/provenance; they do not create extra tables in the fixed SynerGEE schema. |

Waypoint names, selected GPX source and survey/date identities resolve explicit surveyed S/E endpoints through the existing `SurveyWaypoint` rules; GPX order never defines edges. WGS84 coordinates project into `Node.X/Y` using the frozen approved metre-based EPSG. The target Node schema has no elevation column. Elevation contributes to section lengths using the existing sum of `sqrt(horizontal_distance² + elevation_difference²)` over route segments. Missing elevation produces a clearly reported horizontal fallback; it is not set to zero. Approved measured lengths take priority. An explicitly configured sag allowance uses the existing versioned calculation. No sample length is reused.

Validation follows the generation mode frozen in the approved survey revision. Simple mode allows missing engineering settings and equipment references as warnings and may use the active system schema template without engineering approval. Survey verification, source integrity, coordinate validity and connected network checks remain mandatory. Full mode retains its engineering approval and electrical mapping requirements. Changing the project mode requires renewed survey approval and a fresh JSON export.

The transfer checksum and frozen revision hash detect corruption; they are not digital signatures proving who issued a manually modified package. Use JSON received through the authorized production download. The application cannot establish that external SynerGEE libraries are installed or certify an engineering model without opening it in that software. The reference example's missing historical waypoint and project-specific engineering choices remain unresolved; this feature does not manufacture them.

## Git deployment and checks

No database migration is added. After updating production code:

```sh
git pull --ff-only origin main
php artisan route:clear
php artisan view:clear
php artisan config:cache
```

`SynergeeOfflineTest` covers authorized/frozen production export without Windows, an independent frozen-integrity safeguard, denied/stale approvals, package corruption, wrong GPX, missing engineering/PV inputs, transformer isolation, Simple mode warnings and mandatory survey checks, local preview and genuine DAO generation/readback without a local approved survey. `DataEntryMdbExportTest` remains the regression check for the existing workflow. Tests require SQLite `:memory:` with isolated configuration and route-cache paths. The fixture engineering approvals and values are synthetic and never become production defaults.
