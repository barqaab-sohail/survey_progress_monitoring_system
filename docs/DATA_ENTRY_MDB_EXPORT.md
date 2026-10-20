# PDF Data Entry MDB export

This independent exporter produces the intermediate `Field_Data` / `GPS_Points` MDB format. It does not call the SynerGEE exporter, GPX processor, EX3 converter, generation-input validator or engineering configuration workflow. No migration or new MySQL records are required. The only changes to existing application files are two routes and an included export link in the PDF entry and survey review pages.

## Use and authorization

After survey verification, open **PDF Data Entry** or **Review saved survey**, then **Export Data Entry MDB**. The review page shows counts, mappings and any information the legacy schema cannot represent. **Download Data Entry MDB** generates one MDB for that batch, named `data_entry_{project-code}_batch-{id}_r{revision}.mdb`.

The module uses the existing batch-scoped `export` permission, visibility and assignment checks. Existing dedicated role restrictions are unchanged: super administrators and authorized MDB creators can export; granting this feature does not expand data-entry operator/verifier roles. Drafts, returned surveys, unapproved revisions, corrupt snapshots, unverified observations and incomplete/duplicate S/E pairs are rejected. Staged surveys require an independent approval decision for that revision. The approval is checked again after generation. A stale review page cannot download a replacement revision.

The exporter reads the approved immutable snapshot only; it neither reconstructs records from derived sections nor alters source records. Legacy snapshots with sections but without original PDF S/E observations are rejected rather than exported incompletely.

## Schema and writer

`resources/mdb/data-entry/empty.mdb` was made from a **copy** of the supplied A0808 file, deleting survey records and compacting the copy to remove deleted data. All three supplied MDBs have identical schema definitions. The clean template has:

- `Field_Data`: 69 fields; AutoNumber primary key `ID1`; nonunique `ID` and `Feeder_Code` indexes.
- `GPS_Points`: 7 fields; primary key `ID`; unique `GPS_Num` index.
- No user relationships or extra tables.

`schema.json` records the reference field order, names, types, sizes, attributes, required/zero-length flags, default and validation expressions, and index names/columns/primary/unique flags. The writer validates that contract before and after writing. The MDB is cloned, not rebuilt using guessed SQL types. Schema defaults remain identical, but missing observation values are explicitly NULL so defaults such as `11kV` cannot invent survey data.

Rows are ordered by transformer identity, explicit pair number, then S followed by E. `ID1` is a deterministic export-local sequential identity. Repeated physical waypoints are retained as distinct observations. Coordinates `x`/`y` are always NULL, `Section_ID` is NULL, `Sequence` is zero, and `GPS_Points` remains empty.

The DAO worker closes and reopens the output, checks the schema again, then compares **all 69 fields of every observation**, row counts, sequential identities, S/E ordering, transformer identity within each pair and the empty GPS table. PHP checks the returned row count and SHA-256. Failed outputs are removed. Successful files are private, sent as downloads and deleted after transmission; no existing MDB export-job records are created.

## Mapping and limitations

| MDB columns | Source / convention |
|---|---|
| `Substation`, `Feeder`, `Feeder_Code` | Frozen transformer header; feeder code must fit signed Long Integer |
| `Group`, `Survey_Date`, `S_E` | Group digits without leading zeros (`01` becomes `1`), date formatted DD/MM/YYYY, S/E designation |
| `DISCO`, `Line_Voltage`, `Pole_Use` | Export conventions set to `HAZECO`, `0.4kV` and `Secondary Distribution` on each observation |
| `GPS_WP` | Original waypoint reference as Double |
| `GPS_NO`, `Pole_Number` | Complete composite waypoint, numeric and textual respectively; compose from recorded group/date/GPS components for older rows where possible |
| `Con_Size_A/B/C/N` | R/Y/B/N; A/ANT → Ant, W/WASP → Wasp, GN → GNAT; other recorded labels retained |
| `Pole_Phase` | Connected recorded R/Y/B conductor positions, e.g. R-B; neutral remains separate |
| `Pole_height`, `Pole_Type` | Recorded height in feet and printed-form pole-class label; legacy metric heights convert to feet to four decimals with a report notice |
| `Equip_Type` | TR → Transformer, SP → Secondary Pole, Ang → Angle, WB → Wall Bracket; other recorded labels retained |
| `Equip_Number` | Transformer code on each observation |
| `Equip_Size`, transformer make/location/mounting, `Equip_Use` | Capacity/header values on first S observation; later size uses reference convention `0`, other header fields NULL |
| `RS_Con1`, `RS_Con3` | RS and RL counts respectively, independently for each endpoint; no aggregation; blank counts become `0` |
| `SC_Con`, `LC_Con`, `SI_Con`, `LI_Con`, `PB_Con`, `AG_Con`, `ST_Con`, `PV_Con` | Corresponding original row counts; blank counts become `0` |
| `PV_Consumer_REF_NO`, `PV_Size`, `AMI_Meter_No`, `Remarks` | A single recorded PV detail's reference, integral capacity, meter and remarks |

The export review explicitly reports these limitations:

- Multiple PV details cannot fit one scalar PV slot. Their individual details are omitted and the original PV count retained; the exporter never duplicates the pole observation or arbitrarily selects one PV consumer.
- Transformer-level PV records without an original S/E observation link are reported and omitted because assigning them to a pole would invent an association.
- Fractional/out-of-range PV capacities and nonnumeric/out-of-range feeder codes are omitted without rounding. Text exceeding the exact target column capacity is omitted without truncation. The affected row and column are reported.
- `service_load_kw` is not assumed to mean sanctioned load. `Sanction Load` remains NULL; its omission is reported for affected observations.
- `RS_Con` has no confirmed application equivalent. LC is retained without reclassifying it.
- An intersection boolean does not establish Angle/Tap_Pole/End_Type values. Affected observations are reported.
- Numeric GPS identifiers lose leading zeros by the reference format's design. The full textual composite identifier remains in `Pole_Number`. Feeder codes are also numeric in this schema.
- PDF/page/row provenance, internal pair IDs, verification evidence, copy history, inspector/client notes, separate division/subdivision names and other application-only metadata have no compatible columns. They remain in the approved snapshot, without adding fields or tables to the MDB.
- Feeder-on-pole, equipment phase/status/unit, administrative codes and picture paths remain NULL. The explicit HAZECO, 0.4kV and Secondary Distribution values are Data Entry MDB export conventions; they do not change the approved survey snapshot or SynerGEE generation.

The exporter reproduces the reference **schema and applicable data conventions**, not the original application's unavailable internal algorithms or historical row IDs. Import behavior in the third-party application still needs its own acceptance check.

## Runtime and tests

Requires Windows with Access DAO/ACE. The independent configuration auto-selects the compatible PowerShell architecture using the existing read-only runtime detector. Optional overrides: `DATA_ENTRY_MDB_POWERSHELL`, `DATA_ENTRY_MDB_TIMEOUT` (default 180 seconds). `MDB_EXPORT_DRIVER` and existing SynerGEE settings do not control this exporter. Refresh Laravel configuration and route caches when deploying.

The feature tests use SQLite `:memory:` exclusively; the application MySQL database must never be used for tests. On this XAMPP installation, PDO SQLite needs enabling for the test process and existing production caches need bypassing through temporary `APP_CONFIG_CACHE` / `APP_ROUTES_CACHE` paths.

The real DAO compatibility suite is `tests/Worker/test_data_entry_mdb.py`. Set `DATA_ENTRY_MDB_REFERENCE_DIR` to the supplied sample directory to run the three-reference comparison. It checks schema equality, all reference observation values (404 rows total, with export-local IDs), source-file hashes, clean template counts, Unicode/blank/NULL preservation and rejection of invalid pairs, coordinates and oversized text. The reference MDB files remain unchanged.

## Validation results — 19 October 2026

| Check | Result |
|---|---|
| New export feature tests | 8 passed, 46 assertions, including actual DAO download and no application-record writes |
| Python worker suite, including all three supplied references | 14 passed |
| Reference schema and record round trip | All 404 observations retained; schema equality for all three files; original file hashes unchanged |
| Full PHP suite with this module | 414 tests; 32 failures, 2 skipped, 1 risky |
| Unchanged HEAD baseline in an isolated source copy | 406 tests; the same 32 failures, 2 skipped, 1 risky |
| JavaScript suite, current and unchanged baseline | Both 24 passed / 4 failed; identical failing tests |
| PHP formatting and diff whitespace | Passed |
| Cached route registration | Both new GET/POST routes present |

The full PHP comparison introduced no new failing test identities. Existing failures span login redirects, some survey/entry/workflow expectations, team assignment and simple-mode validation. The existing risky test is `MdbWorkflowTest::test_automatic_drive_storage_keeps_sources_processable_and_private_after_local_cleanup`. The four existing JavaScript failures concern row validation, draft height units, header recovery and operator height-unit confirmation. These unrelated modules were not changed to make their tests pass.

The initial full PHP run exceeded XAMPP's default 128 MB test-process memory limit. The completed comparison used 512 MB for both source versions, with process-local PDO SQLite extensions and SQLite `:memory:`. Application configuration and route caches were refreshed after implementation; `.env` and business records were not changed.

A read-only runtime preflight found the Access worker configured correctly and no currently approved batches in the connected application database. Real-user record downloads therefore await normal completion and verification; the tested HTTP download used an isolated approved fixture and the real DAO writer. No third-party application import test was performed.
