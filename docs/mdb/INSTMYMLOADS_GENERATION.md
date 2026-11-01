# Annual Loads generation

The normal export generates InstMymLoads from the same final section coverage and whole-customer allocations used by Loads. Following the updated output requirement, null, empty, or whitespace-only numeric values become zero, including customer counts, demand fields, and an explicitly blank CapacityFactor. Nonblank source values, saved demand rates, exact text SectionIds, and IsSpotLoad are retained. An omitted CapacityFactor retains its existing default of 1.

Each eligible section receives YearNumber 0 and 1, following the existing annual pattern. Individual phase-specific connections retain their values in both years; aggregate sections retain the existing zero-known-values pattern in Year 0 and source values in Year 1. Blank numeric values become zero in both years.

Generation enforces uniqueness by SectionId + YearNumber. Identical duplicate source rows are deduplicated without summing their demand. Conflicting records for a SectionId and fractional customer counts are rejected. The existing 19-column MDB schema, physical key (SectionId, IsSpotLoad, YearNumber), and relationships are unchanged. Export revision `instmymloads-zero-defaults-v3` prevents reuse of older cached exports.

For the verified transformer 11140223003 / batch-17 / revision-101 scope, the previous 24 annual rows become 180 rows: 90 eligible section IDs, each with Years 0 and 1. There are no missing or duplicate pairs and no fractional customer counts. All 19 columns are populated, and the update replaces only previously blank numeric values with zero.

Disposable before/after MDB exports were reopened and all mapped values verified. The zero-default update replaced 1,620 blank cells with zero across 180 rows, with no remaining blanks or duplicate pairs. All nonblank values, the 49 other physical tables, and the existing schema and relationships stayed identical. The focused suite passed 72 tests and 4,377 assertions. Local verification evidence for zero defaults is under `tmp/instmymloads-zero-review/`; the earlier section-coverage review is under `tmp/instmymloads-coverage-review/`.
