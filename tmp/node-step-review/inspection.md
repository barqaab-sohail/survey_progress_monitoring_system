# First MDB table: inspection and pending decisions

Status: this records the pre-edit inspection. The user subsequently resolved all three decisions: update the normal export and dependent references together; apply first E minus 0.02 to the first S in MDB output even for registered records; and use the verified 13-Dec-2022 date. Implementation and verification are complete; see docs/mdb/NODE_GENERATION.md. Source workbooks/PDFs, source MDBs, schemas and user records remain unchanged.

## Confirmed target and schema

The actual table is `Node`, not `Nodes`. Direct read-only DAO inspection of `resources/mdb/synergee-empty.mdb`, the stored reference-schema template, and a disposable copy of the existing generated output confirms this column order:

| Order | Column | Access type | Required | Details |
| --- | --- | --- | --- | --- |
| 1 | NodeId | Text(32), DAO 10 | Yes | Unique primary key; schema permits empty string, exporter requires nonblank identifier |
| 2 | X | Double, DAO 7, 8 bytes | No | No default or validation expression |
| 3 | Y | Double, DAO 7, 8 bytes | No | No default or validation expression |
| 4 | Description | Text(100), DAO 10 | Yes | Allows empty string; default empty string |
| 5 | IsPadMountGear | Long Integer, DAO 4, 4 bytes | No | Default 0; no validation expression |

NodeId has enforced relationships to InstFeeders.FeederId, InstSubstationTransformers.SubTranId, and InstSection.FromNodeId/ToNodeId. The inspected MDBs contain 50 user tables and 41 relationships. No schema change is needed.

## Current implementation

The matching reviewed export path is ExportService::request -> ExportPayloadBuilder::build -> GenerateMdbExport / ExportService::run -> configured exporter. WindowsExporter and the HTTP worker use scripts/mdb/export-reviewed-mdb.ps1. That script copies a clean template, inserts through the existing field definitions, and checks every mapped value, schema, relationships, counts, and foreign references after reopening.

ExportPayloadBuilder currently makes NodeId as a stable hash of batch and waypoint/source identity. X/Y come from ProjectionService and the configured EPSG. Logical transformer terminals share their source coordinates. SurveyWaypoint overlays complete survey identities without changing stored GPX records. Description intentionally records the survey identifier/short waypoint name or logical source alias. IsPadMountGear is inherited from configured table values rather than mapped from each transformer's PDF mounting field. The inspected generated rows contain 0.

There is a separate older TransformerMdbNetworkService / TransformerMdbWriter path, using TX-HV, TX-LV and date:waypoint identities, plus a separate data-entry export. Neither matches Application_created. Those paths should remain outside this change unless specifically requested.

## Workbook and actual output comparison

Reference: C:/Users/sohai/OneDrive/Desktop/HAZECO/Node.xlsx, sheets Node and Application_created. Workbook comments were read as reference material within the user's request, not independent instructions.

Application_created has 15 rows for transformer 11140223003, first E waypoint 027. A disposable copy of outputs/three-pair-mdb-analysis/generated-r91-updated-export.mdb has exactly the same 15 NodeIds and descriptions. Maximum coordinate difference is 5.122274160385132e-9 metres, consistent with workbook serialization precision.

The target Node sheet instead illustrates transformer T-11131222104, first E waypoint 001. The two sheets represent different survey records and cannot be reconciled row-for-row as an expected/actual fixture for one transformer.

The supplied Waypoints_2026-10-08.gpx has waypoint 001 at latitude 34.163890, longitude 73.257410. Independent pyproj EPSG:32643 conversion gives X=339376.0816263687, Y=3781699.944180856. This agrees with Node!B5:C5 within 0.0000003 metres. The first S/feeder use E minus 0.02, transformer uses E minus 0.01, and first meter/customer positions use E plus 0.01 in both axes. These are diagram placement offsets, independent of projection or surveyed span length.

Preserve existing Description mappings. For new customer/feeder rows without an existing description mapping, the schema supports an explicit empty string. The workbook's blank examples do not require clearing intentional existing descriptions.

The target customer IDs are stored numerically in the workbook. For example waypoint 003 has 14 customers in the PDF but only 13 customer rows in the workbook: .1, .11, .12, .13, .14, .2 through .9. The .10 identifier is absent. Generated identifiers must be strings so .1 and .10 remain distinct; counts should come from verified source records rather than reproducing this omission.

## Source PDFs located

C:/Users/sohai/OneDrive/Desktop/HAZECO/A081026.pdf has 14 pages. Visual inspection of page 1 confirms the target example's transformer T-11131222104, first S waypoint 104 and first E 001. D.Pole is checked, corresponding to IsPadMountGear=0. Its first S handwritten date is 13-Dec-2022, consistent with the transformer's identifier and existing survey-identity fixtures.

The matching current-output source is H_081026_07.pdf. It was located through existing project analysis pointing to the exported transformer snapshot, then its header page 3 was visually inspected at C:/Users/sohai/Downloads/H_081026_07.pdf. The revision-96 snapshot identifies source PDF 33, SHA-256 1efe69c13c6d89d7f168c4e514cf57fda8140409f789bda2a5f28f99c6346c1d, header page 3 and continuation page 4. The header marks double pole and the frozen header says Double Pole. No missing source PDF blocks these two examples. Single-pole and pad-mounted source examples have not been established by this inspection; these mappings still require focused synthetic regression coverage and source provenance validation when implemented.

## Decisions requested before implementation

1. **First-step delivery boundary:** a Node-only replacement inside the normal complete MDB would leave InstFeeders and InstSection pointing to old hashed NodeIds. Choose a separate Node-only review MDB, or permit dependent identifier references to be remapped in the normal export. Other table business mappings must remain unchanged. A separate Node-only MDB can retain the schema with the other network tables empty; it is an intermediate review artifact.
2. **Captured registry coordinates:** StartingPoleCoordinates and SurveyWaypoint deliberately preserve frozen registry coordinates for some registered first S points. Decide whether this behavior remains, with E-0.02 limited to the existing first-E fallback, or whether the new Node placement rule overrides it in MDB output. Stored observations and span-length rules must not be rewritten as a side effect.
3. **First S date conflict:** the PDF and existing survey-identity fixtures indicate 13-Dec-2022, which would yield 131222104 under date-plus-waypoint formatting. Node!A2 is 31222104, and F2 describes its date as 31222. Choose the verified record date or an explicit exception reproducing the workbook value. No date correction has been assumed.

For generated IDs, waypoint digits and decimal suffixes must remain exact strings; group/date/waypoint survey identities in stored application records must remain intact. Consolidated exports also need explicit collision detection when dropping group prefixes or using one feeder name at different coordinates; no silent merging or suffix invention is appropriate.

## Checks performed

- Read-only DAO schema inspection: template and stored reference Node fields match. Source SHA-256 remained unchanged.
- Read-only DAO inspection of a disposable existing output copy: Node schema and all 15 workbook current-output identities/descriptions agree.
- Independent pyproj conversion of supplied GPX waypoint 001: agrees with target workbook values within 0.0000003 m.
- Visual inspection: target PDF page 1 and matching current-output PDF page 3 establish double-pole mounting and different records.
- Baseline PHPUnit run with isolated configuration/route caches and SQLite :memory:: MdbProjectionTest, MdbExportPayloadTest, MdbSurveyIdentityTest, MdbStartingPoleCoordinatesTest. Result: **44 tests, 883 assertions, all passed**. PHP required both pdo_sqlite and sqlite3 extensions for this run; the initial run without pdo_sqlite could not execute model-backed tests.
- No new regression tests have been added and no new behavior is claimed verified. First S/E, transformer, meter/customer generation, and all three mounting cases still require implementation tests and disposable MDB write/readback after the decisions above.

Inspection files, extracted metadata, preview images, disposable MDB copy, and baseline JUnit results are contained in tmp/node-step-review. The desktop BarqaabLt_28Oct2026_145428.mdb could not be inspected because another process holds it; it was left untouched. Available template/output evidence was sufficient to confirm Node's schema.
