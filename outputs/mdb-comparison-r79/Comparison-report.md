# SynerGEE MDB comparison

Inspection date: 25 October 2026 (Asia/Karachi).

**Finding: the generated MDB matches the approved reference's database structure, but its contents are incomplete for a validated electrical model. Opening, display and successful load-flow operation in SynerGEE have not been demonstrated.**

## Files and inspection method

| Role | File | Size (bytes) | SHA-256 |
|---|---|---:|---|
| Application output | synergee_11140223003_batch-17_r79.mdb | 1,331,200 | cb87207aeb01639f7a0030602ad3a4427a12754ca3ad815439dfd5f7d6d15cc1 |
| User-designated approved reference | Noor PurT-4221215879.mdb | 3,973,120 | 75075516545951cfbf290a3d521a3e312420f1b2a9d561f1e6aa6256e1e81861 |

Both files were opened read-only using the installed 64-bit Access DAO engine. Table metadata and data were read without running saved queries, macros or modules. The existing approved-schema checker was run on both. Source hashes were checked before and after inspection and remained unchanged. No application source code or MDB content was changed for this comparison.

These files represent different transformers. Different identifiers, customer counts, node counts, section counts and lengths are expected; matching them row for row would not establish correctness. The second file's SynerGEE approval is the user's stated reference status, not an approval independently reproduced here.

## Structural compatibility

| Check | Generated | Approved reference | Assessment |
|---|---:|---:|---|
| User tables | 50 | 50 | Exact schema match |
| Fields | 1,065 | 1,065 | Exact definitions match |
| Indexes | 133 | 133 | Exact definitions match |
| Relationships | 41 | 41 | Exact definitions match |
| Access readability | Passed | Passed | Both open through DAO |
| Stored product | SynerGEE Electric 5.0.0.324 | SynerGEE Electric 5.0.0.324 | Same stored target |
| Units / projection label | Metric / WGS 1984 UTM Zone 43N | Metric / WGS 1984 UTM Zone 43N | Same labels; see missing coordinates below |
| Stored frequency | 60 Hz | 60 Hz | Same recorded value, not an independent project approval |

Both have this canonical schema fingerprint:

`a2659f32f858dbc0dc25ab75756e7f6d117cd9216a4a8839d938cb014c5bc4cf`

The fingerprint comparison includes field types, sizes, defaults, required flags, indexes and relationship definitions. The generated file's smaller size is not evidence of a format error; these are different networks and Access files can also retain different amounts of allocated space.

## Network contents

| Record type | Generated | Approved reference |
|---|---:|---:|
| Node | 14 | 22 |
| InstSection | 13 | 20 |
| InstFeeders | 1 | 1 |
| InstPrimaryTransformers | 1 | 1 |
| Loads | 10 | 19 |
| InstMeterPoints | 0 | 1 |
| InstDGens / InstMymDGens | 0 / 0 | 0 / 0 |

For the generated network, all 14 nodes are reachable from the feeder through undirected section connections. There are no unknown section endpoints, unknown feeder references, self-connections, duplicate undirected section connections or orphan loads. Relationship checks found no nonblank child keys without a parent. Unique-index checks found no duplicate fully populated keys.

The reference has 21 nodes reachable from the feeder and one isolated unused node. All its sections are reachable and its inspected references also pass. The unused node does not by itself show a failure in the user's approved model.

These checks establish referential and graph consistency. They do not establish electrical phase continuity, voltage-domain correctness, impedance correctness or solver convergence.

## Material differences and missing inputs

| Item | Generated MDB | Approved reference | Implication |
|---|---|---|---|
| Projected node coordinates | X and Y NULL for all 14 nodes | X and Y present for all 22 nodes | Generated MDB has no stored map positions. Display behavior requires a SynerGEE test. |
| Section lengths | NULL for all 13 sections | Present for all 20 sections | Physical line lengths are unavailable for assessing line impedance and losses. Reference includes valid model links with zero length; the requirement is not that every model link have positive length. |
| Transformer library type | Empty string | `100 KVA` | Output does not identify a transformer equipment definition. Both use `UseInstanceImpedance = 0`; the reference's library-dependent arrangement has not been completed in the output. |
| Section configuration ID | Present on 1/13 sections; missing on 12 | Present on all 20 | Most generated sections lack an explicit configuration reference. Actual alternative construction handling must be checked in SynerGEE. |
| Source/transformer section phases and conductors | One section has NULL phases and conductors | Section phases present throughout | The generated Simple-mode source link is an incomplete electrical specification. |
| Load connection field | NULL on all 13 sections | Populated on all 20 | Load connection interpretation has not been established in the output. |
| Load phase demand | All kW, kvar and kVA phase fields NULL in all 10 records | Per-phase kW/kvar populated (zero); kVA values include nonzero values | Generated load rows do not carry an electrical demand model. Null demand must not be interpreted as a verified zero load. |
| Phase customer allocation | NULL for all 10 load records | Values recorded, with some individual phase cells NULL | Counts retained in source survey/JSON have not been allocated into these MDB load fields. |
| Model-year labels | NULL | 2015–2025 | Reference-specific settings differ; these years should not be copied into the new model automatically. |
| Meter records | None | One record, mostly NULL fields | Difference alone does not prove a problem; a meter model depends on actual survey and intended analysis. |

All 12 generated survey sections have `RYBN` phases. Their active-phase conductor codes are present. The extra source section is the section missing phase and conductor details. Across the generated survey sections, observed conductor references include `ANT` and `GNAT`; their installed library definitions were not available for verification.

The reference's phase kVA cells total 252 kVA and its numeric phase customer cells sum to approximately 84. These values describe a different transformer model and are **not** proposed defaults for transformer 11140223003. The generated load table contains no populated phase demand or customer cells; its numeric sum cannot be taken as zero actual demand.

## Source JSON cross-check and likely cause

The accompanying downloaded `synergee_11140223003_batch-17_r79.json` was also inspected read-only:

- Simple MDB Generation is ON.
- EPSG is 32643.
- All 101 supplied waypoint records have numeric latitude/longitude values. This count covers the snapshot, not necessarily 101 nodes in the selected transformer export.
- Transformer 11140223003 has survey capacity 100 kVA and 12 survey sections.
- Template is `HAZECO-REFERENCE-SCHEMA`.
- LT secondary voltage is NULL, and there is no explicit source network mapping for this transformer.

The existing exporter deliberately writes NULL projected coordinates and lengths in Simple mode if projection fails, and records warnings. Previous local generation checks reported that the configured Python command was unavailable. **Projection failure is therefore consistent with the observed missing coordinates and lengths.** The database inspection alone does not reproduce the historical generation process, so this is a supported diagnosis rather than proof of its exact runtime state.

Likewise, a known survey capacity of 100 kVA does not automatically identify an approved library type. The installed library must establish the intended voltage ratio, winding connections, impedance and losses; copying the other transformer's type or voltage values would not verify this model.

## Conclusion and next steps

1. **Database format: passed.** The application has produced a genuine readable MDB with the exact approved Access schema and intact network references.
2. **Map and electrical model completeness: incomplete.** The gaps above prevent this inspection from establishing a meaningful loss or voltage analysis.
3. **Native SynerGEE acceptance: untested.** No SynerGEE opening, library-resolution, model-check or load-flow test was run. Access schema/readback tests cannot substitute for those tests.

Before treating the output as an analysis-ready model:

1. Configure a working Python/pyproj projection worker on the computer generating the MDB, then regenerate and confirm all selected nodes have metre-based coordinates and section lengths are populated as intended.
2. Assign the correct installed transformer library type, confirm voltage/connection settings, and complete source phases, conductors and section configuration references.
3. Enter or derive demand and phase customer allocation from a reviewed method. Simple mode permits these to be omitted; enabling Simple mode is not engineering validation.
4. Approve any changed source survey/configuration and export a fresh JSON when the frozen source settings change. A local projection-runtime fix can use the same intact JSON, but source mapping/settings changes require a new approved export.
5. Open a copy of the regenerated MDB in the target SynerGEE installation, confirm the equipment library resolves, inspect the map and source/transformer connectivity, run its model checks and load flow, and review warnings, voltages and losses with the responsible engineer.

The generated file might be accepted for opening or editing by SynerGEE, but that behavior has not been established. **Do not regard this comparison as certification of a working load-flow or loss-calculation model.**

## Supporting artifacts

- `schema-validation.json`: independent DAO schema verification for both files.
- `comparison.json`: network, field-completeness, unique-key and relationship check results.
- `table-counts.csv`: row counts for all 50 tables.
- `generated-inspection.json` and `reference-inspection.json`: raw read-only inspection exports.
- `analyze.mjs` and `check-schema.ps1`: scripts used for this comparison.
