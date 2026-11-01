# Loads generation from final InstSection records

The historical Loads-only comparison below predates the subsequent [InstMymLoads coverage update](INSTMYMLOADS_GENERATION.md), which now shares these allocations before Loads applies its blank-to-zero defaults.

`LoadsTableGeneration` runs in the existing normal export after Node/InstSection mapping and after the existing InstMymLoads calculation. It replaces only `tables.Loads`. The export revision is `loads-section-coverage-v1`, so an earlier 13-row export is not reused from the export cache. No schema, source observations, phase-specific endpoints, electrical settings, or other table records are changed.

## Source scope and workbook reconciliation

The supplied `Loads.xlsx` has 166 rows and 166 distinct text SectionIds, all using feeder code 000407 / group 01, with E waypoints 001–017. Its transformer example also uses the older date/identifier example. `Loads_app_generated_table_1.xlsx` has 13 rows for group 08, waypoints 027–038: 12 distinct IDs and a duplicate `00040708081026027`. None of the reference's 166 IDs matches this case's section IDs. These are different survey scopes, rather than a missing set of 76 reference records from this assignment.

The checksummed batch-17 / revision-101 package for transformer 11140223003 contains 12 survey spans. The existing normal mapper generates 91 sections: one feeder, one transformer, 12 pole spans, and 77 consumer/PV connections. The feeder section is `00040711140223003`, identified by its actual InstFeeders/FromNodeId relationship. Excluding only that section leaves 90 eligible Loads rows. Counts and identifiers are derived from each export's records; these example values are not constants in the mapper.

The previous mapper produced survey-span Loads before consumer/PV sections were expanded. It then added the automatic meter association's zero load to the first span again. Consequently it emitted 13 rows, including one duplicate, while omitting the transformer and connection rows.

## Agreed value rules

The user explicitly retained the assignment's source demand rates and unknown blanks, rather than the reference's uniform 3 kVA/customer and zero kW/kvar/kWh. The reference supplies the 20-column order, complete section coverage, blank Description, and pole-aggregate / individual-connection row pattern.

- Every final eligible section gets exactly one Loads row. Exact stored SectionIds are reused as text, including leading zeros and suffixes such as `.1` and `.10`. No IDs are generated or inferred from workbook IDs.
- Generated customer rows reuse `ConsumerPhaseConnections`, the same existing plan that generated their Node and InstSection relationships. Whole single-phase counts follow the existing available-conductor rotation/recorded-subset rules. Three-phase customers reuse the existing three-connection expansion, one customer connection per phase.
- Pole aggregate counts are the sum of their associated consumer connections. For the current 14 customers at E029 this is R=5, Y=5, B=4, with kVA 10/10/8 at the source's 2 kVA/customer rate.
- The approved source demand total and its implied rate are preserved. Historic fractional equal allocations are apportioned in whole customer units. Category-rate kVA is apportioned at the source rate's decimal precision, with largest remainders assigned in stable R/Y/B order. For a three-phase consumer whose source rate is 5 kVA total, the three phase connections receive 2/2/1 kVA, preserving 5 total without recurring thirds. Fractional rates remain fractional rates; they are not replaced by the reference's sample rate.
- Explicit measured phase demand keeps its source allocation and precision. Known phase totals are distributed over the associated connections with a reconciled total. Unknown kW/kvar/kWh stay NULL, including the current assignment's values. An explicitly mapped kWh value remains a mapped value. Legacy missing inactive-phase values remain NULL; absent phase evidence in incomplete simple models does not invent an allocation.
- Source-only/transformer sections with no consumption have zero customer counts and zero kVA. PV-only connection sections likewise get rows with zero consumption customers and zero kVA; no load rate or consumption is invented from installed generator capacity. Generator records are unchanged.
- Existing IsSpotLoad, CapacityFactor, and location settings are retained. The reference's 0 / 1 / C values apply when those settings are absent. Description is blank.

Pole aggregates and individual connection rows both represent local demand, as in the reference. They must be reconciled separately; summing both layers counts the same consumers twice. This case has 74 source consumers and 148 kVA on the pole layer, and the same 74 consumers / 148 kVA on the individual consumer layer. Three additional PV-only connection rows carry zero consumption.

## Verification

Disposable before/after MDB exports were produced through WindowsExporter and the normal DAO writer from the same unchanged source package. DAO reopened them and verified every mapped field and reference. Physical inspection confirmed:

- 90 Loads rows, 90 unique eligible IDs, no missing or unexpected IDs, and only the feeder section excluded;
- all 20 Loads columns match the reference order and retain the approved MDB types;
- no fractional customer counts or recurring fractional kVA in this case; source 2 kVA rate and unknown blanks preserved;
- every pole's phase totals reconcile to its individual consumer rows and to the source totals;
- all 49 other physical table contents are identical, including the existing 24 InstMymLoads rows;
- all 50 user-table definitions, indexes, and 41 relationships are unchanged; the approved template hash is unchanged.

The verification report and disposable MDBs are in `tmp/loads-coverage-review/`. The original workbooks, JSON package, PDF/GPX sources, and user MDBs were read without modification. The 166-vs-90 workbook difference is reconciled by scope; there is no remaining row-selection decision for this case.

The final focused export regression run passed **103 tests with 2,486 assertions**, including source allocation/precision, complete section coverage, recorded conductor subsets, three-phase expansion, zero-consumption PV rows, offline generation, export-cache invalidation, and native MDB writes/readbacks.
