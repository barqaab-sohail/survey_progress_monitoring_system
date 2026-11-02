# PESCO warehouse review and application integration guide

Reviewed on 2 November 2026 against the current application source.

**Recommendation:** use this file as a versioned equipment library alongside the generated network MDB. Keep the application's Laravel database and clean network MDB template in their existing roles. Start with reviewed equipment IDs through the existing Engineering Configuration module; add a dedicated warehouse importer if automatic equipment browsing, matching and validation are required.

## 1. What was inspected

- Source: `C:\Users\sohai\Downloads\Warehouse_PESCO - 14DEC2025.mdb`.
- Size: 22,073,344 bytes.
- SHA-256: `975a0069152bcc4b0a0eb082c5f4c56c9144c91e87ea6d344b0781fbc5a7917e`.
- 38 user tables and 72,268 total records. The two recorded relationships concern Access navigation system tables; there are no declared user-table relationships in this extraction.
- The source was open by another process, so a separate shared-read copy was inspected through the existing read-only Access DAO inspector. The original's subsequently calculated hash matched the inspected copy. No source records, application records or engineering settings were changed. Saved queries, macros and modules were not executed.
- The filename's date is a label, not proof of engineering approval or the age of every record.

| Table | Records | Useful application role |
|---|---:|---|
| `DevConductors` | 165 | Exact conductor IDs, sequence impedance fields, ratings and cable attributes |
| `DevTransformers` | 46 | Exact type IDs, kVA, voltages, impedance, resistance, losses and connection codes |
| `DevConfig` | 25 | Line construction IDs and conductor-position geometry |
| `DevCustClass` | 11 | Customer-class definitions and load-model attributes |
| `DevCustLoadCurves` | 528 | Customer load profiles, if a later study explicitly uses them |
| `DevGenerators` | 11 | Generator/PV type definitions requiring suitability review |
| `DevEnvironProf` / `DevEnvironProfMth` | 5,471 / 65,652 | Environmental profiles; defer from initial field-app lookups |
| `SAI_Equ_Control` | 1 | Warehouse conventions and recorded product label |

The control row records `Synergi Electric 6.29.0`, 50 Hz, `Metric`, and phases R/Y/B. Projection name and WKT are blank. Year descriptions are 2012–2022; they should not become survey dates or current study years. `Metric` alone does not document every field's unit or impedance basis: retain original field names and confirm the software's unit conventions before calculations.

## 2. Relationship to this application

The intended flow is:

`Survey/PDF/GPX observations + approved project settings + reviewed warehouse references -> network MDB -> Synergi with the matching warehouse -> engineering checks and load flow`.

The network MDB supplies nodes, feeder connections, sections, transformers and demand instances. The warehouse supplies reusable equipment definitions referenced by names. It has no `Node`, `InstSection`, `InstFeeders` or `Loads` tables and cannot replace the application's network template.

The current exporter expects the approved network schema: 51 tables and 42 relationships, with a supported legacy 50-table form. This warehouse's 38-table schema is different. Uploading it as a clean network template will not make it usable by that exporter. Copying `Dev*` tables into the clean template would also violate the current schema contract.

Current integration points:

| Current code | Existing behavior / integration opportunity |
|---|---|
| `app/Services/Mdb/EngineeringConfiguration.php` | Central project settings, library revision labels and capacity-to-type mappings |
| `app/Services/Mdb/EntryLookups.php` | Conductor choices and existing aliases `A -> ANT`, `W -> WASP`, `GN -> GNAT` |
| `app/Services/Mdb/EntryService.php` | Converts recorded conductor codes to network reference IDs |
| `app/Services/Mdb/NetworkValidator.php` | Checks references against selected template metadata |
| `app/Services/Mdb/ExportPayloadBuilder.php` | Writes exact conductor, configuration and transformer references into network instances |
| `scripts/mdb/export-reviewed-mdb.ps1` | Creates and reads back the network MDB; does not install a Synergi warehouse |

The reviewed source has no dedicated warehouse registry/importer or automatic warehouse attachment. Template `equipment_references` are reviewed ID allowlists, not imported electrical definitions. Updating an allowlist does not install this file on an engineer's Synergi workstation.

The existing `mdb:import-engineering-references` command compares ten network-model inspections. It is not a warehouse importer and should not be used with this file.

## 3. Practical use with the existing screens

1. Retain an immutable copy of this warehouse and its hash. Have the department confirm that this PESCO library is suitable for the application's HAZECO project and identify the intended Synergi installation/version.
2. In the target Synergi installation, configure a separate working copy as the equipment warehouse for a representative generated network. Confirm the actual warehouse association using that version's documented procedure. The exact menu sequence and backward compatibility were not verified in this review. DNV's [official online help](https://mysoftware.dnv.com/knowledge-centre/synergi-electric/usermanual/) is the appropriate version-specific reference.
3. As Super Admin, open **Administration -> Engineering Configuration**, select the project, and follow **Manage / register approved templates**. Continue using a clean network-schema MDB. Register a reviewed template metadata version listing the equipment IDs actually verified against this warehouse. Preserve any other metadata needed by the project. Existing reviewed template versions remain immutable.
4. Select that template version in Engineering Configuration. Set the conductor/transformer library revision labels to an unambiguous label that includes this warehouse's filename and hash reference. Review frequency, supply and secondary voltages, connection codes, line construction, units and load policy before approving settings.
5. Map surveyed transformer capacities to the reviewed exact library IDs. Resolve conductor aliases explicitly. Keep the original field observations unchanged.
6. Reverify affected surveys and generate fresh exports after changing central settings or template references. Existing application behavior invalidates affected approvals/exports when engineering settings change.
7. Open the network against the matching warehouse in the target Synergi version. Check unresolved equipment, connectivity, phase/voltage consistency, impedance selection and load-flow results. Database readback alone does not establish engineering acceptance.

Example metadata fragment, restricted to IDs that exist in this file; this is a draft for review, not approval:

```json
{
  "equipment_references": {
    "conductors": ["ANT", "DOG", "GNAT", "WASP", "2/0 AWG"],
    "transformers": ["50 KVA", "100 KVA", "200 KVA"],
    "configurations": ["12.5/7.2 kV cross arm C2-2"]
  }
}
```

Example transformer mapping syntax, after suitability review:

```json
{"50":"50 KVA","100":"100 KVA","200":"200 KVA"}
```

Do not list only this sample subset if actual surveys use other reviewed conductors or transformer capacities. The warehouse also contains `LT` and `HT` configuration IDs; review their geometry before deciding whether they are appropriate. An ID's existence does not establish suitability.

## 4. Findings requiring a decision

| Topic | Warehouse evidence | Current bundled reference evidence / required action |
|---|---|---|
| Frequency | 50 Hz | `resources/mdb/engineering-defaults.json` records 60 Hz. Review the actual project setting; the live project's saved value was not inspected. |
| Product label | Synergi Electric 6.29.0 | Bundled network references record SynerGEE Electric 5.0.0.324. Verify the intended application version and supported file combination. Editing a product-label string is not a schema conversion. |
| Transformer voltage | `100 KVA`: 11 / 0.415 kV | This supplies a candidate secondary-voltage reference, subject to project confirmation. Bundled defaults leave the LT voltage unresolved. |
| Transformer connections | `100 KVA`: high side `D`, low side `YG` | Bundled instance mapping records `YG` / `YG`. Resolve how the selected installation applies instance versus library connection settings. |
| Transformer impedance | `100 KVA`: impedance 4%, resistance 2.02% | Relevant when library impedance is selected. `UseInstanceImpedance=1` instead needs reviewed instance values. Avoid a blanket 4% value for all types. |
| Existing exact IDs | ANT, DOG, GNAT, WASP; 50/100/200 KVA; cross arm C2-2 | All are present and can be reviewed for immediate use. |
| Whitespace variants | No leading-space ANT/DOG/GNAT/WASP IDs | Bundled reference evidence includes leading-space variants. Preserve raw observations and approve explicit mappings rather than silently changing history. |
| Insulated conductor | `INS ANT` exists; `INSULATED ANT` does not | Similar names are candidates for engineering review, not automatic aliases. |
| ABC cables | `ABC 50 MM2`, `ABC 95 MM2` exist | Printed app choices use `ABC 50mm2`, `ABC 95mm2`; they are not exact matches. The existing alias function does not translate them. New rows can select exact reviewed library choices once exposed by metadata, or a dedicated mapping change can handle these codes. |
| PVC conductor | `PVC 0.083` absent | Other PVC sizes exist; a size must be identified from evidence before mapping. Several near-duplicate PVC IDs also exist. |
| Capacity alone | Both `50 KVA` and `50 KVA CSP` exist | These have different voltage/connection attributes. Capacity alone cannot choose between them. |
| Customer demand | Customer classes and curves present | They do not establish approved demand per surveyed consumer. Current code uses 2 kVA for single-phase categories and 5 kVA for three-phase categories; the warehouse does not automatically replace that policy. |
| Location | Projection fields blank | Keep the verified project CRS and survey/GPX coordinates; this file does not supply them. |

The file also contains generic transformer and generator definitions at other voltage levels. Importing everything as approved project equipment would expose unsuitable choices. For example, the record named `Residential Solar` has a recorded 7.62 kV rating; its name alone does not justify selection for an LT PV survey.

## 5. Recommended application enhancement

Add a Super Admin **Equipment Warehouses** module as a separate feature:

1. **Register and inspect:** upload to private storage; record original filename, SHA-256, recorded product, frequency and units. Use the existing Windows Access worker approach to extract approved table names and fields in read-only mode. No PHP request needs a permanent direct connection to a desktop MDB.
2. **Stage a catalog:** retain versioned conductor, transformer and configuration rows with raw fields and source IDs. Start with the 236 records across those three tables. Defer environmental profiles and load curves until a defined use case needs them.
3. **Review:** show conflicts and exact matches, require explicit project suitability decisions, and keep imported definitions unapproved until reviewed. Preserve units/bases and avoid guessed conversions. The MDB has no declared user-table relationships, so validate referenced IDs explicitly.
4. **Map observations:** store project-specific survey-code-to-equipment-ID mappings. Separate the raw observed code from the resolved ID and warehouse revision. Flag ambiguous voltage, phase, material or capacity matches instead of choosing by name.
5. **Pin and export:** pin one approved warehouse version per project; validate every exported equipment ID against that version and the selected template allowlist. Freeze warehouse hash/version and resolved mappings in export snapshots, transfer JSON and the final manifest. Deliver the matching warehouse separately when needed, subject to the department's distribution policy.
6. **Keep review history:** approval changes or warehouse replacement must advance configuration revisions and invalidate affected exports consistently with the current engineering workflow.

Suggested storage responsibilities are warehouse versions, versioned equipment records, project warehouse selection and approved survey-code mappings. Reuse the existing project configuration and audit workflow where practical. Keep daily survey/progress tables independent of the equipment catalog.

## 6. Review artifacts and limits

Artifacts in `output/warehouse-pesco-review/`:

- `warehouse-inspection.json.gz`: complete read-only extraction, schema and indexes, losslessly compressed and verified against the extracted JSON. The disposable MDB copy was removed after review.
- `warehouse-summary.json`: table counts, schema and two sample rows per table.
- `key-equipment.json`: selected engineering fields and exact equipment IDs.
- `compatibility-review.json`: exact-match comparison against bundled reference IDs and printed conductor options.
- `analyze_warehouse.py`: reproducible catalog comparison.

All 165 conductor IDs, 46 transformer IDs and 25 configuration IDs are unique within their respective extracted tables. The source hash matched the review copy after inspection. This was a data/source-code review: no production import, configuration approval, native Synergi opening, load-flow run or software-version compatibility test was performed. No application code was changed.
