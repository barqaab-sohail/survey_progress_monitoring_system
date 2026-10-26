# MDB comparison ? 26 October 2026

The files have exactly matching database schemas, but represent different transformer networks. The generated file includes geometry and section lengths; it still lacks important electrical inputs present in Choper Hatta. Schema compatibility alone does not establish that it is ready for load-flow analysis.

## Files and method

- A: `synergee_11140223003_batch-17_r79 (2).mdb` ? 1,331,200 bytes. SHA-256: `86c6a20db64b86f0dbfa4987d8a876799a5c5a9601c4b48b6cf5c38de6b86def`.
- B: `Choper HattaT-3030216724.mdb` ? 3,973,120 bytes. SHA-256: `a13f0d2599a7c998ff9554bee70b67aed7c5140d86604631b4b82901851c486b`.

Both were inspected directly using 32-bit Access DAO, opened read-only. Source hashes were unchanged after inspection. Saved queries, macros and modules were not executed. This report compares user-table schemas, field metadata, indexes, relationships and table data; it does not compare Access forms or application code.

## Structure and records

| Item | A: Generated r79 (2) | B: Choper Hatta |
|---|---:|---:|
| User tables | 50 | 50 |
| Field definitions | 1,065 | 1,065 |
| Indexes | 133 | 133 |
| Relationships | 41 | 41 |
| Nodes | 14 | 26 |
| Sections | 13 | 24 |
| Loads | 10 | 23 |
| Feeders | 1 | 1 |
| Primary transformers | 1 | 1 |
| Meter points | 0 | 1 |
| Populated tables | 6 | 7 |

All field definitions (including types, sizes, defaults, required flags and validation), index definitions and relationships match. Both have 43 empty tables in common; all seven tables populated in either file have different record contents. The full table count comparison is in `table-counts.csv`. Different network identities and record counts are expected and do not themselves indicate an error.

## Material content comparison

| Item | A: Generated r79 (2) | B: Choper Hatta |
|---|---|---|
| Transformer identity | 11140223003 | T-3030216724 |
| Transformer library type | Empty string | 200 KVA |
| UseInstanceImpedance | 0 | 0 |
| Node X/Y coordinates | Present for all 14 | Present for all 26 |
| Section lengths | Present for all 13 | Present for all 24 |
| Sum of stored section lengths | 466.254103 | 882.184604 |
| Zero-length sections | 2 | 0 |
| Section configuration | All 13: 12.5/7.2 kV cross arm C2-2 | All 24: same configuration |
| Section phase codes | RYB: 1; RYBN: 12 | RYB: 1; RYBN: 16; R  N: 4;  Y N: 2;   BN: 1 |
| LoadConnectionPhaseGnd | NULL on all 13 sections | Populated on all 24 |
| Per-phase kW / kvar | NULL throughout all 10 loads | Populated, stored total 0 / 0 |
| Per-phase kVA | NULL throughout all 10 loads | Stored numeric sum 518 across 23 load rows |
| Per-phase customer fields | NULL throughout all 10 loads | Stored numeric sum approximately 173 |
| Model-year descriptions | NULL | 2015 through 2025 |

Lengths are reported in the stored `SectionLength_MUL` values; both files label LengthUnits as Metric. The sums are descriptive and are not proof of physical accuracy. Likewise, summing the stored customer fields does not independently establish a unique consumer count. NULL load demand in A means unspecified, not a validated zero demand.

Both store SynerGEE Electric 5.0.0.324, 60 Hz, Metric units, projection label WGS 1984 UTM Zone 43N, feeder nominal voltage 11 kV and bus voltage level 11.37. These matching recorded settings have not been independently verified against project requirements.

A's coordinates range from X 339387.768 to 339548.844 and Y 3782362.344 to 3782646.172. B's range from X 783524 to 783849 and Y 3380959 to 3381257. The networks are geographically different according to their stored positions; geometry should not be copied between them.

## Integrity and interpretation

Both pass the inspected checks for unknown section endpoints, unknown feeder references, orphan loads, nonblank relationship keys without parents, and duplicate fully populated unique-index keys. Neither has self-connections or duplicate undirected section connections.

All 14 nodes in A are reachable from its feeder. In B, 25 of 26 are reachable; the remaining node is isolated and unused by sections. All sections in both files are reachable from their feeder.

A's transformer section declares RYB while its conductor fields are NULL. B has one RYBN section with an empty neutral-conductor reference. These are review observations: transformer and special model links may be handled differently from physical line sections, so this check alone does not prove an invalid model. A also has two zero-length sections requiring interpretation in the model context.

The most material unresolved issue in A is the blank TransformerType while UseInstanceImpedance is 0, followed by unspecified load demand and load-connection fields. Equipment-library availability, phase continuity, geometry accuracy, voltage-domain correctness and solver convergence require validation in SynerGEE. No native SynerGEE opening or load-flow test was performed, and B is not independently certified as an approved model by this comparison.

## Supporting artifacts

- `comparison.json`: geometry, network, missing-field and integrity results.
- `schema-and-data-summary.json`: exact schema comparison and source hashes.
- `table-counts.csv`: counts for all 50 tables.
- `field-value-differences.csv`: every field whose value-frequency distribution differs (including NULL versus empty string). These distributions are not a row-by-row alignment between unrelated network identities.
- `generated.json` and `reference.json`: complete read-only table inspections.

No source MDB or application implementation was modified.
