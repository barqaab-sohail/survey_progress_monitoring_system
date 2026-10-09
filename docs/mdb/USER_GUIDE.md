# MDB Creation and Verification user guide

For everyday PDF and S/E data entry, use the [simple operator guide](OPERATOR_GUIDE.md). The default batch screen now places the PDF above the header and individual S/E rows. The detailed network, engineering and acceptance procedures below remain available through **Advanced / engineering** and the review screens.

Open **MDB Creation & Verification** from the application navigation. The dashboard shows transformer records, entered sections, current validation errors, pending verification, current MDB outputs and Analysis Team acceptance. Export output counts remain separate from transformer counts. Filter by project, feeder, transformer reference, survey team, survey date range and workflow status.

Access uses the application's existing users, roles, team membership and permissions. Administrators grant the new `MdbWorkflow:*` permissions. A permission to perform an action still requires access to the batch's project or team.

Assign additional roles such as `mdb_creation_team`, `mdb_verifier` and `analysis_team` through the application's existing role management. An Analysis Team user also needs membership in an MDB or processing team for the applicable project. Existing survey, MDB creation and verification roles receive the appropriate workflow permissions through the permission seeder; an administrator can manage them using the existing permission system.

## Survey Team: collect and submit sources

1. Choose **Create survey batch** and select the project, feeder, team and survey date. You can upload several original PDFs and GPX files at creation.
2. In **Original source files and versions**, upload additional files. To replace a document, select its preceding version. Every original and its integrity hash remain available through authorized routes.
3. If Google Drive is enabled, connect your account and supply an authorized, downloadable Drive file ID. Processing uses a preserved private copy rather than a public link.
4. Wait for each source to reach **Ready**. A failed source shows its processing error and a retry button. Queue workers are required for PDF inspection and GPX processing.
5. Use **Submit survey sources** when collection is ready. A survey submission does not approve electrical entries.

The GPX detail list shows textual names, source file, original WGS84 coordinates, elevation and available descriptions. Timestamps display in Asia/Karachi; originals remain UTC. Names such as `0001` and `SSS` remain text. Duplicate names do not establish connections.

## MDB Creation Team: enter verified observations

1. Add a transformer network with its code, verified capacity, surveyed connection waypoint and draft header. Enter the original handwriting and the normalized value separately. Leave unreadable or unrecorded values blank.
2. Explicitly associate each PDF header, continuation or PV page with the transformer. Check the confirmation stating that the page belongs to that transformer. A continuation page shares the selected header only through this association. Reopen the header form and confirm manual verification after checking these pages. Remove an incorrect association with its reason and explicit confirmation; the original source remains preserved and the header requires renewed verification.
3. Choose the transformer network to edit. The simple operator screen places the PDF above the form on all screen sizes. Existing section-only records remain in **Advanced / engineering**, where the original side-by-side editor is available. Select a source and page, or follow a section's PDF page link. **Open PDF separately** is available when an embedded PDF viewer is unsupported.
4. Add one section for each surveyed start/end pair. Enter both waypoint names and their GPX source files. If the reference is ambiguous, choose an exact verified waypoint. Repeated endpoints form branches; waypoint numbering and timestamps are never used to infer wiring.
5. In the advanced section editor, enter phase and neutral configuration, separate R/Y/B/N conductor codes, equipment type and library reference, pole class, and pole height with explicit metres or feet. The simple row editor derives phase from conductors and retains separate equipment observations for each S/E pole. The printed `Int` field is an intersection checkbox, excluded from consumer counts.
6. Enter the printed RS, RL, SC, LC, SI, LI, PB, AG and ST consumer counts, retaining their original written values. Blank is unrecorded; zero requires a checked observation. The simple row editor also records the separate PV count. Confirm LC's precise commercial classification with the project's engineer.
7. Record measured or documented demand separately from consumer counts. For each allocated phase, enter customers, kW, kvar and kVA, the method and evidence. The system does not infer power factor, equal phase allocation or demand from customer counts.
8. Set the source PDF, page and survey row. Check **I checked this section…** only after manual review. Optional recorded intermediate WGS84 geometry can be entered as JSON. A measured length override needs a reason and verifier approval. Endpoint distances are labelled estimates.
9. For genuine ditto marks, choose a previously checked section and select **Review and confirm ditto copy**. The confirmation lists the values to copy. Only phase, conductor and equipment observations are copied; enter and verify the new endpoints, raw transcription, consumer counts and source row independently.
10. Add PV records with consumer reference, verified installed kW, original entries, remarks and PDF provenance. Existing PV entries have a correction form. An incorrect section can be removed with a reason and explicit confirmation; prior revisions and the audit history retain it, and its PV observations remain at transformer level until reassigned.
11. Review current validation and choose **Send entered network for verification**. Save each form before submitting the network.

The system checks the batch revision on every edit. If another user or processing job changed it, the application rejects the stale save and keeps the submitted form values for review. Reload the current revision before completing the correction.

## MDB Verifier: review and approve

Open **Current revision validation**. Errors identify the affected PDF page, survey row and section where available. Resolve missing coordinates, ambiguous references, topology errors, incomplete fields and unapproved engineering inputs before final approval.

Use the read-only header, conductor and consumer review details to compare normalized values with the original transcription. Section links open the PDF page. Review branches, source connectivity, length estimates and engineering flags without automatically changing the network.

A verifier may approve a documented waypoint correction with its reason and evidence; the original WGS84 coordinates remain preserved. Recorded measured-length overrides and demand phase allocations have separate approval actions. These actions create a new data revision.

Use **Record review comment**, **Return for correction** or **Approve current revision**. Rejection requires a useful reason. Approval freezes an immutable data revision and the engineering configuration/template used for it. Editing approved data, changing applicable configuration or templates, or returning an MDB for correction invalidates approval and supersedes previous outputs. Review and approval must be repeated.

## Administrator: configuration and templates

Open a project's settings from the MDB dashboard. Enter an appropriate projected EPSG code with metre units, frequency, nominal voltage, equipment library revisions, documented load assumptions and the reviewed SynerGEE schema mapping. Use [MAPPING.md](MAPPING.md) for the JSON contract.

Provide a change reason and evidence. Check the engineering approval box only with the appropriate authorization. The form's empty JSON examples are placeholders; sample frequency, voltage and projection values are never approved defaults.

Register a genuine clean Microsoft Access MDB with a template code, version, exact SynerGEE product/version and metadata listing approved equipment references and permitted static tables. Existing network/control rows must be removed. The supplied sample network is an inspection fixture, not a clean template. The worker verifies schema, template integrity and cleanliness before export.

## Export and Analysis Team validation

1. After approval, choose an individual transformer or a consolidated feeder scope where the reviewed mapping permits it. The displayed template is bound to the approved immutable revision.
2. Queue the export. Failed jobs can be retried; repeated requests reuse the same idempotent job. If **MDB export worker not configured** appears, only an intermediate payload exists. The application does not claim that an MDB was generated.
3. A configured worker writes a genuine MDB and reads it back to verify counts, mapped values and node references. Check the exporter log and integrity hash. Only a current, approved, readable output is downloadable.
4. The Analysis Team records **Downloaded**, **Opened in SynerGEE**, **Connectivity checked**, **Load flow checked**, then **Accepted**, with version and results/evidence. Acceptance requires the prior checks. **Returned for correction** requires comments and resets approval/check progression; earlier events remain in history.

Application generation and readback do not imply SynerGEE engineering acceptance. The development environment has not completed an actual SynerGEE application validation. The handwritten 27-page PDF is not fully extracted or verified; every required header and row still needs manual checking. In particular, the sample PDF's waypoint references 878/879 are absent from the supplied GPX and block a complete source model until authorized coordinates are supplied.

## Operational dependencies

See [DEPLOYMENT.md](DEPLOYMENT.md) for migrations, permission seeding, private storage, queue and worker setup. This implementation and its tests do not migrate or alter the live application database. Browser visual and interaction verification remains pending because the in-app browser was unavailable in this development session.
