# MDB comparison — generated revision 91 and approved reference

Inspected on 27 October 2026. Both databases were opened read-only through DAO. Source SHA-256 hashes were unchanged after extraction. No queries, macros, modules, or SynerGEE load flow were executed.

## Result

The transformer type and category-based customer/demand values are now populated in the generated MDB. Both files have identical table structures, field definitions, indexes, and relationships. The files describe different transformers and networks, so their customer totals, capacities, coordinates, and lengths are expected to differ. Structural agreement does not establish native SynerGEE acceptance.

## Main comparison

| Item | Generated application MDB | Approved reference MDB |
|---|---|---|
| Transformer | 11140223003 | T-3030216724 |
| Transformer type | 100 KVA | 200 KVA |
| User tables / relationships | 50 / 41 | 50 / 41 |
| Nodes / sections | 14 / 13 | 26 / 24 |
| Loads rows | 108 | 23 |
| Customers summed across phases | 74 | 173 |
| Apparent demand summed across phases | 148 kVA | 518 kVA |
| Nonzero customer/demand rows | 10 | 20 |
| Zero/empty customer-and-kVA rows | 98 | 3 |
| Total stored section length | 466.254 m | 882.185 m |
| Meter points | 0 | 1 (measurement values unset) |
| Missing section endpoints / orphan load rows | 0 / 0 | 0 / 0 |

## Load verification

The corresponding revision 91 JSON was also read. Its engineering configuration revision is 7 and its load method is `category_rates`. Its survey consumer counts total 74; its derived phase demand totals 148 kVA. These totals match the generated MDB. Every generated phase customer and kVA field is populated. Generated kW and kvar fields are NULL, consistent with the configured direct-kVA method; the reference stores zero kW and kvar. NULL means unspecified, and must not be interpreted as a measured zero.

| Phase | Generated customers | Generated kVA | Reference customers | Reference kVA |
|---|---:|---:|---:|---:|
| R | 29 | 58 | 60.667 | 181.667 |
| Y | 24 | 48 | 53.667 | 160.667 |
| B | 21 | 42 | 58.667 | 175.667 |

The generated totals are 36 RS customers ? 72 kVA and 38 SC customers ? 76 kVA. All other categories have zero demand. Generated phase customer allocation uses whole customers; the reference includes fractional customer allocations. The reference total represents a different survey and must not be copied into this model.

## Findings requiring review

1. **Multiple Loads rows per section:** the application writes nine consumer-category records for each of 12 survey sections (108 rows). Of these, 98 have zero customers and zero kVA. The reference has one Loads row on each of 23 sections. Both files permit repeated SectionId values through non-unique indexes, but that alone does not establish how the target SynerGEE importer handles repeated section loads. Verify that importing this generated file preserves the full 74 customers and 148 kVA instead of overwriting a category row. The completeness count includes zero category records.

2. **Two zero-length sections:** one is the logical transformer terminal link; the other is the first survey S/E pair. Revision 91 JSON maps that pair (references 003 ? 027) to the same source waypoint (960), consistent with the existing first-pair coordinate policy. The reference has no zero-length sections. Confirm that the first pair is intentionally coincident; if it represents a real span, its physical length is absent.

3. **Section direction:** all 14 generated nodes are connected when edges are treated as undirected. Following FromNodeId ? ToNodeId from the feeder reaches 13 of 14 nodes; the first survey S/E edge points toward the root. This is a direction-review finding, not proof of a broken physical connection. The reference reaches 25 of 26 nodes in either direction check; its remaining node is isolated. Native import should be checked for how it orients these edges.

4. **Inherited electrical values:** both files store Frequency = 60, NominalKvll = 11, transformer SpecNomKv = 4.16, NomKvMode = C, YG/YG connections, and UseInstanceImpedance = 0. Revision 91 JSON has secondary_voltage_kv unset, so the 4.16 value is retained from mapping values. Matching the reference does not independently confirm suitability for this transformer. Confirm frequency, secondary-voltage settings, connections, and library data against the department specification.

5. **Demand versus nameplate:** estimated connected demand is 148 kVA against a 100 KVA type label (148%). The reference similarly totals 518 kVA against 200 KVA (259%). These are sums of model inputs, not measured simultaneous loading or load-flow results; no overload conclusion is established by this comparison alone.

6. **Meter and descriptive differences:** the generated file has no meter point; the reference contains one with measurement values unset. The generated feeder uses internal identifiers and substation label 407, while the reference uses Choper Hatta and 132KV M.Pur. Reference-only year labels 2015–2025 and optional text/location markers are not populated in the generated file. These are separate from the resolved load-population issue.

## Table record counts

| Table | Generated | Reference |
|---|---:|---:|
| Gnodes | 0 | 0 |
| InstAreaOfInt | 0 | 0 |
| InstAreaOfIntPts | 0 | 0 |
| InstBkrRelays | 0 | 0 |
| InstBreakers | 0 | 0 |
| InstCapacitors | 0 | 0 |
| InstDGens | 0 | 0 |
| InstDTrans | 0 | 0 |
| InstFaultEvents | 0 | 0 |
| InstFeederSet | 0 | 0 |
| InstFeederSetItem | 0 | 0 |
| InstFeeders | 1 | 1 |
| InstFuses | 0 | 0 |
| InstGenerators | 0 | 0 |
| InstLargeCust | 0 | 0 |
| InstMeterPoints | 0 | 1 |
| InstMotors | 0 | 0 |
| InstMymAreaOfInt | 0 | 0 |
| InstMymDGens | 0 | 0 |
| InstMymLargeCust | 0 | 0 |
| InstMymLoads | 0 | 0 |
| InstMymMeter | 0 | 0 |
| InstMymProjLoads | 0 | 0 |
| InstMymSections | 0 | 0 |
| InstMymSwDevPos | 0 | 0 |
| InstPrimaryTransformers | 1 | 1 |
| InstProjLoads | 0 | 0 |
| InstProtectiveDevices | 0 | 0 |
| InstRecRelays | 0 | 0 |
| InstReclosers | 0 | 0 |
| InstRegulators | 0 | 0 |
| InstSchematic | 0 | 0 |
| InstSchematicItem | 0 | 0 |
| InstSection | 13 | 24 |
| InstSectionalizers | 0 | 0 |
| InstSubstationTransformers | 0 | 0 |
| InstSwPlan | 0 | 0 |
| InstSwPlanItem | 0 | 0 |
| InstSwitches | 0 | 0 |
| InstTccItem | 0 | 0 |
| InstTccSet | 0 | 0 |
| InstTextLabels | 0 | 0 |
| InstTranBus | 0 | 0 |
| InstTranLine | 0 | 0 |
| InstTranVertices | 0 | 0 |
| InstViews | 0 | 0 |
| InstWhatIf | 0 | 0 |
| Loads | 108 | 23 |
| Node | 14 | 26 |
| SAI_Control | 1 | 1 |

## Source evidence

- `synergee_11140223003_batch-17_r91.mdb`: SHA-256 `c42328ce43bdae3ea943a5e80162269c4517c7536fd335537719e3368d1dfc48`.
- `Choper HattaT-3030216724.mdb`: SHA-256 `a13f0d2599a7c998ff9554bee70b67aed7c5140d86604631b4b82901851c486b`.

Raw read-only extracts: `generated.json` and `reference.json`. Computed metrics: `comparison-summary.json`. No source database or application code was changed for this comparison.
