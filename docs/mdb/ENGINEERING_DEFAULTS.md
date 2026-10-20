# Reusable engineering defaults

The Engineering Configuration page now links to **Engineering defaults library** (`/admin/engineering/defaults-library`). Access uses the existing Super Admin role and `mdb-workflow.configure` authorization. Administrators can edit the default configuration, disable automatic loading, review original source evidence and save a new audited library revision. Concurrent stale edits are rejected.

## Source scope and evidence

The seed manifest, `resources/mdb/engineering-defaults.json`, consolidates nine distinct engineering models: the seven Allah Abad/Kot Madina/Mosa Pak/New Bosan/Z Town files supplied on 19 October 2026 plus both previously supplied Industrial files. Mosa Pak is counted once. Intermediate Data Entry MDBs use a different schema and are not engineering-library sources.

Every source was read using Access DAO with read-only mode. All nine match the canonical approved schema: 50 user tables, 1,065 fields, 133 indexes and 41 relationships. Source hashes remained unchanged. Full inspections and the consolidation program are in `output/engineering-defaults-review/`.

There are **133 consistent nonblank, non-instance parameters** and **eight reported conflicts**. Common observations include:

| Field | Recorded value |
|---|---|
| Frequency | 60 Hz |
| Nominal supply voltage | 11 kV |
| Projection name | WGS 1984 UTM Zone 43N |
| EPSG | 32643, derived from that label; verify against GPS/control points |
| Projection WKT | Blank |
| Configuration reference | `12.5/7.2 kV cross arm C2-2` |
| Phase / neutral spacing | Raw 121.9 / 91.4; application fields label these cm, but source only declares Metric globally |
| Average conductor height | 9.1 under the recorded Metric convention |
| Transformer connection references | YG / YG |
| Instance impedance switch | 0; stored zero impedance fields are not physical library specifications |
| Recorded nominal-voltage mode/value | NomKvMode=C / SpecNomKv=4.16; actual LT secondary voltage unresolved |
| Transformer type references | `50 KVA`, `100 KVA`, `200 KVA` |
| Conductor references | ANT, DOG, GNAT, WASP, INSULATED ANT and leading-space variants |

The source models contain equipment IDs, not the external conductor/transformer library definitions or installed revisions. Those revisions, actual LT voltage, transformer capacity-to-library approval, survey date/unit policy and final-generation load policies remain unresolved. No library revision or electrical specification is invented.

The eight conflicts cover role-dependent LoadConnectionPhaseGnd, unequal observed kVA/customer ratios, unusable power-factor evidence, whitespace variants in four conductor families and a missing secondary-link neutral reference. There are 350 positive-customer phase records: 341 show approximately 3 kVA/customer, with exceptions around 3.8667, 4 and 5. Every positive-kVA phase has zero kW and kvar, so this is not a usable category-rate or power-factor standard.

Kot MadinaT-8160421145 includes one generator and annual-generator row. Component-only constants and historical source-role profiles are retained as evidence, rather than being applied as unconditional defaults. Specific generator output, coordinates, measured demands, original network IDs and historical model-year labels are not shared defaults.

## Applying and overriding values

1. Creating a PDF/GPX MDB workflow or a new transformer MDB workspace initializes a project configuration only when that project has none and the library is enabled. The project row lock serializes initialization with normal configuration saves.
2. New configurations are **unapproved drafts**. Existing full engineering approval, equipment/template checks and SynerGEE schema validation remain in force. Simple Generation remains a project-controlled setting; the library cannot change it.
3. Opening Engineering Configuration for a project with no configuration shows a read-only preview of the library defaults. GET requests do not create records. Saving a project draft or approval retains the copied source evidence and library revision.
4. Any existing project configuration is kept exactly as stored, including custom values, deliberately blank fields, simple-mode settings and approvals. There is no automatic fill/merge/reset of existing configurations.
5. Updating the library affects future initializations only. Previously copied project settings retain their version. Update those through their existing project configuration form when desired; normal configuration-change approval invalidation continues to apply.
6. Rerunning the dedicated seeder does not overwrite administrator edits, revisions or the disabled state.

The library editor accepts `epsg`, `settings`, `load_assumptions` and `equipment_references`. Known values can be edited and unresolved values kept `null`. Shared mapping tables and fields are validated against the existing canonical schema, including value types and text sizes. Unsupported tables/fields, coordinates, per-network wiring and injected review/approval flags are rejected. Editing reference names in the defaults does not rewrite the original extraction evidence or approve an installed library.

This adds one application table, `mdb_engineering_presets`; no existing MySQL table definitions or reference MDB schemas are altered. The final exporter continues to consume the existing project configuration and approved frozen snapshot. Data Entry MDB export defaults remain independent.

## Installation

Run migrations before serving the updated code. On an existing installation, apply only the additive migration and dedicated seeder:

```powershell
php artisan migrate --path=database/migrations/2026_10_19_200000_create_mdb_engineering_presets_table.php --force
php artisan db:seed --class=EngineeringDefaultsSeeder --force
php artisan route:cache
```

Do not run the application's full demonstration DatabaseSeeder against a live installation. The standard DatabaseSeeder includes EngineeringDefaultsSeeder for fresh installations. Runtime initialization reads the database library; it does not need the original MDB files or Access runtime.

## Verification

All tests run with SQLite `:memory:` and temporary configuration/route cache paths, never the application database. Focused library/import/configuration tests passed: **34 tests, 259 assertions**. Relevant database-seeder, transformer-builder, full/simple export, generation-settings and payload regression tests passed: **59 tests, 509 assertions**. Total: **93 tests, 768 assertions**.

Coverage includes idempotent seeding; disabled defaults; preserved custom project settings; no writes on GET preview; automatic copying during a real upload workflow; project override persistence; future-only library edits; immutable extraction evidence; Admin authorization; optimistic locking; schema/type validation; rejection of per-network data and approval bypass; and existing configuration/export behavior. Canonical schema checks and SHA-256 verification passed for all nine original MDBs.
