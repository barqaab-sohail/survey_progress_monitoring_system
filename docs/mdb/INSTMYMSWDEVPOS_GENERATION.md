# InstMymSwDevPos generation and static values

By default, SynerGEE MDB creation writes 11 `InstMymSwDevPos` records for each selected transformer. This applies to the normal survey export and local SynerGEE JSON conversion, including individual-transformer and consolidated feeder exports.

The reference is `InstMymSwDevPos.xlsx`, worksheet `InstMymSwDevPos`, cells `A1:D12`, supplied from `C:\Users\sohai\OneDrive\Desktop\HAZECO`. Its SHA-256 is `fd4018950104809d5589ee0830dde01a5f222240930c103744b43246ccb0b2c6`. The workbook is a reference; it is not read or modified during generation.

| MDB column | Generated value | Implementation |
| --- | --- | --- |
| SectionId | The exact final transformer section ID | Read from `InstPrimaryTransformers.SectionId` after section generation/remapping; verify against `InstSection.SectionId`. |
| DeviceCode | Reference text `M` | Editable table profile: `values.DeviceCode`. |
| YearNumber | Reference indexes 0 through 10, inclusive | Editable `first_year` and `last_year` (0-10). |
| IsOpen | Reference integer `0` (Closed) | Editable table profile: `values.IsOpen`. |

The workbook's example SectionId is not copied. For example, when a transformer has final SectionId `00040711131222104.1`, all 11 records use that exact text, including its leading zeros and `.1` suffix. The years are 0, 1, 2, 3, 4, 5, 6, 7, 8, 9 and 10. With reference settings, every record has `DeviceCode = 'M'` and `IsOpen = 0`.

## Files and responsibilities

| File | Responsibility |
| --- | --- |
| `app/Services/Mdb/InstMymSwDevPosTableGeneration.php` | Consumes the frozen static values and year range, builds rows, and rejects missing, non-transformer or duplicate section references. |
| `app/Services/Mdb/MdbTableDefaults.php` | Validates and resolves project/library table profiles; supplies immutable defaults for older JSON. |
| `resources/mdb/table-defaults-v1.json` | Original reference defaults, field labels, types and help; live values are edited on Engineering Configuration. |
| `app/Services/Mdb/ExportPayloadBuilder.php` | Calls the generator after final transformer section IDs exist and adds `tables.InstMymSwDevPos` to the export payload. Its `EXPORT_REVISION` controls generation cache identity. |
| `scripts/mdb/export-reviewed-mdb.ps1` | Allows the table, writes its values through Access DAO, reopens the MDB, compares every field/count and verifies that each SectionId belongs to `InstPrimaryTransformers`. |
| `scripts/mdb/worker_server.py` | Allows this generated table when export runs through the HTTPS Windows worker. It also accepts the existing generated meter-history and meter-demand tables. |
| `app/Services/Mdb/ReadbackVerifier.php` | Checks the writer's exact payload/output hashes, table counts, schema fingerprint and verified-reference flags before an MDB is delivered. Its existing generic checks cover the new rows. |
| `resources/mdb/synergee-empty.mdb` | Existing clean MDB template. It already contains this table and its relationship. |
| `resources/mdb/approved-schema.json` | Existing approved four-column definition, composite primary key and relationship. No schema changes are required. |
| `tests/Unit/MdbInstMymSwDevPosGenerationTest.php` | Verifies the literal values, years, transformer links, export scope and real MDB write/readback. |
| `tests/Worker/test_mdb_worker.py` | Verifies the worker table allowlist and native transformer relationships. |

## How static values are implemented

A Super Admin edits **Engineering Configuration > Static values by table > InstMymSwDevPos**. The values are stored in the project's configuration and frozen once in approved JSON:

```json
{"version":1,"tables":{"InstMymSwDevPos":{"values":{"DeviceCode":"M","IsOpen":0},"first_year":0,"last_year":10}}}
```

The actual profile also contains the other configured tables. For each transformer, the generator uses the supplied profile:

```php
foreach (range($profile['first_year'], $profile['last_year']) as $year) {
    $rows[] = [
        'SectionId' => $id,
        'DeviceCode' => $profile['values']['DeviceCode'],
        'YearNumber' => $year,
        'IsOpen' => $profile['values']['IsOpen'],
    ];
}
```

`$id` comes from the final transformer record, including leading zeros and suffix. Static values are assigned explicitly; the writer does not rely on MDB field defaults. Older approved JSON without a profile uses the original immutable reference values.

## How to change a static value later

1. Sign in as Super Admin and choose the project in Engineering Configuration.
2. Select InstMymSwDevPos under **Static values by table**. Set device code, Open/Closed and first/last year indexes. The page displays the resulting record count; SectionId remains automatic.
3. Save with the existing reason and approval/confirmation controls, reverify affected surveys and export fresh JSON.
4. Upload the fresh JSON on the local computer and generate the MDB again. Existing JSON and existing MDBs retain their historical settings; editing the workbook does not alter them.

No PHP constant, environment-variable or cache-revision edit is needed for routine value changes. The configuration revision and frozen package distinguish the updated settings. Deploy the updated application and worker code once to enable this feature; see [Static table values](STATIC_TABLE_VALUES.md) for all eight tables, implementation files, library behavior and deployment details.

Adding rows to this existing table needs no Laravel database migration, MDB schema change or new user input. The approved MDB retains its existing 51 tables and 42 relationships. Its existing composite key is `(SectionId, DeviceCode, YearNumber)`; the existing relationship is `InstSection.SectionId -> InstMymSwDevPos.SectionId`. The application additionally restricts these generated rows to transformer sections.
