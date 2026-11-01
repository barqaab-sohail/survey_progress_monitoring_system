# SAI_Control generation

The normal MDB exporter and transformer MDB builder write the static literal row transcribed by header name from `C:/Users/sohai/OneDrive/Desktop/HAZECO/Tables/SAI_Control.xlsx`. The workbook has one value row and no formulas. Its 22 values are emitted in the MDB schema order. In particular, Product is `Synergi Electric 6.29.0`, MYM_YearDesc_B through MYM_YearDesc10 contain text years 2015 through 2025, and blank ProjectionWKT remains null. No values are inferred from survey date, selected template version, or per-project projection settings for this table.

The fixed source row changes only SAI_Control values; the existing MDB schema and other table mappings remain as before. Export revision `sai-control-reference-v1` prevents reuse of an export cached before this mapping.
