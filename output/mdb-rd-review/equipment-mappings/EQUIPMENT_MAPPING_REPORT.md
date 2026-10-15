**Equipment mappings from the survey PDF, GPX and valid MDB references**

Equipment assignments can be extracted from the PDF and attached to the GPX coordinates. Exact MDB equipment-reference names come from the valid reference MDBs. These three sources have different roles: the PDF identifies surveyed equipment/materials and phases; the GPX locates the waypoint; the MDBs provide observed target-library names. The GPX does not provide conductor ratings, transformer parameters, pole construction definitions or equipment-library IDs.

The actual GPX has 120 waypoints with latitude/longitude, elevation, time, name, comment and symbol. Its comments are timestamp text and its symbols are generic waypoint symbols. It contains no equipment definitions. All original file hashes were verified before this extraction; no original or live application configuration was changed.

| Survey label | Meaning from source | Candidate MDB reference | Evidence/status |
|---|---|---|---|
| A | Ant | ANT | PDF legend + reference MDB names |
| W | Wasp | WASP | PDF legend + reference MDB names |
| GN | Gnat | GNAT | PDF legend + reference MDB names; exact per-column IDs need explicit library matching |
| 100 kVA | Transformer capacity | 100 KVA | PDF header + valid reference TransformerType |
| 200 kVA | Transformer capacity | 200 KVA | PDF header + valid reference TransformerType |
| ABC 50 mm2 | Cable option printed in legend | ABC 50 MM2 | Reference-backed legend option; no installed occurrence asserted |
| PVC variants | Insulated conductor designation | Unresolved | No PVC reference name in the supplied MDBs |
| GSL? | Unclear neutral mark on page 11 | Unresolved | Handwriting and mixed-conductor remark require review |

These are reference-backed candidate names, not new electrical specifications. Capacity alone does not establish that one transformer make has the same impedance/loss characteristics as another with the same rating. The target SynerGEE library must resolve the selected names.

**Construction and equipment observations**

The printed pole-class legend defines S=Steel Structure, PCO=PC Ordinary, PCS=PC Spun, RS=Rail Steel, TS=Tubular Steel and WB=Wall Bracket. Preserve these observations in pole_class and distinguish them from the raw Type codes TR and SP. Pole-height readings can also be preserved, with their unit established at project level.

The valid reference sections all use `12.5/7.2 kV cross arm C2-2` as their `ConfigurationId`. That provides a reusable construction candidate, but a pole class/height alone does not prove a matching cross-arm arrangement. Do not convert raw pole height directly into the MDB average-height field without the relevant unit and construction rules.

The full crosswalk distinguishes legend options from observed handwritten material marks. DOG is present in the reference inventory but is not asserted as an installed conductor in this PDF. ABC 95 and PVC legend entries have no target match in the supplied MDBs. Pages 7–8 have a handwritten PVC designation that appears to read `37/0.083`; keep that reading uncertain rather than silently replacing it with a different conductor. The page-11 092→093 neutral mark and “Half span Gnat” remark need a separate resolution.

**How to use this in the application**

1. Read/verify each PDF S/E pair and assign its complete survey identity and selected GPX source. Keep historical transformer-root identities separate from new same-number waypoint labels.
2. Save conductor marks per R/Y/B/neutral position, construction/pole observations and the transformer header. Apply ditto marks only within their verified span/branch context.
3. Use the extracted crosswalk to select ANT/WASP/GNAT and 100 KVA/200 KVA reference types. Preserve the exact target strings; some reference phase-Y conductor IDs have leading spaces. Select a matching construction ID explicitly.
4. Bind each point to the GPX by name/source/date. The supplied examples demonstrate new survey points, not a proposed rebinding of historical roots.
5. Leave unknown active-phase materials unresolved. A dash for a confirmed absent phase is different from a blank/unclear conductor on a connected phase. Add exact missing PVC library references and resolve ambiguous marks before final export.

The application already stores the relevant survey observations and accepts explicit equipment IDs in export mapping. This extraction does not implement or activate an automatic crosswalk in the application. It provides the concrete mapping data and identifies the exceptions that such an implementation must handle.

**Files**

- [equipment-crosswalk.csv](equipment-crosswalk.csv) and [equipment-crosswalk.json](equipment-crosswalk.json): equipment vocabulary, target candidates, provenance and unresolved items.
- [gpx-waypoints.csv](gpx-waypoints.csv): all 120 coordinates and source point metadata.
- [example-pdf-gpx-bindings.csv](example-pdf-gpx-bindings.csv) and [example-pdf-gpx-bindings.json](example-pdf-gpx-bindings.json): six visually read examples with equipment observations and actual GPX coordinates.
- [gpx-field-inventory.json](gpx-field-inventory.json): confirms the actual GPX's coordinate/metadata fields and absence of equipment definitions.

This is a crosswalk extraction, not a complete transcription of all thirteen survey pages into finalized MDB sections. The PDF worker currently requires manual handwritten verification.
