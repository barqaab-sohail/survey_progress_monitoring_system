# Reference MDB review and device mapping v2

Ten user-supplied MEPCO models were inspected on 13 October 2026 using read-only Access DAO. Source SHA-256 hashes were checked before and after inspection. The [machine-readable comparison](reference-comparison.json) records each filename, hash, populated table count, schema comparison, phase codes and observed equipment references. Raw rows and round-trip test MDBs are kept locally in ignored `tmp/mdb-reference-review/`.

## Findings

All ten have the same 50 user tables, field definitions, indexes and 41 relationships as `resources/mdb/synergee-empty.mdb`. Together they contain 180 sections, 170 loads and 200 nodes. Every model has one `InstMeterPoints` row. In these rows, only `SectionId` is populated; blank readings must stay NULL.

Alipur City 2T-11301214359 also has two `InstDGens` rows and two `InstMymDGens` rows. A generator is unique per section; annual outputs use the composite key `(SectionId, YearNumber)`. The sample schema does **not** enforce a relationship from annual generator outputs to generator instances, so the writer now checks this link explicitly.

Eight positional phase codes are observed: `RYBN`, `RYB`, `R  N`, ` Y N`, `  BN`, `RY N`, `R BN`, and ` YBN`. The existing fixed R/Y/B/N slot mapping is retained.

All references contain `Frequency = 60`, metric units, SynerGEE Electric 5.0.0.324 and WGS 1984 UTM Zone 43N. These are observations, not approved current-project settings. Exact conductor strings include `ANT`, `DOG`, `GNAT`, `WASP`, leading-space variants and a single-space value. Transformer references include `25 KVA`, `50 KVA`, `100 KVA` and `200 KVA`. The referenced equipment library is still needed to verify these identifiers and electrical parameters. The schema match alone does not establish those parameters or engineering correctness.

## Using v2

In the existing project configuration JSON, select `mapping.version = "synergee-reviewed-v2"`, retain the six reviewed v1 table mappings, and add these explicit dictionaries to `mapping.table_values`:

```json
{"InstMeterPoints": {}, "InstDGens": {}, "InstMymDGens": {}}
```

Add device lists inside the appropriate `mapping.networks[transformer_code]`. This synthetic example illustrates the shape only; select actual survey IDs and approve the values for the current project:

```json
{
  "meter_points": [
    {"source_section": "supply", "fields": {}}
  ],
  "generators": [
    {
      "pv_record_id": 8,
      "fields": {"DGenOutputPct": 100, "DGenVoltSet": 120},
      "years": [
        {
          "YearNumber": 0,
          "CapacityFactor": 1,
          "Phase1Kw": 1,
          "Phase2Kw": 2,
          "Phase3Kw": 3,
          "Phase1Kvar": 0,
          "Phase2Kvar": 0,
          "Phase3Kvar": 0
        }
      ]
    }
  ]
}
```

A meter identifies either a `source_section` key from that network or a `survey_section_id`, never both. Its field dictionary can be empty to deliberately create the blank meter record observed in the references. Measurements and demand modes, when needed, must be entered explicitly.

A generator identifies a frozen PV record in that transformer. The PV record must have positive installed capacity and be linked to a surveyed section in the same transformer. Installed capacity is **not** automatically divided across phases or converted into output. Each connected phase requires explicit finite kW and kvar values for every modeled year. Inactive phases accept NULL or explicit zero; signed kvar is preserved. Output percentage must be 0–100, voltage setting positive, and yearly capacity factor 0–1. Base year 0 is required; year numbers must be unique nonnegative integers. The engineering reviewer must check their meaning against the model's configured year labels and generator controls.

One generator per section is supported by the schema. Multiple PV observations on one section require a separately designed and reviewed aggregation rule; the application rejects duplicate mappings rather than summing them. Every positive PV record must be mapped or covered by the existing reviewer-approved `pv_exclusion`. An exclusion applies to unmapped records; mapped generators are still exported.

`SectionId` is always resolved from the current frozen network and cannot be copied into device field dictionaries. Duplicate meters, foreign PV IDs, foreign sections, duplicate years, missing phase outputs and disconnected-phase outputs fail validation. Any omitted MDB column remains NULL. All nine tables are required in a v2 payload, even when a device list is empty. v1 retains its six-table contract and rejects v2 device requests instead of dropping them.

Save and review the configuration through the existing workflow, then capture and approve a fresh survey revision before generation. Existing approvals and project settings were not changed by this code update. Restart long-running Laravel queue workers and any standalone Python MDB worker after deploying the updated code. The writer and HTTP worker must both support v2.

## Repeatable inspection

Use the PowerShell architecture matching the installed Access engine. On this workstation it is 32-bit:

```powershell
& "$env:WINDIR\SysWOW64\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File scripts/mdb/inspect-reference.ps1 -InputPath 'W:\path\reference.mdb' -OutputPath 'tmp/mdb-reference-review/reference.json'
python scripts/mdb/compare-references.py --template tmp/mdb-reference-review/template.json --output tmp/mdb-reference-review/comparison.json tmp/mdb-reference-review/reference.json
```

Inspect the template with the same script to obtain `template.json`. The inspector reads table data only, rejects linked tables, and does not execute saved queries, macros or modules. The comparison report preserves significant whitespace in equipment IDs and distinguishes observed values from approved settings.

## Verification

All ten reference models were independently reconstructed into copies of the clean template using the v2 native writer. Every reconstructed model passed full field readback, row counts, schema/relationship comparison and reference checks, including all ten meter records and both generator/year pairs. These are compatibility fixtures, not new current-project models.

Additional automated tests cover v1 compatibility, v2 device mapping, invalid section/PV references, phase allocation, duplicate years, HTTP table contracts, multiple-year readback and rejection of orphaned yearly outputs. See `tests/Unit/MdbSupplementalMappingTest.php`, `tests/Unit/MdbExportPayloadTest.php` and `tests/Worker/test_mdb_worker.py`.

Validation on this workstation passed 136 MDB-related PHP tests (1,550 assertions), six HTTP/native Access worker tests, two reference-comparison tests, and PHP formatting checks. The PHP test command enables the installed SQLite extensions for its isolated in-memory database:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter Mdb --no-progress
$env:PYTHONDONTWRITEBYTECODE = '1'
python tests/Worker/test_mdb_worker.py
python tests/Worker/test_mdb_reference_comparison.py
```

Access readback confirms the file contents. Opening the resulting current-project model in SynerGEE and checking connectivity/load flow remains a separate engineering acceptance step.
