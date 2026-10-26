# Review of application MDB against approved Choper Hatta MDB

Review date: 26 October 2026, Asia/Karachi.

## Verdict

The application output is a readable Access MDB with an exact match to the approved reference's captured database schema. Its network references and connectivity pass the checks performed. Its geometry is populated. However, the file is not yet demonstrated to be an analysis-ready SynerGEE electrical model: transformer equipment identification and load data are incomplete, and section load connections are unspecified. Native SynerGEE opening, equipment resolution and load flow have not been tested.

This review uses the exact two files requested, not the older comparison elsewhere in this project, which used a different reference and an older generated file with the same filename.

## Method and scope

Both files were opened read-only using the installed 32-bit Access DAO engine. All 50 non-system tables, all their rows, field metadata, indexes and 41 relationship definitions were inspected. Saved queries, macros and modules were not executed. SHA-256 checks confirmed both source files remained unchanged. Database contents were treated as data, not instructions. No application code or source MDB was modified.

The canonical approved-schema checker and an independent comparison of all captured schema properties both matched. Data checks included declared relationship references, duplicate fully populated unique keys, section endpoints and feeder references, undirected connectivity from the feeder, duplicate edges, self-connections, orphan loads, field completeness, and section length versus straight-line endpoint distance. These are database and model-data checks, not a substitute for the SynerGEE solver or field verification. Partially blank composite keys are excluded from the unique-key and relationship checks.

| Item | Application output | Approved reference |
|---|---|---|
| Filename | synergee_11140223003_batch-17_r79.mdb | Choper HattaT-3030216724.mdb |
| Size | 1,331,200 bytes | 3,973,120 bytes |
| SHA-256 | 7a30bbca58ddd4481437a42f698e14043584a05c7bfe2bd368abdd2da6de6964 | a13f0d2599a7c998ff9554bee70b67aed7c5140d86604631b4b82901851c486b |

These are different transformer networks. Different totals, coordinates and identifiers are expected and should not be made identical. Approval of the reference is the user's stated status; it was not independently reproduced in SynerGEE here. File size is not a validity test, since both network size and Access allocation differ.

## Structure and contents

| Check | Generated | Reference |
|---|---:|---:|
| User tables | 50 | 50 |
| Fields | 1,065 | 1,065 |
| Indexes | 133 | 133 |
| Relationships | 41 | 41 |
| Nodes | 14 | 26 |
| Sections | 13 | 24 |
| Feeders | 1 | 1 |
| Primary transformers | 1 | 1 |
| Load records | 10 | 23 |
| Meter records | 0 | 1 |
| Nodes with X/Y | 14/14 | 26/26 |
| Sections with length | 13/13 | 24/24 |
| Sections with phase code | 13/13 | 24/24 |
| Sections with configuration ID | 13/13 | 24/24 |
| Sections with load connection | 0/13 | 24/24 |

Both schema fingerprints are `a2659f32f858dbc0dc25ab75756e7f6d117cd9216a4a8839d938cb014c5bc4cf`. Captured field types, sizes, required flags, defaults, validation rules, attributes, indexes and relationship definitions match.

Both store `SynerGEE Electric 5.0.0.324`, metric units, `WGS 1984 UTM Zone 43N`, frequency 60, voltage factor 11 and PTSecondary 120. Matching these settings establishes consistency with this reference, not independent confirmation that they are the right settings for the new project. Both have empty ProjectionWKT (NULL versus empty string). The reference has 2015?2025 model-year labels; the generated file does not. Those labels should follow the intended study rather than being copied automatically.

## Findings by priority

### 1. High: transformer equipment definition is missing

`InstPrimaryTransformers.TransformerType` is an empty string in the generated file, versus `200 KVA` in Choper Hatta. Both have `UseInstanceImpedance = 0`, so the generated file does not identify its intended library equipment while also not selecting instance impedance. This leaves equipment resolution and the electrical transformer model unestablished.

Assign the correct reviewed equipment-library type for transformer 11140223003. Do not copy `200 KVA` merely because it is present in the approved file. Verify the actual capacity, ratio, winding connections, impedance and losses using the intended library and project inputs. Both files also carry `SpecNomKv = 4.16` and YG/YG connection codes; matching inherited settings is not proof of their suitability or interpretation in the new model.

### 2. High: load rows exist but contain no phase demand or customer allocation

All 10 generated load rows have NULL in every Phase1/2/3 Customers, Kw, Kvar and Kva field: 120 missing cells across these quantities. A load row or its category text alone does not establish electrical demand. NULL must not be reported as verified zero demand.

In the reference, all 23 load rows have populated per-phase kW/kvar values (zero). Its kVA fields have 55 numeric cells totaling 518 kVA, and phase-customer fields have 55 numeric cells totaling approximately 173. These are stored reference totals, not measured simultaneous demand or an independently assessed transformer loading result. They must not become defaults for the exported network.

Populate demand and customer allocation from the reviewed survey and an approved estimation or measurement method. Check phase allocation against connected phases. Missing load data blocks a meaningful conclusion about voltage drop, loss or loading, even if the file opens.

### 3. High: section load connection is unspecified

`InstSection.LoadConnectionPhaseGnd` is NULL on all 13 generated sections; it is 1 on all 24 reference sections. This is a concrete difference in electrical specification. Confirm the correct native SynerGEE meaning and intended connection, then populate it through reviewed project configuration. Do not assume that NULL and 1 are interchangeable or blindly substitute the reference value.

### 4. Review: transformer-link conductor handling

The generated transformer section `sac3694570168772e1b2264107f0999` has RYB phases but all three phase-conductor fields are NULL. It is a zero-length logical transformer link with a transformer device attached. This is not automatically a missing physical line: native SynerGEE must establish whether this device-bearing link is modeled correctly without conductor definitions.

The other 12 survey sections have RYBN phases and populated active-phase and neutral conductor codes. Observed generated codes are ANT and GNAT. The reference uses ANT, DOG and WASP, including some values with leading spaces. Different conductors can be legitimate for different networks; resolve the actual exported codes against the installed equipment library. A nonblank code alone does not establish valid impedance or ampacity.

### 5. Review: geometry passes basic checks; two zero-length links need context

Generated coordinates are populated and finite. X ranges from 339387.768 to 339548.844; Y ranges from 3782362.344 to 3782646.172. Generated section lengths total approximately 466.254 m, compared with 882.185 m in the reference. Neither file has a stored section length more than 0.1 m shorter than the straight-line distance between its endpoints. This is a consistency check only; it does not prove survey accuracy, correct CRS, route fidelity or sag allowance.

Two generated links have length zero and coincident endpoint coordinates:

- `sac3694570168772e1b2264107f0999`: transformer-terminal-5 to 08081026027; carries the transformer device and no loads.
- `s51f353d9d3292bf0998cb8d95af3e8`: 11140223003 to 08081026027; no attached transformer or loads.

The first is consistent with the current exporter's deliberate logical-terminal construction. Check whether the second is also an intentional connection at the same location, or whether the source has collapsed distinct physical positions. Do not invent a positive length to make a validation check pass. The reference lengths range from 1 to 65.947 m; generated lengths range from 0 to 74.670 m.

### 6. Review: construction configuration is populated but still needs engineering verification

All sections in both files use `12.5/7.2 kV cross arm C2-2`. The generated file also carries the same common spacing/height defaults visible in the reference. This establishes that fields are populated, not that the geometry is appropriate for each surveyed LT section. Verify the intended construction definition, spacing and conductor arrangement in the installed library. The name alone does not establish that the equipment is incorrect.

### 7. Informational: network integrity is good

All 14 generated nodes are reachable from the feeder using undirected section connectivity. There are no unknown section endpoints, unknown feeder references, self-loops, duplicate undirected edges or orphan loads. All inspected nonblank declared relationship keys resolve, and no duplicate fully populated unique keys were found. The connected 14-node/13-edge generated network forms an undirected tree.

The reference has 25 reachable nodes and one isolated unused node; all 24 sections are reachable. Its inspected key checks also pass. The isolated reference node is not a reason to add an isolated node to the generated file. These checks do not prove phase continuity, device orientation, voltage-domain correctness or solver convergence.

### 8. Informational: other reference differences need no automatic copying

The reference's single meter record has its non-key fields NULL; its presence alone does not establish required metering data for this export. Both files have no distributed-generator records. Most optional device tables are empty in both. Generated hashed IDs differ from descriptive reference IDs, but are internally consistent and fit the same schema. Short generated category descriptions versus reference text are mainly a usability/traceability difference unless another integration relies on a naming convention.

## What the source code explains

The current `ExportPayloadBuilder.php` explicitly writes NULL phase demand/customer fields in Simple mode when no phase demand values are supplied (around line 324). Its `engineering()` helper skips engineering-only enforcement in Simple mode (around line 466). `NetworkValidator.php` similarly downgrades listed missing-engineering conditions to warnings in Simple mode. This explains how a structurally valid export can remain electrically incomplete; the MDB alone does not prove the historical mode/settings used to generate it.

The builder also creates a zero-length logical transformer terminal when Simple mode has no explicit source network (around line 112), matching the pattern found here. Current `ModelReadiness.php` checks coordinates, lengths, transformer type, configuration, phases and load allocation, but does not explicitly check LoadConnectionPhaseGnd or equipment-library resolution. Its numeric nonnegative-length check can also accept zero-length survey sections. The application should distinguish successful MDB generation from engineering completeness and native acceptance, and expose these remaining review items clearly.

The prior local report's missing-coordinate diagnosis does not apply to the file inspected now: this exact generated file has populated coordinates, lengths, phase codes and configuration references, and a different SHA-256.

## Recommended completion sequence

1. Resolve transformer equipment type and verify voltage/connection settings against the actual transformer and installed library.
2. Complete reviewed load demand and phase customer allocation.
3. Specify and verify section load connections; resolve conductor and construction references, including the logical transformer link behavior.
4. Confirm both zero-length links against source topology and physical survey evidence.
5. Regenerate from the reviewed source/settings and rerun database completeness checks.
6. Open a copy in the target SynerGEE installation, resolve equipment, inspect map and transformer orientation, run native model checks and load flow, and review warnings, voltages, loading and losses.

Final assessment: database structure passes; basic geometry and graph integrity pass; electrical completeness has material gaps; native SynerGEE acceptance remains untested. No code fixes were made as part of this review.

## Evidence files

`schema-validation.json` records canonical schema checks. `comparison.json` contains network and field-completeness results. `extended-checks.json` contains length checks and populated-table field profiles. `table-counts.csv` lists all 50 table counts. The two `*-inspection.json` files contain full read-only table extracts and metadata; they may contain project identifiers. `analyze.mjs` and `check-schema.ps1` support reproducing the checks with the repository's read-only inspector.
