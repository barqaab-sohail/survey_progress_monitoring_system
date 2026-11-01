# InstMymMeter generation

The normal MDB export writes two InstMymMeter rows for each transformer. Each SectionId is taken from the corresponding InstPrimaryTransformers record and checked against the current InstSection row; the workbook's sample identifier is never copied. The rows use YearNumber 0 and 1.

The other 13 fields are literal named values from `InstMymMeter.xlsx`, including the values and zeros. Both rows match except for YearNumber. The existing 15-column MDB schema and relationships remain unchanged. Export revision `instmymmeter-years-v1` prevents reuse of an export cached before these rows were generated.

The disposable MDB check generated exactly two rows for SectionId `00040711140223003.1`, whose InstSection Description is `Transformer`. All 15 columns matched the workbook pattern, neither row had blanks, and all 49 other physical tables were unchanged. The focused regression suite passed 74 tests; two environment dependent tests were skipped.
