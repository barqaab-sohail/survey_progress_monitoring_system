"""Build source evidence and a review worksheet; never write application settings."""
import json
import math
from collections import Counter
from pathlib import Path

import pyproj
from pyproj import CRS, Transformer

ROOT = Path(__file__).resolve().parent
documents = [json.loads(p.read_text(encoding="utf-8-sig")) for p in sorted(ROOT.glob("*.inspection.json"))]
analysis = json.loads((ROOT / "engineering-analysis.json").read_text(encoding="utf-8"))
crs = CRS.from_epsg(32643)
inverse = Transformer.from_crs(crs, 4326, always_xy=True)
summaries = []
section_evidence = []

for document in documents:
    tables = {t["name"]: t for t in document["tables"]}
    nodes = {r["NodeId"]: r for r in tables["Node"]["rows"]}
    sections = tables["InstSection"]["rows"]
    loads = tables["Loads"]["rows"]
    used_nodes = {s[k] for s in sections for k in ("FromNodeId", "ToNodeId")}
    xy = [(n["X"], n["Y"]) for n in nodes.values()]
    ll = [inverse.transform(*point) for point in xy]
    missing_relations = []
    for relation in document["relationships"]:
        parent_keys = {tuple(r[f["name"]] for f in relation["fields"]) for r in tables[relation["table"]]["rows"]}
        for row in tables[relation["foreign_table"]]["rows"]:
            key = tuple(row[f["foreign_name"]] for f in relation["fields"])
            if all(v is not None for v in key) and key not in parent_keys:
                missing_relations.append({"relation": relation["name"], "key": key})
    all_lengths_match = True
    for section in sections:
        start, end = nodes[section["FromNodeId"]], nodes[section["ToNodeId"]]
        distance = math.hypot(end["X"] - start["X"], end["Y"] - start["Y"])
        all_lengths_match &= math.isclose(distance, section["SectionLength_MUL"], rel_tol=1e-9, abs_tol=1e-6)
        section_evidence.append({"file": document["file"], **section, "computed_planar_length": distance})
    summary = {
        "file": document["file"], "sha256": document["sha256"],
        "tables": len(tables), "fields": sum(len(t["fields"]) for t in tables.values()),
        "indexes": sum(len(t["indexes"]) for t in tables.values()),
        "relationships": len(document["relationships"]),
        "row_counts": {name: table["count"] for name, table in tables.items()},
        "populated_tables": sum(t["count"] > 0 for t in tables.values()),
        "xy_extent": [min(x for x, y in xy), min(y for x, y in xy), max(x for x, y in xy), max(y for x, y in xy)],
        "lon_lat_extent_if_epsg32643_label_is_correct": [min(x for x, y in ll), min(y for x, y in ll), max(x for x, y in ll), max(y for x, y in ll)],
        "unreferenced_nodes": sorted(set(nodes) - used_nodes),
        "missing_node_references": sorted(used_nodes - set(nodes)),
        "unmatched_declared_relationship_values": missing_relations,
        "all_section_lengths_match_node_distances": all_lengths_match,
        "section_phases": dict(Counter(s["SectionPhases"] for s in sections)),
        "consumer_total": sum(r[f"Phase{i}Customers"] or 0 for r in loads for i in (1, 2, 3)),
        "modeled_kva_total": sum(r[f"Phase{i}Kva"] or 0 for r in loads for i in (1, 2, 3)),
        "fractional_customer_phase_values": sum((r[f"Phase{i}Customers"] or 0) % 1 != 0 for r in loads for i in (1, 2, 3)),
        "conductors_by_column": {f: dict(Counter(s[f] for s in sections)) for f in ("PhaseConductorId", "PhaseConductor2Id", "PhaseConductor3Id", "NeutralConductorId")},
    }
    summaries.append(summary)

fields = [
    ("Projected CRS (EPSG)", "epsg", 32643, "Derived from the recorded CRS label using the PROJ EPSG database; no numeric EPSG code is stored in either MDB. Confirm using original GPS/control points."),
    ("CRS name", "crs_name", "WGS 1984 UTM Zone 43N", "Exact SAI_Control.ProjectionFile in both files. This confirms the label only, not its geographical correctness."),
    ("Frequency (Hz)", "frequency_hz", 60, "Exact SAI_Control.Frequency in both files. Retain 60 as source evidence; project frequency needs engineering confirmation."),
    ("Supply voltage, line-to-line (kV)", "nominal_voltage_kv", 11, "Exact InstFeeders.NominalKvll. BusVoltageLevel is separately 11.37 and is not the nominal-voltage field."),
    ("LT secondary voltage, line-to-line (kV)", "secondary_voltage_kv", None, "Not established. NomKvMode=C and SpecNomKv=4.16 are recorded, but mode meaning/library voltage must be checked. PTSecondary=120 is a separate control field. The earlier 0.4kV Data Entry export convention is not evidence from these MDBs."),
    ("Projection WKT / reference", "projection_wkt", "", "ProjectionWKT is an empty string in both files. Optional; leave blank when reproducing the source."),
    ("Approved MDB template", "template_id", None, "Application-specific registered template ID is not contained in a reference MDB. Select an approved, clean template with the matching schema; do not use a populated source file as the template."),
    ("Conductor library revision", "conductor_library_revision", None, "Not stored. Referenced conductor names are present, but definitions and library revision are absent."),
    ("Transformer library revision", "transformer_library_revision", None, "Not stored. Referenced transformer name is present, but its library revision and electrical definition are absent."),
    ("Line configuration library ID", "configuration_id", "12.5/7.2 kV cross arm C2-2", "Exact ConfigurationId on all 19 sections. It is an identifier; the voltage-looking text is not the network nominal supply voltage."),
    ("Transformer supply conductor library ID", "supply_conductor_id", "DOG", "Candidate family only: supply R/B are DOG, Y is ' DOG' with one leading space; neutral is blank. Verify literal IDs/alias policy in the installed library."),
    ("Transformer secondary-link conductor library ID", "secondary_conductor_id", "ANT", "Candidate family only: link R/B are ANT, Y is ' ANT'; SectionPhases=RYBN but the neutral ID is blank. Resolve the neutral and whitespace policy."),
    ("Phase-to-phase spacing (cm)", "phase_spacing_cm", 121.9, "Exact PhaseToPhaseSpacing_MUL on all sections. The application labels this field cm; the MDB declares Metric globally and provides no field-level unit annotation. Confirm the spacing unit before approval; no conversion was applied."),
    ("Phase-to-neutral spacing (cm)", "neutral_spacing_cm", 91.4, "Exact PhaseToNeutralSpacing_MUL; same spacing-unit qualification applies."),
    ("Average conductor height (m)", "conductor_height_m", 9.1, "Exact AveHeightAboveGround_MUL; Metric is recorded. This is model conductor height, not an individual surveyed pole height."),
    ("Survey pole-height unit", "pole_height_unit", None, "These electrical models do not establish the paper form's pole-height unit. Current PDF Data Entry saves feet under the existing application policy; that policy comes from the application, not these MDBs."),
    ("Two-digit survey-year window starts at", "two_digit_year_start", None, "Not established. Historical model-year labels 2015-2025 are not a DDMMYY interpretation policy."),
    ("Transformer capacity to library type", "transformer_types_json", {"100": "100 KVA"}, "Candidate mapping inferred from the type label only. Both instances use '100 KVA'; verify its capacity and electrical definition in the selected library. No other capacities are established by these two files."),
    ("Approved demand method", "load_mode", None, "No approved application demand method is stored. Existing rows use fractional equal phase allocation, which differs from the application's whole-customer balancing method."),
    ("Blank consumer cells", "blank_consumers_zero", None, "The files contain numeric model loads, not the original blank paper cells. They do not establish this policy. The Data Entry MDB export-only zero rule does not automatically approve this final-generation setting."),
    ("Category kVA and power-factor rates", "rates", None, "No RS/RL/SC/LC/SI/LI/PB/AG/ST/PV rate table is present. Observed aggregate ratio is 3 kVA/customer for 39 nonzero phase records, but power factor cannot be established from positive kVA with zero kW/kvar."),
    ("Demand method evidence", "load_evidence", None, "This review supplies observed load evidence, not an approved demand standard. Document the selected method and its approval separately."),
    ("Advanced electrical mapping", "mapping_json", None, "133 cross-file consistent nonblank, non-instance parameters are in engineering-analysis.json. Review these observations and role-dependent settings before populating the advanced mapping; survey topology must come from the current network."),
    ("Observed equipment references", "equipment_references_json", analysis["settings"]["reference_import"]["equipment_references"], "Literal names, including significant leading spaces, are in observed-equipment-references.json. They are references, not complete library contents."),
]

worksheet = {
    "status": "reference_evidence_only_not_applied_or_approved",
    "sources": [{"file": d["file"], "sha256": d["sha256"]} for d in documents],
    "fields": [{"label": label, "application_field": key, "observed_or_candidate_value": value, "qualification": note} for label, key, value, note in fields],
    "coordinate_derivation": {"pyproj": pyproj.__version__, "proj": pyproj.proj_version_str, "epsg_database_version": pyproj.database.get_database_metadata("EPSG.VERSION"), "derived_crs": crs.name, "authority": crs.to_authority(), "axes": [{"name": a.name, "unit": a.unit_name} for a in crs.axis_info], "source_wkt_is_blank": True},
    "file_summaries": summaries,
}
for name, value in (("configuration-worksheet.json", worksheet), ("section-equipment-evidence.json", section_evidence), ("observed-equipment-references.json", analysis["settings"]["reference_import"]["equipment_references"])):
    (ROOT / name).write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

def display(value):
    if value is None:
        return "Unresolved"
    if value == "":
        return "Empty string"
    return "`" + json.dumps(value, ensure_ascii=False) + "`"

lines = [
    "**Engineering Configuration evidence from the two Industrial MDB files**", "",
    "Inspected on 19 October 2026 using read-only Access DAO. All user tables, records, fields, indexes, relationships, database properties, field annotations and saved-query names were inspected. No saved query, macro or module was executed. Source SHA-256 hashes remained unchanged. Application settings, approvals, stored survey records and source MDBs were not changed.", "",
    "Both files share the canonical application reference schema: 50 tables, 1,065 fields, 133 indexes and 41 relationships. Both have seven populated tables and 43 empty tables. There are no equipment-library definition tables or saved queries, no linked tables, and no database-property/field-annotation evidence of an external equipment-library revision.", "",
    "**Source files and population**", "",
    "| Table | IndustrialT-4060116233 | IndustrialT-4120623648 |", "|---|---:|---:|",
]
for name in ("SAI_Control", "InstFeeders", "InstPrimaryTransformers", "InstSection", "Loads", "InstMeterPoints", "Node"):
    lines.append(f"| {name} | {summaries[0]['row_counts'][name]} | {summaries[1]['row_counts'][name]} |")
lines += ["", "The other 43 tables have no records, including generators, capacitors, protective devices, regulators and annual device/load records. Empty tables provide schema definitions and defaults, but do not demonstrate installed equipment or engineering values.", ""]
for document in documents:
    lines.append(f"- `{document['file']}` SHA-256: `{document['sha256']}`")
lines += ["", "**Engineering Configuration form worksheet**", "", "Values below reproduce source observations or identify candidates; unresolved and qualified entries prevent a fully justified approved configuration from being obtained from these files alone.", "", "| Application field | Observed / candidate value | Evidence and qualification |", "|---|---|---|"]
for label, key, value, note in fields:
    if key in ("equipment_references_json", "mapping_json"):
        value = "See accompanying evidence JSON"
    lines.append(f"| {label} (`{key}`) | {display(value)} | {note} |")

lines += ["", "**Coordinate checks**", "", "The numeric EPSG code 32643 is derived from the stored label using the local PROJ database. It is not explicitly recorded in the MDB. SAI_Control.LengthUnits is Metric and ProjectionWKT is empty. The coordinate results below are conditional on the label being correct, and do not independently establish geographical accuracy.", "", "| File | Stored X range | Stored Y range | Longitude range if EPSG:32643 | Latitude range if EPSG:32643 |", "|---|---|---|---|---|"]
for summary in summaries:
    x1, y1, x2, y2 = summary["xy_extent"]
    lon1, lat1, lon2, lat2 = summary["lon_lat_extent_if_epsg32643_label_is_correct"]
    lines.append(f"| {summary['file']} | {x1}-{x2} | {y1}-{y2} | {lon1:.6f}-{lon2:.6f} | {lat1:.6f}-{lat2:.6f} |")
lines += ["", "The resulting location is approximately 30.37 N, 77.88 E. Check this against the corresponding original GPX/control points before choosing the current project's CRS. A matching label alone does not validate the coordinates. This inspection does not substitute a different UTM zone.", "", "All 19 stored section lengths match the Euclidean distances between their recorded nodes within 0.000001 coordinate units. Both source supply and secondary-link sections have length 1 and use synthetic one-unit node offsets. These offsets/lengths are historical modeling conventions, not surveyed spans for the current project. Each file also contains one unreferenced transformer-root Node record; there are no missing referenced nodes and no unmatched declared relationship values.", "", "**Equipment references and construction**", "", "| Role | R conductor | Y conductor | B conductor | Neutral | Phases |", "|---|---|---|---|---|---|", "| Transformer supply, both files | `DOG` | ` DOG` | `DOG` | Empty | `RYB` |", "| Transformer secondary link, both files | `ANT` | ` ANT` | `ANT` | Empty | `RYBN` |", "| Distribution, 4060116233 (7 sections) | `WASP` | ` WASP` | `WASP` | `WASP` | `RYBN` |", "| Distribution, 4120623648 (8 sections) | `ANT` | ` ANT` | `ANT` | `ANT` | `RYBN` |", "", "Each Y conductor shown with a leading space has exactly one U+0020 space. Preserve literal references in the evidence. A single supply/secondary conductor-family field cannot by itself settle whether these whitespace variants are aliases or different installed-library IDs. The secondary-link neutral is blank even though N is present in SectionPhases.", "", "Both transformer instances reference `100 KVA`; neither file establishes 25/50/200 kVA types or a GNAT conductor. The complete referenced conductor/configuration/transformer names are saved in observed-equipment-references.json. Conductor resistance, reactance, ampacity, material and size definitions; transformer winding ratings, losses, real impedance and revision; and the cross-arm library's physical definition are not included. Section AmpRating=100 occurs with UseSectAmpRating=0, so it is not evidence of a verified 100 A conductor rating.", "", "**Transformer, supply and load-model observations**", "", "Both models have ConnectedPhases=RYB, high/low connection codes YG/YG, TapSetting=1, UseInstanceImpedance=0, PercentImpedance=0 and PercentResistance=0. With instance impedance disabled, the zero fields do not establish a physically zero-impedance transformer; the missing external library definition is needed. NomKvMode=C and SpecNomKv=4.16 are retained as source evidence, not entered as the confirmed LT voltage.", "", "The common feeder settings include ConnectionType=YG, NominalKvll=11, BusVoltageLevel=11.37, FeederMultiplier=1, UseByPhVoltLevel=0, phase voltage angles 0/-120/+120 and stored zero sequence/source-impedance fields. A stored zero is not a fault-level study or evidence of real zero source impedance.", "", "All sections contain distributed-load constant impedance/current fields 40/10 and spot-load fields 40/30. Their residual percentages are 50 and 30 respectively if the usual three-component sum-to-100 interpretation is approved; no load-model interpretation was applied to application settings. PercentLoadGrowthRate=2, NeutIsGrounded=1, UseEquivSpacing=0 and IdenticalPhaseConductors=0 are recorded consistently. LoadConnectionPhaseGnd is role-dependent: 0 on the two supply sections and 1 on the 17 downstream sections. Do not use one unconditional value for both roles.", "", "| File | Stored total customer equivalents | Stored total kVA | Nonzero customer phases |", "|---|---:|---:|---:|"]
for summary, document in zip(summaries, documents):
    loads = next(t["rows"] for t in document["tables"] if t["name"] == "Loads")
    nonzero = sum((r[f"Phase{i}Customers"] or 0) > 0 for r in loads for i in (1, 2, 3))
    lines.append(f"| {summary['file']} | {summary['consumer_total']:g} | {summary['modeled_kva_total']:g} | {nonzero} |")
lines += ["", "Across all 39 nonzero phase records, kVA/customer is 3 (within floating-point tolerance), while kW=0 and kvar=0. No usable power factor or category-specific demand rates can be derived. The source uses equal fractional customer equivalents across phases; the application balances whole customers. Choosing 3 kVA for all consumer categories would be a new engineering decision, not a recovered category table.", "", "The source load settings are IsSpotLoad=0, LocationToModelSpotLoads=C and CapacityFactor=1. Each file has one InstMeterPoints row populated only by SectionId; no meter measurements/settings are established. There are no PV/generator rows or annual generator models. SAI_Control records Product='SynerGEE Electric 5.0.0.324', VoltageFactor=11, Phase1/2/3=R/Y/B, VoltageInPercent=0, PTSecondary=120 and historical model-year labels 2015-2025. Product identifies the model's recorded target version, not the current installed equipment-library revision. The model-year labels and original feeder/substation/transformer/node IDs belong to these source models and should not be reused as current-project identifiers.", "", "**Application implications and remaining inputs**", "", "EngineeringConfiguration.save writes the form's secondary_voltage_kv into advanced InstPrimaryTransformers.SpecNomKv and replaces frequency, projection, nominal supply voltage and spacing/height values from the form. Therefore the unresolved LT voltage, NomKvMode semantics and spacing-unit review must be settled before approving those settings. A full WKT generated from an EPSG database would be derived material, not recovered source content, and must not be silently truncated to the form's 255-character field.", "", "The current PDF Entry unit policy is feet. The prior request to export blank counts as zero and line voltage as 0.4kV applies to the independent Data Entry MDB module. Those export conventions do not supply the missing library definitions or automatically approve final SynerGEE load/voltage policies.", "", "To finish an approved Engineering Configuration, obtain the exact installed conductor and transformer library revisions/definitions, approve the whitespace alias policy and secondary-link neutral, select a clean registered template, confirm project frequency and LT voltage, verify CRS against GPS/control points, confirm spacing units and construction applicability, and approve load rates/power factor/allocation and the final-generation blank-cell/date policies. This inspection supplies values and a worksheet; it does not change or approve the module.", "", "**Complete consistent parameter inventory**", "", "The following 133 values are identical across all populated rows of both files after excluding observation identities, coordinates, demand values, historical years and other instance-specific fields. They are source evidence. Repeated default/disabled fields still need interpretation; consistency is not proof of a project engineering standard.", "", "| Source field | Observed value |", "|---|---|"]
for table, values in analysis["settings"]["mapping"]["table_values"].items():
    if not isinstance(values, dict):
        continue
    for field, value in values.items():
        lines.append(f"| `{table}.{field}` | {display(value)} |")
lines += ["", "**Evidence files**", "", "- configuration-worksheet.json: every module field with source value/candidate, unresolved status and qualification.", "- engineering-analysis.json: full field variants, 133 consistent parameters, role profiles, missing values and conflicts from the existing read-only analyzer. No import method was invoked.", "- observed-equipment-references.json: exact library reference strings.", "- section-equipment-evidence.json: all 19 source sections plus recomputed planar lengths.", "- Two *.inspection.json files: all 50 tables' schemas, indexes, rows and relationships.", "- Two *.metadata.json files: full canonical schema fingerprints, database properties, field annotations and query inventory.", "", "CRS derivation uses the installed PROJ EPSG database (versions recorded in configuration-worksheet.json). General UTM zone interpretation: https://proj.org/en/stable/operations/projections/utm.html . Source facts above come from the inspected MDBs; module requirements come from resources/views/engineering/edit.blade.php and app/Services/Mdb/EngineeringConfiguration.php.", ""]
(ROOT / "ENGINEERING_REVIEW.md").write_text("\n".join(lines), encoding="utf-8")
print(f"Created module worksheet and detailed report: {len(fields)} form entries, {analysis['settings']['reference_import']['consistent_parameter_count']} constant observations, {len(section_evidence)} sections.")
