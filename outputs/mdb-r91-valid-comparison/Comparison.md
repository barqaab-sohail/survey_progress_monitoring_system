# Generated revision 91 versus valid_synergee.mdb

Inspected 27 October 2026 using read-only DAO extraction. Both source SHA-256 hashes remained unchanged. No saved queries, macros, modules, native SynerGEE import, or load-flow calculations were executed.

## Findings

The generated MDB and approved reference have identical table definitions, indexes, and relationships (50 tables and 41 relationships). They represent different transformers and surveyed networks. The generated customer and apparent-demand values are populated, but its Loads layout differs from the reference. The reference also includes a modelled PV connection.

| Item | Generated r91 | Approved valid_synergee.mdb |
|---|---:|---:|
| Transformer ID | 11140223003 | T-8160421145 |
| Transformer type | 100 KVA | 100 KVA |
| Feeder | Internal identifier, source feeder 407 | Kot Madina |
| Substation label | 407 | 132 kv Khanewal |
| Nodes | 14 | 33 |
| Sections | 13 | 31 |
| Loads rows | 108 | 30 |
| Customers (sum across phases) | 74 | 242 |
| Demand (sum across phases) | 148 kVA | 728.60 kVA |
| Stored section length | 466.254 m | 1142.869 m |
| Zero-length sections | 2 | 0 |
| Zero/empty customer-and-kVA load rows | 98 | 3 |
| PV connections (InstDGens) | 0 | 1 |
| PV year records (InstMymDGens) | 0 | 1 |
| Modelled PV output, Year 0 | None recorded | 5.00 kW |
| Meter points | 0 | 1 |

## PV details

The approved reference includes:

- SectionId: `103331080825010`.
- DGenDesc: `PVGEN-103331080825010`.
- InstMymDGens.YearNumber: `0`.
- Phase1Kw / Phase2Kw / Phase3Kw: `1.6666666667` each (5 kW total).
- Phase kvar: zero on all three phases.
- CapacityFactor: 1; DGenOutputPct: 100; DGenVoltSet: 120.
- Its PV SectionId resolves to an existing section. InstGenerators is empty in both databases; this does not mean the reference has no PV.

The revision 91 source JSON contains zero PV records and empty pv_details in all 24 survey rows. Therefore the generated absence is consistent with its supplied survey data. The reference PV belongs to a different transformer and must not be copied into this survey. If 11140223003 has PV, capture and verify its own PV details and configure the export generator mapping. Generator connection records do not establish individual metered PV customer counts.

Demand is expressed in kVA and generation in kW. Subtracting 5 kW directly from 728.6 kVA would not establish net apparent demand.

## Loads and allocation

The application writes nine consumer-category rows per survey section: 12 sections x 9 categories = 108 Loads rows. Only 10 have nonzero customers/demand; 98 are zero. The reference has one Loads row on each of 30 sections, including three zero/empty rows. Loads.SectionId has non-unique indexes in both files, so this layout is permitted by the database schema; native SynerGEE import must still be checked to confirm that repeated section rows are summed and not overwritten.

Generated 74 customers and 148 kVA match the revision 91 source JSON: 36 RS customers contribute 72 kVA, and 38 SC customers contribute 76 kVA. kW/kvar are NULL in the generated file under the direct-kVA method; the reference stores zero kW/kvar. Neither representation should be described as measured real/reactive power.

| Phase | Generated customers | Generated kVA | Reference customers | Reference kVA |
|---|---:|---:|---:|---:|
| R | 29 | 58 | 69.333333 | 208.866667 |
| Y | 24 | 48 | 104.333333 | 313.866667 |
| B | 21 | 42 | 68.333333 | 205.866667 |

Generated allocation keeps customer counts as whole numbers. The reference includes fractional per-phase customer allocation. Different totals are expected for different surveys and should not be forced to match.

## Network checks

- Missing section endpoints: generated 0; reference 0.
- Orphan load rows: generated 0; reference 0.
- Orphan PV references: generated 0; reference 0.
- Nodes reachable from the feeder with undirected edges: generated 14/14; reference 32/33.
- Nodes reachable following FromNodeId to ToNodeId: generated 13/14; reference 32/33.
- Generated has two zero-length links: the logical transformer terminal link and the first S/E pair, whose endpoints share the source coordinate under the current first-pair policy. If that pair represents a physical span, confirm its length.
- The generated first survey edge points toward the root. This is an orientation-review finding; all generated nodes are connected when ignoring edge direction.

## Matching electrical settings requiring independent confirmation

Both store Frequency 60, nominal feeder voltage 11 kV, BusVoltageLevel 11.37, SpecNomKv 4.16, NomKvMode C, YG/YG transformer connections, and UseInstanceImpedance 0. Both use metric length units, WGS 1984 UTM Zone 43N, SynerGEE Electric 5.0.0.324, and the line configuration 12.5/7.2 kV cross arm C2-2.

The generated JSON has secondary_voltage_kv unset, retaining SpecNomKv 4.16 from mapping values. Confirm these values and library entries against the department requirements for this transformer. Agreement with an approved file for a different transformer is not independent verification.

The estimated demand/nameplate ratios are 148% and 728.6%, respectively. They compare connected model inputs to the type label and do not establish measured simultaneous loading or a load-flow overload.

## Meter and other differences

The reference meter point is linked to SectionId 103338160421145.2, with its measurement fields unset. Generated meter-point tables are empty. The reference also has year labels and descriptive/optional text fields that are unset in the generated file.

## Record count inventory

| Table | Generated | Reference |
|---|---:|---:|
| Gnodes | 0 | 0 |
| InstAreaOfInt | 0 | 0 |
| InstAreaOfIntPts | 0 | 0 |
| InstBkrRelays | 0 | 0 |
| InstBreakers | 0 | 0 |
| InstCapacitors | 0 | 0 |
| InstDGens | 0 | 1 |
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
| InstMymDGens | 0 | 1 |
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
| InstSection | 13 | 31 |
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
| Loads | 108 | 30 |
| Node | 14 | 33 |
| SAI_Control | 1 | 1 |

## Evidence

- `synergee_11140223003_batch-17_r91.mdb` SHA-256: `c42328ce43bdae3ea943a5e80162269c4517c7536fd335537719e3368d1dfc48`.
- `valid_synergee.mdb` SHA-256: `1065f140ba2c964c1fb30d4e0e73aa581db03913b5e0ca90f3b1bae5c731bf3a`.

Raw extracts are retained as generated.json and reference.json. This comparison did not modify either MDB or application code. Native import, connectivity interpretation, equipment-library resolution, and load-flow acceptance remain to be tested in the target SynerGEE installation.
