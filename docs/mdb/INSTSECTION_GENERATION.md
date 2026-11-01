# InstSection generation in the normal export

This is the third table step in the existing reviewed MDB export. It runs after Node generation, uses actual Node/InstFeeders identities, and does not create an alternate export workflow. The source workbook is `C:/Users/sohai/OneDrive/Desktop/HAZECO/Tables/InstSection.xlsx`: row 2 rules, row 6 headers, and row 7 onward examples. The pasted request and subsequent user clarifications govern conflicts with the examples.

## Changes and boundaries

- `app/Services/Mdb/InstSectionGeneration.php` rebuilds the workbook chain and section roles, maps conductor/configuration fields, adds connection sections, remaps dependent section identifiers, and checks references, connectivity and cycles.
- `app/Services/Mdb/SectionGenerationRules.php` formats string-safe identifiers and calculates consumer/PV cable lengths from explicit pole-height units.
- `app/Services/Mdb/ConsumerPhaseConnections.php` is the one phase/endpoint plan shared by Node and section generation. It honors recorded phase counts and expands three-phase consumers/PV without inventing available phases.
- `app/Services/Mdb/NodeTableGeneration.php` consumes that plan to add only required endpoints. Existing physical consumer suffixes, Node descriptions and coordinates remain unchanged; extra phase/PV suffixes follow the pre-existing physical consumer sequence. New endpoint descriptions remain blank and all endpoints use their pole's established X/Y + 0.01 placement.
- `app/Services/Mdb/ExportPayloadBuilder.php` integrates the steps and passes actual source-section identities. Its export revision is `instsection-workbook-v1`, preventing reuse of earlier table-step MDBs.
- `tests/Unit/MdbInstSectionGenerationTest.php` and `tests/Unit/MdbSectionGenerationRulesTest.php` cover the new rules, including native writes. Node/offline fixtures and cache regressions were updated for the confirmed inputs/behavior in `MdbNodeTableGenerationTest`, `SynergeeOfflineTest` and `MdbExportWorkflowTest`.

No migrations, source-data edits, electrical demand changes, new Loads/device records, or unrelated table-generation steps are included. Existing later-table business values remain unchanged; only their dependent section identifiers move. This includes optional transformer incident-section pointers when they actually reference a remapped section. Existing frozen networks without complete operator identities retain the compatibility behavior established in the Node step; no historical identifiers or dates are invented.

## Identifiers and chain

SectionId is text throughout: a numeric feeder code left-padded to six digits, the applicable row's two group digits, a six-digit DDMMYY date, and the exact three waypoint digits. Suffixes are concatenated as text; `.1` and `.10` remain distinct. There are no separator hyphens: the workbook records and existing reference fixtures use concatenated IDs, and the note's hyphens show component grouping. Codes longer than six digits or invalid dates/identities are reported rather than truncated.

The confirmed first-S example uses 13-Dec-2022 and NodeId `131222104`, not the obsolete `31222104`. Each real record retains its own verified date and group; another transformer's historical S date is not replaced with this example's date.

The chain is feeder Node -> first S -> transformer terminal -> first E, followed by the actual survey S/E pole wiring and separate leaf sections for each consumer/PV phase connection. Existing source/survey SectionIds are remapped to the appropriate new role. InstPrimaryTransformers stays on the transformer section; the existing secondary meter association stays on the first-E pole section. Loads and generator associations retain their business data. Multiple existing zero/aggregate Loads rows may refer to the same pole section, as the existing schema permits; demand is not redistributed in this step.

InstPrimaryTransformers.SectionId explicitly reuses the actual InstSection record selected by the existing network's `transformer_section` relationship. No separate device SectionId is generated. The mapping applies independently to each transformer, including consolidated exports and historical networks. It validates the transformer identity and rejects missing or unrelated section references. Other primary-transformer fields/settings and the existing optional incident-section reference handling remain unchanged. Regression checks compare every non-SectionId field against the preceding mapping, cover two distinct transformers, and write/read back a disposable consolidated MDB.

## Phases and endpoints

The MDB/application uses fixed R/Y/B/N slots: RN is stored as `R  N`, YN as ` Y N`, BN as `  BN`. Available phases come from the verified survey. Unknown conductor availability, a missing neutral, or a recorded allocation on an unavailable phase blocks the affected record.

The existing application's single-phase categories are RS, SC and ST. Other known consumer categories retain the existing three-phase classification. Recorded single-phase counts take priority. Only unallocated single-phase customers rotate R, Y, B (or the available subset), in successive groups. Every three-phase consumer gets one RN, one YN and one BN connection, each with its own Node. PV installations use the same expansion when R/Y/B are available. For a verified Y-and-neutral-only location, the user explicitly requires three separate YN PV connections instead: each retains its distinct endpoint Node, and the PV sections leave the R/B conductor fields NULL. Missing R/B conductor entries do not block this case. Y and neutral still require source evidence; other partial-phase cases retain their existing validation. Source observations are not rewritten.

Confirmed examples:

| Source observations | RN | YN | BN | Endpoint Nodes / sections |
| --- | --- | --- | --- | --- |
| Three single-phase customers recorded on RN + one three-phase consumer + one PV | 5 | 2 | 2 | 9 |
| Eight unallocated single-phase customers + one three-phase consumer + one PV | 5 | 5 | 4 | 14 |
| Eight single-phase customers recorded on YN + one PV at a Y/neutral-only location | 0 | 11 | 0 | 11 |

Both generators use the same deterministic endpoint suffix plan. An existing physical three-phase consumer's canonical suffix is retained for its first phase; its other phase suffixes are appended after existing physical consumers. PV phase endpoints then extend that same sequence. This preserves every existing NodeId and placement while providing one distinct leaf Node for every generated connection section. Conflicting PV counts/details/linked records and inconsistent consumer totals block export.

## Lengths and static values

The user explicitly selected workbook values over conflicting configuration values for the fields the workbook specifies. The feeder, transformer and first-E pole lengths are NULL, 0 and 10 respectively. The first pole's original surveyed span remains in provenance, while its model length is marked `workbook_static`.

Consumer/PV cable length is `round(((height_in_feet - 4) * 0.3048) / 0.667, 2)`, using SQL decimal-style half-up rounding for the nonnegative result. An explicitly recorded metre height is converted equivalently. Height comes from the associated E row, not the preceding S row's section height. A 36 ft pole gives 14.62 m; 31 ft gives 12.34 m. Missing/invalid units or a height below the four-foot clearance are reported.

Later pole spans retain the existing projected 3D polyline method, recorded elevation, explicit sag allowance and approved measured-length handling. Missing elevation is reported rather than silently accepted as a horizontal span. Node drawing offsets do not alter surveyed spans.

Feeder conductors are 500 MCM; transformer conductors are 4/C 07/.052; connection conductors are PVC 07/0.052. Pole conductors remain the actual PDF mapping: A -> ANT, GN -> GNAT, W -> WASP, etc.; ANT is never used as an unrecorded fallback. Labels belong in Description; PositionCode stays integer 0. ExpFactRepair is 0 as requested. Other populated workbook configuration fields are applied to the described roles, while fields the workbook leaves unspecified retain existing mapped values. Application settings and schema defaults themselves are not edited.

## Verification and source review

The exact 59-column order, types, constraints, indexes and defaults were inspected through DAO and match the workbook headers. The output retains all 51 approved template tables and all 42 relationships, including the user-requested InstMeterDmd extension. The schema/mapping table below records every column in order. SectionId is a unique primary key. FromNodeId and ToNodeId reference Node.NodeId. FeederId is nullable in the MDB schema, but normal export requires an actual matching feeder.

The latest regression suite passed **119 tests with 1,778 assertions**. The focused InstSection run passed **15 tests with 322 assertions**, including native single-phase, mixed three-phase/PV, Y-only PV and consolidated two-transformer writes. Primary-transformer checks cover reuse of each corresponding transformer section, preservation of every other field, and rejection of missing/unrelated references or a mismatched transformer identity. The Y-only fixture checks three distinct YN endpoints, NULL R/B PV conductor fields, retained Y/neutral cables and placement, unchanged source input, and validation of missing active Y/neutral conductors. Native readback verifies mapped values, schema and relationships on disposable MDBs. Pint and syntax checks passed.

A disposable full normal export from the unchanged revision-101 source produced **92 Nodes and 91 InstSection rows**. Exact physical MDB comparison preserved all **89 existing Nodes** and added only `81026028.1`, `.2` and `.3` for the PV at E 028, using the established placement. Its **77 consumer/PV connection sections have 77 distinct endpoint Nodes**. The writer reopened the MDB and verified every mapped value, all references, the original schema/relationships and unchanged template hash. InstFeeders and unrelated table rows are unchanged; Loads, transformer and meter business values are unchanged apart from necessary section-reference substitution. The original PDF, GPX, snapshot and user MDB files were not overwritten. Native SynerGEE engineering analysis remains the existing separate acceptance step.

Source review items are deliberately not guessed or backfilled:

- The user's earlier decision requires the substation header for transformer 11140223003 to match `132 KVA Abbottabad` in H_081026_07.pdf page 3. Revision 101 still stores `132kV Abbottabad`; that existing header requires correction/reverification through the normal review workflow. It was left unchanged during this section-only step.
- A081026.pdf page 2, E 014, shows a PV entry on a branch with Y and neutral only. The user resolved this case: generate three YN PV rows with three distinct Nodes, without requiring R/B conductors or changing the source data. The focused regression includes omitted R/B entries and a native MDB write/readback of this case.

Verification artifacts are retained under `tmp/instsection-step-review/`, including native schema inspection, readback, physical comparison and JUnit reports. They are disposable verification data, not an alternate application export workflow.

## Exact column order and population

| # | Column | MDB type | Required | Population |
| --- | --- | --- | --- | --- |
| 1 | SectionId | Text(32) | Yes | Six-digit feeder code + own row group + DDMMYY + waypoint; text suffix for transformer/connection. |
| 2 | FeederId | Text(32) | No | Exact identifier already stored in the relevant Node and InstFeeders record. |
| 3 | FromNodeId | Text(32) | Yes | Actual generated Node in the feeder/transformer/pole/connection chain. |
| 4 | ToNodeId | Text(32) | Yes | Actual generated Node; consumer/PV endpoints come from the shared phase plan. |
| 5 | SectionPhases | Text(5) | No | Verified available phases; recorded allocations first, then unallocated rotation. Fixed R/Y/B/N slots. |
| 6 | Description | Text(100) | No | Feeder / Transformer / Secondary Pole, according to workbook role. |
| 7 | PhaseConductorId | Text(32) | No | Feeder 500 MCM; transformer 4/C 07/.052; pole PDF R conductor; connection PVC 07/0.052; NULL for Y-only PV. |
| 8 | NeutralConductorId | Text(32) | No | Feeder NULL; transformer 4/C 07/.052; pole PDF N conductor; connection PVC 07/0.052. |
| 9 | PhaseToPhaseSpacing_MUL | Double | No | `121.9` (workbook) |
| 10 | PhaseToNeutralSpacing_MUL | Double | No | `91.4` (workbook) |
| 11 | AveHeightAboveGround_MUL | Double | No | `9.1` (workbook) |
| 12 | PercentDistLoadConstImpedance | Double | No | `40` (workbook) |
| 13 | PercentDistLoadConstCurrent | Double | No | `10` (workbook) |
| 14 | PercentSpotLoadConstImpedance | Double | No | `40` (workbook) |
| 15 | PercentSpotLoadConstCurrent | Double | No | `30` (workbook) |
| 16 | SectionLength_MUL | Double | No | Feeder NULL; transformer 0; first pole 10; later pole existing 3D span; connection E-height cable formula. |
| 17 | PercentLoadGrowthRate | Double | No | `2` (workbook) |
| 18 | MappingLink | Long Integer | No | `0` (workbook) |
| 19 | LoadConnectionPhaseGnd | Long Integer | No | Transformer 0; feeder, poles and consumer/PV connections 1. |
| 20 | UseEquivSpacing | Long Integer | No | `0` (workbook) |
| 21 | IdenticalPhaseConductors | Long Integer | No | `0` (workbook) |
| 22 | PhaseConductor2Id | Text(32) | No | As PhaseConductorId, using the PDF Y conductor for poles. |
| 23 | PhaseConductor3Id | Text(32) | No | As PhaseConductorId, using the PDF B conductor for poles; NULL for Y-only PV. |
| 24 | PositionCode | Long Integer | No | `0` (workbook) |
| 25 | ConfigurationId | Text(32) | No | `12.5/7.2 kV cross arm C2-2` (workbook) |
| 26 | IsContingency | Long Integer | No | `0` (workbook) |
| 27 | UseSectAmpRating | Long Integer | No | `0` (workbook) |
| 28 | AmpRating | Double | No | `100` (workbook) |
| 29 | DistLoadCustZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 30 | SpotLoadCustZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 31 | UseDistCustZone | Long Integer | No | `0` (workbook) |
| 32 | UseSpotCustZone | Long Integer | No | `0` (workbook) |
| 33 | UseCustClassDistLoadIzpq | Long Integer | No | `0` (workbook) |
| 34 | UseCustClassSpotLoadIzpq | Long Integer | No | `0` (workbook) |
| 35 | ExposureZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 36 | MitiZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 37 | GrowthZone | Text(32) | No | `Unknown` (workbook) |
| 38 | FromElbowOrBayStatus | Text(1) | No | `N` (workbook) |
| 39 | ToElbowOrBayStatus | Text(1) | No | `N` (workbook) |
| 40 | ElbowOrBayAmpRating | Double | No | `100` (workbook) |
| 41 | DuctBankId | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 42 | DuctBankInst | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 43 | DuctBankCircuit | Long Integer | No | `1` (workbook) |
| 44 | UseDuctBankAmpRating | Long Integer | No | `0` (workbook) |
| 45 | UseDuctBankConfiguration | Long Integer | No | `0` (workbook) |
| 46 | SpotLoadIsOff | Long Integer | No | `0` (workbook) |
| 47 | EnergizeYear | Long Integer | No | `0` (workbook) |
| 48 | RetireYear | Long Integer | No | `100` (workbook) |
| 49 | LoadCategory | Long Integer | No | `0` (workbook) |
| 50 | NeutIsGrounded | Long Integer | No | `1` (workbook) |
| 51 | HideMapResults | Long Integer | No | `0` (workbook) |
| 52 | Note_ | Text(100) | No | Existing mapped value retained; schema default unchanged. |
| 53 | LocationLink | Text(200) | No | `*` (workbook) |
| 54 | AMSLink | Text(125) | No | Existing mapped value retained; schema default unchanged. |
| 55 | Monitor | Text(10) | No | Existing mapped value retained; schema default unchanged. |
| 56 | ExpFactPermFail | Double | No | `1` (workbook) |
| 57 | ExpFactTempFail | Double | No | `1` (workbook) |
| 58 | ExpFactRepair | Double | No | `0` (workbook) |
| 59 | CostZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
