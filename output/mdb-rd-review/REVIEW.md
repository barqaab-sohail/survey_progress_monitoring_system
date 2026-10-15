**Independent MDB feasibility review — HAZECO, 12 October 2026**

**Decision: the system can create a genuine MDB with the reference database structure, but the supplied files and current application are not sufficient for an automatically generated, engineering-complete deliverable.** A working R&D MDB is included. It demonstrates schema preservation, surveyed connectivity and coordinates; it is deliberately incomplete for electrical analysis. No live survey was imported, no engineering approval was granted, and all twelve supplied files retained their original SHA-256 hashes.

This review inspected the nine old MDBs independently through Microsoft Access DAO, visually reviewed all fourteen PDF pages, parsed the GPX and KMZ, exercised the application's read-only input parsers, reviewed the current mapping/writer code, and generated/reopened a sample. Instructions or notes inside the documents were treated as source content, not authorization to change the application.

**Follow-up: single-phase allocation method supplied by the user.** Single-phase consumers will be modelled as equal shares across the span's connected R/Y/B phases, retaining fractional equivalent customers when needed. This resolves the allocation-method choice for those consumers; demand magnitudes remain separate. The reference MDBs support fractional shares. The application's current integer-only phase-customer validation needs adaptation before automatically using this method. See [phase-allocation method and demonstration](phase-allocation/PHASE_ALLOCATION_METHOD.md). This addendum records the method; it does not activate live configuration or change the original sample MDB.

**Follow-up: PV reference conventions extracted.** The four generators in three supplied MDBs use equal R/Y/B kW, `DGenOutputPct=100`, `CapacityFactor=1` and zero per-phase kvar. Demand and generation are stored separately against a section ID. These supply a concrete export convention; the files do not retain the original client meaning of “section load” or separately label installed nameplate capacity. See [PV convention extraction](pv-conventions/PV_CONVENTIONS_REPORT.md). Client-provided per-consumer load and installed-capacity values reduce the input gap; generator export development and explicit input interpretation remain.

**What the supplied files contain**

| Input | Observed content | Use and limitation |
|---|---|---|
| Nine reference MDBs | Identical checked schema: 50 tables, 1,065 fields, 133 indexes, 41 relationships. Product metadata: SynerGEE Electric 5.0.0.324. | Suitable structural references. Their operating values are from another project and are not HAZECO engineering specifications. |
| A081026.pdf | 13 handwritten survey pages and a final summary stating five transformers; survey date 8 October 2026. | Transformer headers, S/E connections, phase/conductor marks, equipment/pole observations and consumer counts. PV marks occur, including pages 9, 12 and 13, without a complete corresponding PV electrical specification. |
| Waypoints_2026-10-08.gpx | 120 unique names, exactly 001–120; coordinates and recording times; no tracks or routes. | Supplies new surveyed point locations, not network connectivity or measured conductor routes. The application parser accepts all 120 without warnings. |
| NAWAN SHEHR (000407).kmz | 615 placemarks: 310 point features, 304 line features and one other feature. The application recognizes 140 transformers. Of the lines, 303 are labelled Primary and one Secondary. | Supplies historical transformer identities/coordinates and potentially useful HT geometry/attributes. The current application imports transformer points, not these lines into the MDB network. |

All five PDF transformer codes have exact full-code matches in the KMZ, and all five capacities agree:

| Transformer | PDF pages | Capacity | Historical KMZ coordinate (latitude, longitude) |
|---|---|---:|---|
| T-11131222104 | 1–2 | 100 kVA | 34.16389095, 73.25744164 |
| T-11230617182 | 3–6 | 200 kVA | 34.16531948, 73.25707599 |
| T-11131222107 | 7–10 | 200 kVA | 34.16333960, 73.25798450 |
| T-11030717217 | 11 | 100 kVA | 34.16536959, 73.25997133 |
| T-11030717223 | 12–13 | 200 kVA | 34.16699493, 73.25822399 |

The feeder labels need an explicit alias: PDF “Nawan Shehr”, KMZ filename code `000407`, KMZ attribute “Nshehr”. Preserve the selected registered feeder ID as the authority rather than relying on spelling. The application returns a null substation even though the KMZ point descriptions contain “Abbottabad”: its normalized attribute is `abbottabadsubstation`, which does not resolve through the current `substation` lookup.

The KMZ contains an undeclared `xsi:schemaLocation` prefix. A strict independent XML parse fails until that metadata is removed from an in-memory copy. The application's existing normalization handles it; both its transformer inspection and generation-input KMZ validation passed on this actual file. The original archive was not edited.

**The most important matching rule**

Keep the registered transformer code, complete survey identity (`GGDDMMYYWWW`), short GPX name, selected source file and source date separate. For example, the historical root `11131222104` is not the new survey point `01081026104`.

Joining by the last three digits would place transformer 104 at a point approximately **318.20 m** away and transformer 107 approximately **404.27 m** away. Historical root suffixes 182, 217 and 223 are absent from the new GPX. Nearby new points are useful validation evidence but cannot establish identity alone: the nearest new points to the five KMZ roots are respectively 001, 018, 056, 091 and 101, at approximately 2.92, 0.83, 1.12, 3.92 and 3.81 m.

The current survey identity code preserves distinct complete identities, but its coordinate source still depends on GPX waypoints. Selecting a registered transformer automatically supplies its code; it does not automatically create a survey root from the registry's KMZ coordinates. A reviewed KMZ-to-survey coordinate binding, or an equivalent authoritative historical coordinate source, is needed. This is especially important for 182, 217 and 223. Do not manufacture a GPX recording date or silently bind historical 104/107 to the new same-number points.

**Whether KMZ/KML is mandatory**

| Scope | Answer |
|---|---|
| Creating an MDB container with this schema | Neither KMZ nor KML is inherently required. A compatible clean MDB template and correctly mapped data are sufficient. |
| LT-only model around known transformers | KMZ is replaceable by an authoritative transformer register/coordinate dataset plus approved source-equivalent topology. For these particular attachments it supplies the historical root coordinates missing under the required identities in the new GPX. Its role therefore extends beyond identification alone. |
| Full HT + LT feeder model | HT geometry, connectivity, source nodes and electrical equipment data are required from KMZ/KML or an equivalent source. This KMZ is a useful input, not proof that the resulting feeder model is electrically complete. |
| Current application generation workflow | A KMZ attachment is explicitly mandatory in `GenerationInputService::manifest()`. This is application policy, not an MDB-format requirement. It validates/freezes the attachment but does not translate its lines into MDB topology. |
| Plain KML upload today | The inspected importer opens a ZIP/KMZ archive, and the generation validator requires `.kmz`. Direct standalone KML support would need development. |

All five matched transformers coincide with at least one line endpoint at the precision tested. That is promising for HT integration. There are also 627 adjacent repeated line vertices, which a future converter should normalize. Endpoint coincidence still needs electrical connectivity review, handling of junctions/switching, identification of the actual feeder source and avoidance of duplicating the existing Secondary line with the LT survey.

**Current functionality and outstanding inputs**

| Area | What exists | What remains |
|---|---|---|
| PDF/GPX processing | Background source jobs; GPX parser; PDF readability/page-count check; manual source verification. | The PDF worker does not extract handwritten rows or infer topology. Operators must enter/verify the survey, including continuation pages and ditto marks. OCR automation would be additional development. |
| Transformer selection | Code comes from the selected feeder's registered transformer list. | Bind and freeze the registered root's coordinates/identity with provenance; validate capacity and feeder aliases. Client-provided narrative information can remain optional. |
| LT geometry | Explicit S/E entry, coordinate projection, geometry or approved measured-length override, connectivity checks. | Transcribe all branches; distinguish a reused start point from a new serial span. With this GPX alone, lengths are endpoint estimates, not measured wire length. |
| Conductors and construction | Per-phase conductors and configuration-library references are supported. | Map A/Ant, W/Wasp, GN/Gnat and PVC designations to the client's actual library IDs; confirm neutral, conductor changes, pole-height units, spacing and configuration. Page 11's 092→093 neutral mark `GSL?` and “Half span Gnat” require clarification, potentially span splitting. |
| Transformer/source model | Reviewed source-node/section mappings and transformer library/instance fields are supported. | Approve source topology, supply voltage settings, frequency, HV/LV connections, transformer type, impedance/loss data or a verified library equivalent, and tap settings relevant to the analysis. Capacity alone does not define these. |
| Consumer loads | Consumer records can produce phase customer/kW/kvar/kVA fields using an approved method. | Counts are not demand. Supply phase allocation, category/load assumptions or measurements, power factor/reactive demand, coincidence/diversity and spot/distributed-load placement. Resolve the printed category definitions and ambiguous digits. Blank is not confirmed zero. |
| PV | Survey PV records and provenance are retained and validated. | Current MDB mapping does not populate generator tables. Obtain PV reference, capacity, phase, output assumptions and connection section; add generator mapping or an explicit engineering-approved scope exclusion. A tick without capacity is incomplete. |
| Meter points | Reference schema is preserved. | Current exporter does not populate `InstMeterPoints`; establish whether the client's deliverable requires the reference marker row or actual metering attributes. |
| HT network | KMZ transformer registry import works. | Build a line/junction/equipment converter and reviewed source connectivity if the deliverable includes HT. The current registry importer ignores LineStrings. |
| Template and settings | Physical clean template exists; Windows DAO writer and projection tools work locally. | The inspected local database contains no registered MDB workflow templates and no project engineering configurations. Register/version/approve the template, libraries and project mapping before application export. Production configuration was not inspected. |
| Acceptance | DAO schema/scalar/reference checks, immutable revision gates and later analysis workflow exist. | Open the completed model in the client's target SynerGEE installation with its libraries; run connectivity and electrical analysis checks. This review did not run SynerGEE or a load-flow calculation. |

The reviewed mapper currently writes only `SAI_Control`, `Node`, `InstFeeders`, `InstSection`, `InstPrimaryTransformers` and `Loads`. Adding PV or meter exports requires coordinated changes to the snapshot/mapping, allowed writer tables, insertion ordering and readback validation—not merely adding form fields. Other schema tables can legitimately remain empty when the corresponding devices are outside the agreed model scope.

The current local queue uses the database connection and the Windows export driver. Source jobs use `mdb-sources`, while exports use `mdb`. A centrally managed server worker serves separate operators; operators do not each need a command button. The documented command is `php artisan queue:work --queue=mdb-sources,mdb --tries=3 --timeout=300`, managed as a service with compatible job/retry timeouts. Without a worker consuming the source queue, uploaded files remain unprocessed and the “Both PDF and GPX must finish processing” entry gate remains blocked. Starting a worker does not fill missing engineering values. Worker uptime and production service configuration were not audited here.

**Generated sample and its limits**

Open [HAZECO_T-11030717217_RD-INCOMPLETE.mdb](HAZECO_T-11030717217_RD-INCOMPLETE.mdb) only as an R&D artifact. It uses page 11, the ten new GPX points 091–100 and the exact KMZ coordinate of historical transformer `11030717217`.

Its LT connections are: root→091; 091→092→093; 091→094→095→096→097→098→099→100. Their combined projected endpoint length is **398.13 m**. Coordinates were projected from the supplied WGS84 values into EPSG:32643 (UTM 43N) for this geographic demonstration; project approval of the CRS remains outstanding.

The MDB contains 12 nodes: the historical transformer root, ten survey points and one explicitly artificial co-located supply node. Eleven sections comprise ten surveyed LT spans and one zero-length equivalent transformer link. This link is an R&D modeling device, not a surveyed HT span or reconstructed feeder.

It contains one transformer, one feeder, one control row and ten incomplete load placeholders. The load descriptions preserve the page-11 consumer readings, but all numerical phase customers, kW, kvar and kVA remain NULL. The overwritten small-commercial count at 091 remains unknown. Conductor/configuration library IDs, frequency, impedance and unverified operating values are NULL. The schema-required `TransformerType` is deliberately `R&D-UNMAPPED-100KVA`; it is not a valid approved equipment-library selection. Full source readings and ambiguities are in [sample-transcription.json](sample-transcription.json).

The sample used the application's low-level DAO writer with an explicitly labelled offline R&D payload. It did **not** pass through or bypass a live approved survey revision. No user approval identities were invented. A separate in-memory test confirmed that the normal payload builder rejects missing engineering configuration approval. The resulting file proves binary MDB generation and structural compatibility, not successful end-to-end production generation from the attachments.

**Comparison with the references**

| MDB | Nodes | Sections | Loads | Meter points | DGens / MymDGens |
|---|---:|---:|---:|---:|---:|
| Choper Hatta T-3030216724 | 26 | 24 | 23 | 1 | 0 / 0 |
| Choper Hatta T-3030216727 | 28 | 26 | 25 | 1 | 0 / 0 |
| Jodh Pur T-4101220409 | 29 | 27 | 26 | 1 | 0 / 0 |
| Jodh Pur T-4151220461 | 19 | 17 | 16 | 1 | 1 / 1 |
| Jodh Pur T-4171220510 | 10 | 8 | 7 | 1 | 1 / 1 |
| Jodh Pur T-4171220531 | 23 | 21 | 20 | 1 | 2 / 2 |
| Kot Gohar M T-3150216178 | 23 | 21 | 20 | 1 | 0 / 0 |
| Noor Pur T-4070621060 | 25 | 23 | 22 | 1 | 0 / 0 |
| Noor Pur T-4221215879 | 22 | 20 | 19 | 1 | 0 / 0 |
| New page-11 R&D sample | 12 | 11 | 10 placeholders | 0 | 0 / 0 |

Each file has one feeder, one primary transformer and one control row. Different survey sizes explain the different node/span counts; the new sample's absent meter record and incomplete load/equipment values are substantive completeness differences.

The new MDB and all nine references match the clean template for every inspected table, field, index and relationship property. The writer reopened the output and verified every mapped scalar, row count and model reference. Independent readback found no missing required-field values, missing section endpoints, duplicate undirected edges or isolated sample nodes. The sample is a single connected, acyclic graph. Database-required fields passing does not mean electrical requirements are satisfied.

Several reference observations prevent treating “filled like the old file” as acceptance:

- Every reference declares 60 Hz. This is a reference value requiring project-specific review, not an approved HAZECO default.
- The inspected reference transformer values include `SpecNomKv=4.16` and other legacy settings despite an 11 kV feeder. These need interpretation against the target library, not automatic copying.
- All references contain load rows with positive kVA and zero kW; some distribute fractional customer counts across phases. This is not evidence of the correct new demand-estimation method.
- All nine contain one isolated node named for the historical transformer identity; their energized sections use other IDs. This may be a legacy representation artifact and needs interpretation, rather than automatic reproduction.
- All nine meter rows contain only a SectionId; their other fields are NULL. Three references contain a total of four PV generator rows and four matching multi-year generator rows.

There is no meaningful single “data completeness percentage” across 1,065 schema fields: many tables and optional fields are irrelevant to a particular network. The supplied [field-completeness.csv](field-completeness.csv) instead gives per-field non-null/non-empty counts for the sample and aggregate references. Structural completeness is demonstrated; library, load, PV/meter and engineering completeness remain explicitly separate.

**Development order and completion criteria**

1. Add authoritative registered-transformer coordinate binding with full-identity/date provenance; correct the substation attribute alias and retain a reviewed feeder alias. Accept plain KML if required. Make the KMZ requirement conditional only when an approved equivalent source is supported.
2. Complete verified entry for all five transformer networks. Resolve unclear handwriting, blank categories, continuation/branch rules, units and mixed-conductor spans. Preserve the original PDF location for every value.
3. Obtain the client's target SynerGEE version and actual equipment libraries; register the clean template and approve project CRS, frequency, voltages, source mapping, transformer settings and phase-demand method. Do not use another project's model values as undocumented defaults.
4. Add meter/PV table mappings where required by the agreed deliverable. For full-feeder output, also implement HT line/junction/device integration and retain full geometry or approved lengths.
5. Run the normal approved workflow on these inputs, reopen the resulting MDB, compare expected device/span/load counts, and perform target-SynerGEE analysis. Only then classify the output as ready for client delivery.

**Evidence and reproducibility**

- [comparison-summary.json](comparison-summary.json): schema, graph and unchanged-original checks.
- [table-count-comparison.csv](table-count-comparison.csv), [field-completeness.csv](field-completeness.csv), [relationships.csv](relationships.csv): complete structural/data comparison.
- [transformer-matches.json](transformer-matches.json), [ht-geometry-audit.json](ht-geometry-audit.json): identity, coordinate and line observations.
- [application-probe.json](application-probe.json): actual parser/gate/runtime checks; local configuration state only.
- [sample-input.json](sample-input.json), [sample-transcription.json](sample-transcription.json), [sample-sections.csv](sample-sections.csv), [writer-result.json](writer-result.json): exact sample inputs, assumptions and readback result.
- `*.inspection.json`: read-only DAO exports of every reference, template and sample. `source-manifest.json` records the twelve original hashes.
- Reproduction scripts: `inspect-sources.py`, `inspect-mdb.ps1`, `application-probe.php`, `build-rd-sample.py`, `audit-comparison.py`. Run from the repository root; use 32-bit Windows PowerShell for DAO and a new output MDB path when invoking `scripts/mdb/export-reviewed-mdb.ps1`.

Code evidence: [ExportPayloadBuilder](../../app/Services/Mdb/ExportPayloadBuilder.php), [GenerationInputService](../../app/Services/Mdb/GenerationInputService.php), [TransformerKmzImportService](../../app/Services/TransformerKmzImportService.php), [SurveyWaypoint](../../app/Services/Mdb/SurveyWaypoint.php), [NetworkValidator](../../app/Services/Mdb/NetworkValidator.php), [ProcessSourceFile](../../app/Jobs/Mdb/ProcessSourceFile.php), [PDF checker](../../scripts/mdb-workflow/inspect_pdf.py), and [DAO writer](../../scripts/mdb/export-reviewed-mdb.ps1).

Schema comparison covers table names; field order/name/type/size/required/default/validation/attributes and zero-length settings; index names/primary/unique/field names; and relationship names/tables/attributes/field mappings. It does not establish equivalence of Access UI objects, queries, every obscure table property, external equipment libraries or SynerGEE analysis behavior. The complete handwritten survey was reviewed for feasibility; only page 11 was transcribed into the sample. No application implementation changes were made as part of this R&D review.
