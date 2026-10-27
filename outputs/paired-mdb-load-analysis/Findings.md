# Paired data-entry to SynerGEE mapping analysis

Inspected 27 October 2026. Both MDBs were opened read-only with DAO; source SHA-256 hashes were unchanged. No saved Access queries/macros/modules, native SynerGEE import, or load flow was executed. This is an observed conversion reconstruction, not a departmental engineering standard.

## Conclusion

The current application does not reproduce the Loads layout and demand/allocation method in this matched approved example. All 29 S/E pairs were linked to approved sections, and all 29 section customer totals and active-phase allocations reconcile. A section-by-section CSV is attached.

| Evidence | Data-entry MDB | Approved SynerGEE MDB |
|---|---|---|
| Survey records | 58 Field_Data rows, 29 S/E pairs | 29 survey sections |
| Ordinary consumers | RS_Con1 total 241; other ordinary categories zero | 241 ordinary customers included in Loads |
| PV consumers | PV_Con total 1 | One additional customer included in Loads; one PV generator |
| Total consumers | 241 ordinary + 1 PV | 242 |
| Survey load rows | Per-row category counts | One combined Loads row per survey section |
| Ordinary demand | No general rate column in the source | 3 kVA per RS_Con1 customer in this sample |
| Ordinary apparent demand | Derived from counts | 241 x 3 = 723 kVA |
| Extra demand on PV section | Sanction Load 7; PV_Size 5 | 5.6 kVA beyond ordinary customer demand |
| Total apparent demand | Reconstructed | 723 + 5.6 = 728.6 kVA |
| PV output | PV_Size 5 | 5 kW, stored separately in InstMymDGens |
| Meter points | AMI_Meter_No empty on every row | One transformer-secondary section association, no readings |

## Exact joins and section reconstruction

Field_Data.Section_ID is NULL in this sample, so it is not the join key. Field_Data.ID1 is a source row key, not a SynerGEE SectionId. All 58 Sequence values are zero, so Sequence is not useful for ordering these rows.

Ordering the supplied records by ID1 yields 29 consecutive S/E pairs. Every E-row GPS_NO matches a survey InstSection.ToNodeId. Every survey SectionId is the text concatenation of Feeder_Code and the E-row GPS_NO in this example:

- Feeder_Code 10333 + end GPS_NO 1080825010 -> SectionId 103331080825010.
- Loads.SectionId -> InstSection.SectionId.
- InstDGens.SectionId -> the same survey section; InstMymDGens uses SectionId plus YearNumber.
- InstMeterPoints.SectionId -> a separate source/secondary link, described below.
- FromNodeId and ToNodeId -> Node.NodeId.

Preserve numbers as text identifiers during joins. The application may use its own stable identifiers if it applies them consistently to sections, loads, PV, and meters; matching the reference's literal ID strings is not necessary.

Customer values are entered on E rows in this file; every S row has zero consumer counts. This example therefore establishes E-row attribution only. It does not prove how a converter handles a different survey with positive counts on both S and E rows.

Most start nodes match the S-row GPS_NO. Exceptions are pair 1 (the transformer start becomes 8160421145.2) and pairs 2 and 24 (their source GPS 1080825001 is also replaced with 8160421145.2). These appear to be converter topology adjustments; endpoint GPS text alone does not explain why they were made. Do not copy these special substitutions into all surveys.

GPS_Points contains no rows and Field_Data x/y are NULL, so this MDB alone does not supply the projected coordinates. Separate coordinate inputs are needed to reconstruct the network geometry. The neighbouring GPX/text files were not used to derive this report's numerical load rules.

## One load per section and equal phase division

The approved file has 31 sections: 29 survey sections plus two source/transformer links. It has 30 Loads rows: one for each survey section plus one zero-load row for the secondary/meter link. The primary transformer link has no Loads row.

Each approved survey Loads row combines its customer categories and PV customer count. For this sample, only RS_Con1 and PV_Con are nonzero. Counts and kVA are split equally across the connected phases, allowing fractional phase customer values. Inactive phase customer/kVA values are NULL; active phases of zero-load sections contain zero.

Examples:

| End GPS | Source counts | Section phases | Approved phase customer values | Approved phase kVA |
|---|---|---|---|---|
| 1080825002 | 10 RS_Con1 | RYB | 3.333333 / 3.333333 / 3.333333 | 10 / 10 / 10 |
| 1080825014 | 14 RS_Con1 | B only | NULL / NULL / 14 | NULL / NULL / 42 |
| 1080825016 | 5 RS_Con1 | RY | 2.5 / 2.5 / NULL | 7.5 / 7.5 / NULL |
| 1080825010 | 2 RS_Con1 + 1 PV | RYB | 1 / 1 / 1 | 3.866667 / 3.866667 / 3.866667 |

The current application instead creates a Loads row for each category, balances single-phase customers in whole numbers, and applies 2 kVA per single-phase consumer and 5 kVA per three-phase consumer. These are different policies. The existing 74-customer/148-kVA result is consistent with the application's configured method; matching this approved conversion would require deliberate changes to aggregation, phase allocation, and the demand method. This sample has no nonzero three-phase ordinary categories, so it does not establish their rate.

## PV is both consumption and generation

Field_Data row ID1 1770, end GPS 1080825010, records two RS_Con1 consumers, one PV consumer, PV_Size 5, and Sanction Load 7. Its reference and supply fields are retained in the read-only extract.

The linked approved section has three customers and 11.6 kVA total consumption: 6 kVA for the two ordinary customers plus 5.6 kVA attributed by reconciliation to the PV customer. The same section also has a PVGEN record and YearNumber 0 generation of 1.6666666667 kW per phase, totalling 5 kW. Consumption is not removed just because the customer has solar.

The residual 5.6 equals 7 x 0.8 arithmetically. This single observation does not establish the sanctioned-load unit, conversion formula, power-factor assumption, or a universal PV demand rule. Confirm the converter/department formula before implementing it. Do not silently treat 7 as kW or apply a 0.8 factor to all PV consumers. Do not subtract generation in kW directly from load in kVA.

Current code retains PV observations and supports explicit generator mappings, but ExportPayloadBuilder.load processes ordinary section consumers; it does not automatically add a PV customer's count and service demand to Loads. SupplementalMapping also requires explicit generator/years mappings. These are separate export responsibilities that must both be addressed to match this example, while avoiding double counting customers already represented in an ordinary category.

## Meter placement and meaning

Observed source topology:

Feeder node Kot Madina -> [section 103338160421145.1, transformer installed here] -> node 8160421145.1 -> [section 103338160421145.2, meter associated here] -> node 8160421145.2 -> survey branches.

The approved file contains exactly one InstMeterPoints row with SectionId 103338160421145.2. That section is downstream of the transformer section and upstream of the survey branches. It also has one zero-load Loads row. This is a transformer-secondary/source association in the model, not one record per customer or PV consumer.

Only the meter's SectionId is populated. Type, Enabled, UseDemandAsLoad, phase readings, and other settings are NULL, and InstMymMeter is empty. It therefore establishes the intended section association, not measured demand, operational meter behaviour, losses, billing, or calibration settings. Field_Data.AMI_Meter_No is NULL throughout this sample; no individual AMI identity can be inferred.

The application already supports an explicit meter_points mapping to either a source_section or survey_section_id. To reproduce this reference's layout, identify a separate transformer-secondary link in the application's model and map the meter to that link. The current Simple model has only a logical transformer link, not the same two-link source layout. Do not attach a meter arbitrarily to a load section or invent its measurement fields. Review whether a secondary meter link should be created and what settings/readings, if any, should be supplied.

## Recommended application changes, based on the observed differences

1. Aggregate category demand into one Loads row per section and load kind, preserving category detail in provenance; distinguish spot and distributed loads if both exist.
2. Make equal fractional phase allocation an explicit reference-compatible policy rather than changing surveyed counts.
3. Keep the customer demand method configurable and documented. This sample demonstrates 3 kVA per ordinary single-phase RS consumer, but it does not authorize replacing the existing department-selected 2/5 kVA policy globally.
4. Represent PV customers' consumption/counts in Loads and their generation in InstDGens/InstMymDGens, with explicit units, method, and duplicate-customer handling.
5. Model the meter on the intended transformer-secondary section. Preserve unrecorded readings as NULL; distinguish association-only meter rows from measured meters.
6. Add a regression fixture from this paired sample: reconcile 29 survey load rows, 242 customers, 728.6 kVA under the confirmed reference formula, one 5-kW generator, and one secondary meter association. The unexplained PV demand formula and special start-node substitutions need confirmation first.

No application code, configuration, source survey, or approved MDB was changed during this analysis.

## Source hashes

- approved_data_entry.mdb: `e0c9922812e2c88a06d8b525e42d63105186c1fdddf791c3d6ffe75f9a709731`
- valid_synergee.mdb: `1065f140ba2c964c1fb30d4e0e73aa581db03913b5e0ca90f3b1bae5c731bf3a`
