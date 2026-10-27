# Application improvements from three approved MDB pairs

All six supplied MDBs were inspected read-only. Their SHA-256 hashes were unchanged. The paired data-entry and SynerGEE structures were reconciled section by section.

## Observed rules

- 65 consecutive S/E pairs match 65 approved survey sections when the data-entry rows are sorted by ID1. Physical Access table order differs in some files and must not be used as survey order.
- SectionId is Feeder_Code concatenated with the E-row GPS_NO in all three examples; source Section_ID fields are not needed for this join.
- One combined Loads row exists on each survey section, including sections with zero customers. Each file has one additional zero-load row on the transformer-secondary meter link.
- The approved converters divide customer allocations and load demand equally across connected phases, including fractional customer allocations.
- All three files have exactly one InstMeterPoints association on a separate link directly downstream of the transformer. Every other meter field is NULL.
- None of these three pairs contains PV records. They do not resolve the PV sanctioned-load conversion uncertainty from the earlier paired example.
- The references use 3 kVA per ordinary single-phase customer and 5 kVA per three-phase customer. As explicitly requested, the application retains **2 kVA and 5 kVA**, respectively.

## Pair totals with the retained application rates

| Transformer | Survey sections | Single-phase customers | Three-phase customers | Approved reference kVA | Application kVA at 2/5 |
|---|---:|---:|---:|---:|---:|
| 2211117034 | 20 | 36 | 0 | 108 | 72 |
| 2301017107 | 23 | 103 | 10 | 359 | 256 |
| 8160421110 | 22 | 148 | 0 | 444 | 296 |

## Implemented changes

1. Category-based demand now divides the total customer count and kVA equally across connected phases. Original survey counts remain integer observations. Manual measured phase inputs remain explicit.
2. ExportPayloadBuilder combines category load rows on each section into one Loads row. It preserves NULL if any contributing category has an unknown quantity, preventing partial demand from being presented as complete. Categories, survey counts, and demand evidence remain in load_provenance.
3. Simple v2 generation with automatic source topology now creates separate logical transformer and secondary links. A meter association is added on the secondary link and a zero-local-load row is added for that logical link. No physical span, meter reading, enabled status, type, calibration setting, or voltage is invented. Logical links remain zero length at the verified root coordinate.
4. Explicit reviewed source/device mappings are retained, including explicitly supplied meter fields. The automatic meter association is only created when no meter mapping is present.
5. Network validation accepts finite fractional phase allocations while still requiring nonnegative integer survey counts and reconciliation of phase sums.
6. Completeness review now lists recorded PV as incomplete when generator association or base-year active-phase output is missing. PV customer consumption still requires explicit approved load inputs; no unverified sanctioned-load formula is introduced.
7. The export cache identity includes an exporter revision, so old retained jobs are not reused for this changed export behavior.
8. Engineering and offline preview guidance explain combined section loads, fractional model allocations, meter associations, and separate PV consumption/generation.

## Verification

- A regression fixture extracted from the three paired sources covers all 65 survey sections, their phase combinations, customer totals, and category composition. Tests compare approved phase customer allocations while recalculating demand with the retained 2/5 kVA rates.
- Regression checks cover unknown-demand propagation, meter-link placement, preservation of NULL readings, fractional allocation validation, and missing PV mapping.
- The existing revision 91 JSON was converted into a separate validation MDB through the actual Windows DAO exporter and automatic read-back verification. The output has 50 tables, 41 relationships, 15 nodes, 14 sections, 13 combined Loads rows, one meter association, 74 customers and 148 kVA. Its original uploaded snapshot and the original MDB were unchanged.
- Revision 91 retains its original frozen phase allocations; a fresh verified JSON is required to pick up the new equal-allocation demand method. The validation MDB tests the updated writer and does not substitute for a newly verified production export.

Native SynerGEE import and load flow were not tested. Electrical library references and omitted settings remain subject to the existing engineering review.

## Deployment

Push/pull the code to production and keep the local converter updated. Review and save the category demand configuration, then verify and approve a fresh survey revision and download new JSON. No source MDBs or production settings were modified by this work.

Detailed joins: Section-Links.csv. Source read-only extracts and manifest.json are retained in this directory. Normalized regression evidence is stored in tests/Fixtures/approved-section-load-pairs.json.

## Test outcome

The focused unit, engineering, export, converter, builder, and data-entry export suites passed (222 tests). The wider operator-entry suite has 17 failures and one risky test already present with the unchanged HEAD implementations of all modified service classes. Re-running the same operator suite against this change produces the identical 17 failures with no newly failing cases. Baseline and current JUnit evidence are retained alongside this report.
