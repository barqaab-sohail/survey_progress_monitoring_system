# Managing MDB static values

Sign in as **Super Admin**, open **Engineering Configuration**, choose the project, and select **Edit static values by MDB table**. These settings apply to every MDB generated for that project.

1. Use **Find a table** to select the exact Access table name. Each table also has a plain-language title and purpose.
2. Expand the table. Each input shows a friendly label, its exact MDB column name, permitted values, and help. Select Yes/No or Open/Closed from the menus rather than guessing numeric flags.
3. Enter the approved values. Blank stores NULL only where permitted; zero is explicit. Fields marked **Filled automatically** cannot be edited.
4. Enter the reason for the change and use the existing save/approval controls. A draft remains unapproved. Full engineering mode requires approved configuration; Simple mode retains its existing confirmation workflow.
5. Reverify affected surveys, export fresh SynerGEE JSON, then upload that JSON to the local generator and generate the MDB again. An older JSON retains its frozen settings.

## Table guide

| MDB table | What the form controls | Filled automatically |
| --- | --- | --- |
| `InstMymSwDevPos` | Device code, switch position, first/last year indexes | Transformer `SectionId`, one `YearNumber` per selected year |
| `SAI_Control` | Model control values, product text, calendar-year labels | Metric units and R/Y/B labels are fixed to match the network generator |
| `InstSection` | Common section settings, role-specific conductors, first-pole/transformer lengths and consumer-cable formula | Section/feeder/node IDs, topology, survey conductors and measured spans |
| `InstMeterPoints` | Meter readings, calibration, description, ratings and flags | Transformer `SectionId` |
| `InstMymMeter` | Annual meter readings/calibration and year range | Transformer `SectionId` and `YearNumber` |
| `InstMeterDmd` | Monthly/day-type selection, power factor and hourly percentages | Meter `SectionId`, selected month/day combinations and expanded hourly columns |
| `Loads` | Spot-load flag, capacity factor, spot-load location code | Section IDs, customers and calculated demand |
| `InstMymLoads` | Annual-load year range | Section IDs, flags/capacity factor from Loads, and calculated annual demand |

Frequency, projection, line configuration, spacing, conductor height, supply/secondary conductors and default section load-connection code use the existing general controls. Their table entries link back to those controls. A populated general setting takes priority; when unresolved, the displayed table reference value is retained. The transformer link has a separate load-connection code under InstSection.

**Example:** For `InstMymSwDevPos`, DeviceCode `M`, Closed `(0)`, first year `0`, last year `10` produces 11 rows per transformer. SectionId comes from the final transformer section; it is never entered manually. Year indexes are 0–10: index 0 uses the base-year label in `SAI_Control`. The button there can fill the ten following calendar-year labels from a four-digit base year.

For `InstMeterDmd`, the main hourly level applies to all 24 hours. Optional individual-hour entries override that level. An empty hour inherits the level; `0` overrides it to zero. Select at least one month and day type. Capacity factor in Loads is a fraction (`0.75` = 75%); power factor in InstMeterDmd is a percentage (`95` = 95%).

For consumer cables, the length in metres is `(recorded pole height in metres − clearance in feet × 0.3048) / slope`, rounded to two decimals. The supplied reference uses 4 ft and 0.667. Changing these values does not alter the recorded survey height. Annual aggregate load rows retain the established year-zero rule; demand and customer counts are never entered as static values.

## Reusable library and local generation

The **Engineering defaults library** has the same table forms. Library edits apply to newly initialized project configurations. Existing projects keep their own values. General settings remain available under the advanced configuration disclosure.

Project settings are saved in the existing configuration JSON column; no database migration or environment variable is required. They are frozen once under `snapshot.configuration.settings.mdb_table_defaults` in approved JSON. The default profile is approximately **3.2 KB** of compact JSON. Labels/help and expanded per-transformer/per-hour rows are not copied into the transfer package. Local generation expands the profile into MDB records.

The first save preserves previously effective Loads values from advanced mapping. Subsequently, the table form controls those columns. Merely opening the page does not change saved settings or existing approvals.

Deploy the updated application code, table catalogue, views, public CSS/JavaScript and MDB scripts to both the server that exports JSON and each local installation that generates MDBs. Restart a running MDB worker after updating its scripts. Existing JSON without the profile uses the immutable version-1 reference values. A meter-point parent is generated for yearly meter rows even with an older v1 mapping, preserving the MDB relationship.

## Files used

- `resources/mdb/table-defaults-v1.json`: immutable original defaults, exact column names, labels, types, limits and help. Administrators edit project/library values through the page; do not edit this reference file to change live defaults. A future contract change needs a new version and backward compatibility.
- `app/Services/Mdb/MdbTableDefaults.php`: validates, normalizes and resolves compact profiles; keeps IDs and unknown tables/columns out.
- `resources/views/engineering/partials/table-defaults.blade.php`, `public/css/mdb-table-defaults.css`, `public/js/mdb-table-defaults.js`: table forms, responsive layout, selection, year counts and calendar-label helper.
- `EngineeringConfiguration.php`, `EngineeringDefaults.php` and `EngineeringConfigurationController.php`: existing project/library save, revision and audit workflow.
- `SnapshotService.php` and `SynergeeTransfer.php`: existing approved snapshot and JSON/local generation flow; no duplicate profile is added per transformer.
- `ExportPayloadBuilder.php` and the relevant `*TableGeneration.php`, `InstSectionGeneration.php`, `SectionGenerationRules.php`, `LoadsTableGeneration.php`, `InstMymLoadsGeneration.php`: consume the frozen profile and derive records.
- `scripts/mdb/export-reviewed-mdb.ps1` and `scripts/mdb/worker_server.py`: write/read back the resulting tables locally, including generated meter parents with v1 mappings.

## Verification

The change was checked with project/library save and revision tests, invalid-input checks, frozen JSON transfer, genuine Access MDB generation/readback, worker protocol tests, and desktop/mobile browser checks. Database structure remains at 51 tables and 42 relationships. The broader regression run still has 13 failures reproduced with the previous application classes: existing expectations for consumer rates, frequency and their validation differ from the current settings. Those unrelated expectations were retained.
