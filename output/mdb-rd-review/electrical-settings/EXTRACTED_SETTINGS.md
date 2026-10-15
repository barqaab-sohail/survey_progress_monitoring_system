**Electrical settings extracted from the nine valid reference MDBs**

The supplied MDBs contain reusable saved electrical settings, construction settings and equipment reference names. The earlier assessment should distinguish settings already present in these references from the task of configuring them in the application for the new HAZECO survey. These files can provide a reference profile; they are not limited to schema examples.

All original hashes were rechecked before extraction. This extraction did not modify any original MDB or application configuration. It includes every field in the control, feeder, primary-transformer, section, load, meter and populated generator tables. NULL, empty text and zero remain separate values.

| Setting | Exact observed value | Coverage |
|---|---|---|
| Product | SynerGEE Electric 5.0.0.324 | 9/9 files |
| Frequency | 60 | 9/9 files |
| Nominal feeder line-line voltage | 11 kV (`NominalKvll`) | 9/9 files |
| Bus voltage level | 11.37 (`BusVoltageLevel`) | 9/9 files |
| Feeder connection code | YG | 9/9 files |
| Voltage factor | 11 | 9/9 files |
| Length units | Metric | 9/9 files |
| Phase labels | R, Y, B | 9/9 files |
| Projection label | WGS 1984 UTM Zone 43N | 9/9 files; ProjectionWKT is empty |
| Feeder multiplier | 1 | 9/9 files |
| Individual phase voltage mode | UseByPhVoltLevel = 0 | 9/9 files |
| Stored phase voltages | 11, 11, 11 | 9/9 files; their use is controlled by the mode flag |
| Stored phase voltage angles | 0, -120, 120 | 9/9 files |
| Transformer phases | RYB | 9/9 files |
| Transformer library types | 100 KVA (7 files), 200 KVA (2 files) | Exact type labels |
| Transformer tap setting | 1 | 9/9 files |
| HV/LV connection codes | YG / YG | 9/9 files |
| Transformer impedance mode | UseInstanceImpedance = 0 | 9/9 files |
| Stored instance impedance/resistance | 0 / 0 | 9/9 files; not evidence of physical zero impedance |
| Transformer nominal-voltage mode/value | NomKvMode = C; SpecNomKv = 4.16 | 9/9 files; interpret together |
| Construction configuration ID | 12.5/7.2 kV cross arm C2-2 | All 187 sections |
| Phase/phase spacing field | PhaseToPhaseSpacing_MUL = 121.9 | All 187 sections; raw stored units |
| Phase/neutral spacing field | PhaseToNeutralSpacing_MUL = 91.4 | All 187 sections; raw stored units |
| Average-height field | AveHeightAboveGround_MUL = 9.1 | All 187 sections |
| Neutral-grounding flag | NeutIsGrounded = 1 | All 187 sections |
| Distributed-load impedance/current components | 40 / 10 | All 187 sections; PercentDistLoadConstImpedance / PercentDistLoadConstCurrent |
| Spot-load impedance/current components | 40 / 30 | All 187 sections; PercentSpotLoadConstImpedance / PercentSpotLoadConstCurrent |

Observed conductor names include ANT, WASP, DOG, ABC 50 MM2 and GNAT. Exact column values are preserved in `reference-profile.json`; some phase-Y names have leading spaces. These names can seed equipment mappings, but any PVC designation absent from the references needs an additional matching library entry.

**Transformer-specific settings**

| Reference transformer | Saved library type |
|---|---|
| T-3030216724 | 200 KVA |
| T-3030216727 | 100 KVA |
| T-4101220409 | 100 KVA |
| T-4151220461 | 100 KVA |
| T-4171220510 | 100 KVA |
| T-4171220531 | 200 KVA |
| T-3150216178 | 100 KVA |
| T-4070621060 | 100 KVA |
| T-4221215879 | 100 KVA |

Both capacities in the new survey therefore have matching reference type names. Reuse their exact type names only with the corresponding library available in the target SynerGEE installation. Transformer identity, feeder/substation name, coordinates, topology and span lengths must come from the new survey/network, rather than the old reference model.

**What this resolves**

The files provide a basis for filling project control values, feeder settings, transformer mode/connection values, construction settings and equipment reference mappings. The current application supports those explicit settings, but this extraction has not registered an approved profile or changed live configuration.

It does not establish that every stored reference value is the desired setting for the new project. In particular, preserve and interpret frequency 60 and the NomKvMode/SpecNomKv combination. Do not classify the files as invalid from those values alone, and do not silently replace them with guessed alternatives. Some fields may be inactive or controlled by library/mode selections.

The MDBs contain instance/reference data, with no populated equipment-definition table holding the full specifications of ANT, WASP, 100 KVA or 200 KVA. To reproduce the analysis behavior, the target SynerGEE equipment library must resolve those names. Also, old customer demand and phase allocations describe old surveyed customers; they do not directly supply the demand values for the new survey. A documented load method remains a separate input.

**Extraction files**

- [reference-profile.json](reference-profile.json): common settings, equipment names and interpretation items, ready for configuration review.
- [transformer-settings.csv](transformer-settings.csv): electrical settings for each reference transformer.
- [field-comparison.csv](field-comparison.csv): every field's distinct values, occurrence counts and source files.
- [exact-values.csv](exact-values.csv) and [exact-values.json](exact-values.json): full saved values with file/table/row provenance.
- [value-comparison.json](value-comparison.json): complete machine-readable comparison.
