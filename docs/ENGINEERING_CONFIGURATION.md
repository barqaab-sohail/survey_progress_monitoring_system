# Central engineering configuration

Open **Administration → Engineering Configuration** as Super Admin. This is the application's Admin role (`super_admin`). Dedicated survey roles, project managers, and users granted the old Configure permission cannot access or update this module or the legacy configuration/template endpoints.

Select the HAZECO project and configure its engineering standard once. Settings are stored centrally in the existing `mdb_workflow_configurations` table, uniquely per project, rather than per operator, feeder, survey or transformer. No migration is required. Existing approved settings appear for review; actual engineering values must be supplied and approved by the Admin.

The module provides:

- Projected EPSG CRS, CRS name/reference, frequency, supply and LT secondary voltage. The existing PROJ worker verifies that the CRS is projected and uses metres; actual surveyed coordinates are checked against its area when processed.
- A pinned approved clean MDB template and its reviewed conductor/transformer/configuration references, with library revision labels. Manage templates and reference allowlists through the linked template administration page. Registering another template does not switch this project's selected template.
- Supply/secondary-link conductor IDs, line configuration ID, phase/neutral spacing, conductor height, survey pole-height unit and survey year interpretation.
- Capacity-to-transformer-library mapping. Each capacity needs an exact approved mapping; no library type is guessed from its kVA label.
- Either verified per-section phase demand or centrally approved category kVA/power-factor rates. The automatic method uses whole customers balanced across connected R/Y/B phases in order, then calculates kVA, kW and lagging kvar. The form describes the estimation method explicitly. Missing counts remain unresolved unless Admin expressly approves treating blanks as zero. Original paper counts are preserved in entry rows and prior revisions.
- Advanced reviewed electrical mappings for transformer impedance/connection modes, load model, source wiring and optional meter/PV models. Network topology, measured observations and source evidence remain specific to each survey; these cannot be invented from shared electrical defaults.

Save requires an explicit engineering approval, a change reason, valid library references and positive/range-checked parameters. Reviewer identity/time are set by the server. Stale Admin forms cannot overwrite newer revisions. Changes are audited, revoke affected survey approvals, supersede existing exports, and advance batch revisions. Old immutable revisions remain intact; the normal independent survey verification workflow must approve a fresh revision before generation resumes.

Reviewed MDB generation snapshots automatically include the current central settings, selected template, line configuration and approved demand mapping. The exporter applies the capacity mapping and central transformer settings. Existing hashes and worker/final-completion checks prevent stale configuration or changed templates from producing a downloadable artifact. Once a project uses the new module, the old raw settings endpoint cannot overwrite it.

The older transformer builder also requires this central approval at generation time. Its local electrical settings cannot override the shared standard. Its limited generation model supports northern WGS84 UTM and automatic category rates; use **MDB Creation & Verification** for another projected CRS, explicit per-phase measured demand or reviewed PV generation. It uses the selected template, central line construction, library mapping and load method and records the engineering revision in its survey audit data. A configuration change during generation withholds the file.

Dependencies remain the existing projection worker (Python with pyproj) and the appropriate Windows Access exporter or configured remote worker. A labelled library revision/allowlist does not install the external SynerGEE equipment library. No production settings or sample engineering values are approved automatically.

## Importing approved-reference observations

The module can retain an incomplete engineering draft. **Save draft** validates supplied values and JSON, retains unresolved blanks and exact observed equipment IDs, audits the edit and removes engineering approval. Full approval continues to require all original checks. Imported sources, SHA-256 hashes, conflicting observations and unavailable parameters are shown separately from the editable engineering fields. Evidence describes the original extraction and is retained after edits.

The read-only inspector in `scripts/mdb/inspect-reference.ps1` produces `<source>.mdb.json` files without executing Access macros or saved queries. The import command compares exactly ten such inspections with matching schemas, verifies original hashes when applying, and only populates a project that has no configuration:

```powershell
php artisan mdb:import-engineering-references C:\private\inspections --project=HAZECO-TDL --admin=1 --source-directory=C:\private\originals --report=C:\private\new-analysis.json --apply
```

Omit `--apply` for a comparison/report only. The importer never grants approval, creates a template, copies another project's wiring or invents category demand rates. Existing configurations are protected from replacement. The 16 October 2026 HAZECO import report and verification artifacts are in `storage/app/private/engineering-reference-imports/2026-10-16/`.
