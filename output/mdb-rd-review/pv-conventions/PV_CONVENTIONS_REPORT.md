**PV conventions recovered from the nine valid MDBs**

Three files contain four PV generators. All four link a generator and a load to the same InstSection through SectionId. Their stored generator conventions are consistent: equal R/Y/B kW on RYBN spans, output percentage 100, CapacityFactor 1, YearNumber 0 and zero kvar in each phase. The original MDBs were hash-checked and remain unchanged.

| Reference MDB | Section | Total stored PV generation kW | Each phase kW | Linked section load kVA |
|---|---|---:|---:|---:|
| Jodh Pur T-4151220461 | 1059082230825566 | 8.96 | 2.9866666667 | 11 |
| Jodh Pur T-4171220510 | 1059082230825622 | 5.65 | 1.8833333333 | 70 |
| Jodh Pur T-4171220531 | 1059082230825609 | 7.02 | 2.34 | 7 |
| Jodh Pur T-4171220531 | 1059082230825611 | 7.54 | 2.5133333333 | 55.8 |

These generation totals are the sum of stored phase kW, not separately identified installed nameplate capacities. All linked load rows have zero phase kW/kvar and positive kVA. The kVA column above must not be relabelled as client-provided kW or treated as a new measured demand.

**Answers to the three details**

1. **Load meaning: only the representation can be recovered.** Demand is stored in Loads, generation in InstDGens/InstMymDGens, and both reference the same SectionId. The load descriptions are null and do not retain the original consumer reference or client field wording. Therefore these files do not establish whether original “section load” was one consumer's load, all consumers on the span, gross demand, net import or a sanctioned rating. The user's new inputs are supplied per PV consumer; retain that provenance and clarify their precise demand meaning without duplicating a section total.
2. **PV allocation: the saved balanced model can be recovered.** All four generators have identical R, Y and B kW. This supplies a reference convention for reproducing balanced generation on an RYB-connected span. It does not prove actual inverter connection phases and does not establish a rule for a B-only or YB span; allocate only to connected phases under an explicit method for such cases.
3. **Generation assumptions: the saved settings can be recovered.** DGenOutputPct=100, CapacityFactor=1, YearNumber=0 and per-phase kvar=0. Positive kW with zero kvar corresponds to unity power factor in the stored P/Q values (an inference from the numbers, not an explicit PF field). DGenVoltSet=120 is also saved in every generator row; preserve its exact raw value and mode meaning rather than treating it as a new feeder voltage. The original time profile or observed generation is not recorded. The combination gives a reference scenario to reproduce, but does not prove nameplate capacity equals recorded model output.

**How this reduces the PV gap**

These files provide a concrete generator-table pattern, balanced allocation convention and saved output/reactive settings. The client-provided demand and installed-capacity values can populate the new records under an agreed interpretation of the two inputs and a stated capacity-to-model-output rule. Demand and PV generation should remain separate. The software exporter still needs generator mapping and verification support; no live configuration or MDB export code was changed by this extraction.

InstDGens has a primary key on SectionId; InstMymDGens has a primary key on SectionId and YearNumber. Multiple PV consumers on one section cannot be emitted as duplicate rows under those keys. Preserve per-consumer source inputs and either aggregate their modelled generation per section/year or use distinct, reviewed connection sections. Avoid double-counting demand already included in the section's Loads row.

Files: [PV comparison](pv-comparison.csv), [exact source records](reference-pv-records.json), [extracted PV profile](pv-reference-profile.json).
