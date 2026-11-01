# InstFeeders step: implementation and verification

Status: the two field mappings and independent checks are implemented. Completion remains pending the source spelling decision below. No user survey data or source files were edited.

## Inspection

Workbook: C:/Users/sohai/OneDrive/Desktop/HAZECO/Tables/InstFeeders.xlsx, sheet InstFeeders, A1:AN2. All 40 column names match the actual MDB's order exactly. The example is Nawa Shehr / 132 KVA Abotabad; these are not hard-coded defaults. The workbook's ByPhVoltLevelPh1/2/3 examples are 100, while the existing real export uses 11; those fields remain 11 as requested.

Read-only DAO inspection of the clean template and existing normal export confirms FeederId and SubstationId are required Text(32), with zero-length strings permitted by the schema but rejected for essential IDs by the normal writer. FeederId is the unique primary key and references Node.NodeId. InstFeeders has 40 columns. The complete MDB still has 50 user tables and 41 relationships; no schema/default/index/relationship changes were made.

The normal controller path is MdbWorkflowController::export -> ExportService::request -> ExportPayloadBuilder::build -> GenerateMdbExport / ExportService::run -> WindowsExporter or the configured HTTP worker -> scripts/mdb/export-reviewed-mdb.ps1. Feeder rows previously merged configured table values and per-network overrides; simple mode fell back to header.substation_identifier when SubstationId was empty. NodeTableGeneration already remaps FeederId and its dependent references together after step 1. The source header's substation_name is editable in the existing PDF review workflow and is retained in frozen revisions. No controller, UI or source-review logic was changed.

## Changes

- app/Services/Mdb/ExportPayloadBuilder.php: explicitly takes FeederId from the existing mapped source Node; takes SubstationId from the reviewed PDF header's substation_name verbatim, overriding codes/configured defaults/network overrides for that field only. Empty/missing or overlong names report the affected transformer without truncation or fallback. The internal export revision changed to prevent reuse of an old cached MDB; application configuration is unchanged.
- tests/Unit/MdbExportPayloadTest.php: checks all 40 feeder fields, exact name/case/punctuation preservation, precedence over codes/defaults, full and simple modes, source/settings immutability, valid dependent references, and rejection of missing/overlong names. Existing fixtures/expectations now supply the required name.
- tests/Unit/MdbNodeTableGenerationTest.php: adds the name assertion alongside the existing modern Node identity check.
- tests/Feature/SimpleMdbExportWorkflowTest.php: native normal-export expectation now uses Survey substation rather than code G1.
- tests/Feature/MdbExportWorkflowTest.php: ensures old Node-step exports are not reused after this mapping change.

Existing changes from step 1 remain intact. No other production mapping files were changed in step 2.

## Verification

- Regression run: 76 tests and 1,231 assertions passed, including native normal MDB exports. The subsequently added cache regression also passed (1 test, 4 assertions).
- Pint and scoped diff whitespace checks passed.
- A disposable normal export was generated from the unchanged real revision-96 JSON, using the normal payload builder and native PowerShell writer. Source PDF/GPX SHA-256 checks matched the frozen source records. The source JSON and snapshot remained unchanged.
- Exact physical MDB comparison against the preserved step-1 output found only one changed value: InstFeeders.SubstationId, from 407 to 132kV Abbottabad (the currently saved header name). FeederId remains NAWAN SHEHR and exactly matches the relevant Node. All other 39 InstFeeders values, including FeederId, were identical.
- Every other table's rows were identical, including all Node records and dependent section references. All field definitions, indexes and all 41 relationships were identical. The native writer and ReadbackVerifier verified all mapped values and found no unmatched references.
- Output retains 89 Node rows, one feeder, 14 sections, one transformer, 13 Loads rows and the existing InstMeterPoints association. No later-table mappings or settings changed.
- Follow-up current-state audit found the newer `synergee_11140223003_batch-17_r101.json` and its existing normal MDB. A disposable regeneration from that unchanged revision also passed native readback. Exact comparison with the actual revision-101 MDB again found only `SubstationId` changed from `407` to `132kV Abbottabad`; all other 39 feeder values, every Node record, every other table's rows, schemas, indexes and relationships were identical. The PDF/GPX hashes still match. Evidence is retained in r101-readback.json and r101-comparison.json.

## Unresolved source spelling

The actual source PDF H_081026_07.pdf, page 3, was rendered and visually inspected for transformer 11140223003. It reads **132 KVA Abbottabad**. The saved, manually verified header and original header both contain **132kV Abbottabad**, and the old exported SubstationId was **407**. The workbook's example **132 KVA Abotabad** is different again and is not an authoritative replacement for this record.

The source header therefore needs a user decision before this record's SubstationId can be claimed to match the PDF exactly: either require a correction through the existing PDF review workflow, or explicitly accept the saved verified header spelling. No source-name correction, normalization, per-record hard-coded override, or user-data update has been assumed. A clarification is pending.

The disposable output proves the field mapping and preservation checks, but does not establish literal PDF spelling for this conflicting record. The task is not marked complete while that requirement remains unresolved.

The newer revision-101 header still contains the same substation spelling conflict. No answer to the clarification has arrived. Completion audit: workbook/schema match, relevant Node ID/reference consistency, all other-field/settings preservation and normal native MDB generation are proven; literal PDF-name agreement remains contradicted by the source and saved header and requires user resolution.
