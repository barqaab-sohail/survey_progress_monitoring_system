# InstMeterPoints generation

The normal export uses the literal row in `InstMeterPoints.xlsx` for every generated transformer record. Each row takes its exact SectionId from the corresponding InstPrimaryTransformers relationship and verifies that the referenced InstSection row is labeled `Transformer` when that description is present. Some supported simple exports omit the optional InstSection Description, so the primary-transformer relationship identifies the section in those records. SectionId is always derived from the current network; the workbook's sample identifier is never copied.

All remaining 33 fields are copied as named static values from the reference row, including zeros, text, and nulls. The existing MDB column order and relationships are unchanged. Export revision `instmeterpoints-reference-v1` prevents reuse of an export cached before this mapping.
