**Agreed R&D phase-allocation method: equal shares for single-phase consumers**

The user specified that single-phase consumers are divided equally across phases. This resolves the choice of estimation rule for single-phase customer allocation. Use the phases actually connected on the span: RYB gives thirds; a two-phase span gives halves; a one-phase span carries the whole count. Neutral is not a load phase.

| Single-phase consumers | Connected phases | R equivalent customers | Y equivalent customers | B equivalent customers |
|---:|---|---:|---:|---:|
| 6 | RYB | 2 | 2 | 2 |
| 10 | RYB | 10/3 | 10/3 | 10/3 |
| 5 | YB | absent | 2.5 | 2.5 |
| 4 | B | absent | absent | 4 |

These are equivalent model shares, not fractional actual persons or fabricated observed connections. Keep the surveyed integer category total. Avoid rounding thirds to whole customers or allocating loads to a missing phase. Categories known as single-phase in the current survey legend are RS, SC and SI. This rule does not relabel RL or LI three-phase consumers or guess the phase classification of LC/PB/AG/ST.

The nine valid reference MDBs contain 80 load rows with fractional phase customer counts, including 69 rows with equal fractional values in all three phase fields. This supports the compatibility of equivalent shares with the reference format. It does not establish a demand per customer or the meaning of every historical load row.

**Demonstration on PDF page 11**

`page11-customer-allocation.csv` and `.json` apply the method to the existing R&D transcription. For example, five SC consumers at WP092 receive 5/3 per phase, four RS consumers at the blue-only WP093 receive four on B, and three RS consumers at the YB WP099 receive 1.5 on Y and 1.5 on B. The overwritten SC count at WP091 stays unknown. LC stays unresolved because its phase classification has not been established. No kW/kvar/kVA or unknown category zeros were invented. These readings still require the normal source-row verification when entered into the application.

**Effect on the feasibility review**

The single-phase allocation rule is now available as a user-specified method; actual phase-by-phase observations are no longer a prerequisite for this estimated model. A category demand/estimation method is still needed to establish total kW/kvar/kVA. Once a category total demand is established, it can be divided using these same shares.

MDB phase-customer fields are floating-point fields, and the reviewed exporter already accepts nonnegative numeric shares and checks their sum within a tolerance. However, `NetworkValidator` currently enforces integer per-phase customers, and the demand-entry control has `step=1`. Automatic application support must generate the fractional shares, relax that restriction for the documented estimation method, and preserve the original integer category counts and provenance. An export write succeeding does not bypass the application validation requirement.

This update records and demonstrates the method in the R&D review. It does not change live project configuration, approve a demand record, or update the previously generated incomplete MDB. That MDB retains its original documented scope and NULL phase-demand values.

Files: [method definition](equal-phase-method.json), [page-11 allocation](page11-customer-allocation.csv), [reference evidence](reference-allocation-evidence.json).
