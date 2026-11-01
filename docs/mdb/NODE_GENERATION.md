# Node generation in the normal MDB export

The reviewed export now applies `NodeTableGeneration` after the existing topology, span, load and device calculations. It changes Node records and their identifier references together. It does not modify stored GPX/PDF observations, captured registry coordinates, section lengths, load calculations, device associations or the MDB schema. The older transformer builder and data-entry MDB exporter are unchanged.

## Schema and identifiers

Direct DAO inspection confirmed the same `Node` definition in the clean template, stored reference template and existing generated output:

| Order | Column | Type | Constraints |
| --- | --- | --- | --- |
| 1 | NodeId | Text(32) | Required; unique primary key; exporter rejects blank IDs |
| 2 | X | Double | Nullable |
| 3 | Y | Double | Nullable |
| 4 | Description | Text(100) | Required; zero-length string allowed |
| 5 | IsPadMountGear | Long Integer | Nullable; schema default 0 |

Existing schema metadata remains intact: 50 user tables and 41 relationships. NodeId is referenced by InstFeeders.FeederId, InstSection.FromNodeId/ToNodeId and InstSubstationTransformers.SubTranId. InstSection.FeederId references the renamed feeder identity too. The mapper substitutes these references without changing other fields or adding later-table business mappings.

For operator surveys with complete survey identities, NodeId is the verified date formatted as `jmy` followed by the exact three waypoint digits. Group digits remain in the application's stored survey identity and Description, but are excluded from the MDB NodeId as illustrated by the workbook. Examples: 13-Dec-2022 + waypoint `104` becomes `131222104`; 08-Oct-2026 + `001` becomes `81026001`. The first S's date discrepancy in the workbook was resolved by the user in favor of the PDF's 13-Dec-2022.

The feeder node uses the verified feeder name. The transformer terminal uses the first S's NodeId plus `.1`. Customer nodes use the associated E NodeId plus `.1` through the existing section's mapped consumer count. Identifiers are strings throughout; `.1` and `.10` are distinct. Case-insensitive ID collisions, inconsistent survey identities, invalid dates/counts and overlong IDs block export rather than merging or changing records. Historical networks without complete operator survey identities retain their existing mapping; no date or waypoint is invented for them.

## Coordinates and descriptions

This mapping requires the project CRS to be EPSG:32643, WGS 84 / UTM Zone 43N. A conflicting or missing CRS blocks this step rather than mixing Node coordinates with different control metadata. Existing approved coordinate corrections are respected.

- First E: its converted GPX X/Y.
- First S and feeder: first E X/Y minus 0.02 in each axis.
- Transformer terminal: first E X/Y minus 0.01 in each axis.
- Customer/meter Node suffixes: associated E X/Y plus 0.01 in each axis, shared by all its customers. Later workbook examples use their own E point in the same way.
- Other surveyed poles: their converted coordinates, without these placement offsets.

The user explicitly chose this first S placement in MDB output even when a registered transformer's coordinates were captured. The captured evidence remains unchanged and continues to determine the existing surveyed span calculations. These offsets affect drawing placement only.

Existing descriptions (survey identifiers and intentional source aliases) remain. Newly added rows with no existing description mapping receive an explicit empty string. No descriptions are invented from the blank target examples. Customer nodes are added to Node only; later customer-section/load improvements remain a separate step. Existing InstMeterPoints associations remain unchanged.

## Mounting evidence

The mapper uses the frozen, manually verified `header.mounting` and a confirmed header page linked to a ready source PDF with its SHA-256 and valid page count:

| Verified PDF arrangement | IsPadMountGear |
| --- | --- |
| Single Pole | 0 |
| Double Pole | 0 |
| Pad / Pad-mounted | 1 |

This value overrides a global Node default for the affected transformer. Missing, unverified or ambiguous mounting; absent/unreadable source metadata; unconfirmed header pages; and invalid page numbers produce a transformer-specific export error asking for the source to be verified. The normal ExportService already retrieves every approved original source and verifies its SHA-256 before requesting an export. The mapper uses this established PDF transcription/review flow, not OCR guesses or live registry lookups. Offline transfer continues to use frozen verified evidence.

## Verification evidence

Reference files inspected read-only:

- `C:/Users/sohai/OneDrive/Desktop/HAZECO/Node.xlsx`: Node and Application_created, including F/G comments. Its sheets represent different transformers. Application_created matches the 15 existing generated rows by ID/Description and coordinates within 5.13e-9 m.
- `C:/Users/sohai/OneDrive/Desktop/HAZECO/A081026.pdf`, page 1: first S 104, date 13-Dec-2022, first E 001, double-pole arrangement. The paired GPX waypoint 001 projects to the workbook X/Y within 0.0000003 m.
- `C:/Users/sohai/Downloads/H_081026_07.pdf`, header page 3: existing output transformer 11140223003, first E 027, double pole. The existing revision-96 snapshot identifies this source by SHA-256.

The target workbook stores suffixes numerically and omits `.10` in several examples. Its waypoint 003 shows only 13 customer rows despite a count of 14 in the PDF. The implementation follows the verified count and string suffix sequence, preserving `.10`.

Focused regression coverage in MdbNodeTableGenerationTest checks the first S/E, feeder, transformer, multiple customers, `.10`, Description preservation, all three mounting arrangements, missing/ambiguous PDF evidence, registry placement without source mutation, invalid CRS/identity, collisions and unchanged business fields in every later table. A native test invokes the same normal PowerShell writer for all three arrangements and verifies the output through ReadbackVerifier, including schema, relationships, every mapped value and template hash.

The surrounding projection, identity, registered-coordinate, export payload, offline and normal export workflow run completed 98 tests with 1,406 assertions: 96 passed and two existing schema tests requiring unavailable 64-bit DAO skipped. After tightening header-page evidence checks, all 18 final focused Node tests passed with 273 assertions. The new native regression successfully uses the installed 32-bit DAO engine for all three mounting arrangements. Pint and scoped diff whitespace checks pass.

A disposable real revision-96 export generated 89 Node records, 1 feeder, 14 sections, 1 transformer, 13 Loads rows and the existing single InstMeterPoints association. Native readback verified every mapped value, all references, the original schema/relationships and an unchanged template hash. The source JSON and frozen snapshot remained unchanged. These checks establish MDB generation consistency; native SynerGEE engineering analysis remains the application's separate acceptance step.

The export revision identifier is now `node-date-waypoint-placement-v1`, so new requests cannot reuse a cached output generated under the prior Node mapping. No migration, backfill or replacement of existing user exports is performed.
