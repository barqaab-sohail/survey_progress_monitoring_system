# Transformer MDB creation

Open **Create Transformer MDB** in the web sidebar (`/mdb-builder`). Access is currently restricted to super administrators, including workspace lists, source files, network review, editing, and MDB downloads. Other roles do not see the sidebar link and cannot access the endpoints.

## Paper survey workflow

1. Select **Enter paper survey** and choose the existing feeder. Enter one transformer's code, survey date, and administrative details. An existing GIS transformer may prefill reference values.
2. Attach the survey PDF and GPX. The PDF is a visual reference for manual transcription; scanned handwriting is not automatically extracted. Files stay in private storage. PDF limit: 50 MB; GPX limit: 10 MB.
3. Enter consecutive S/E pairs. Every S must be followed by its E. A pair defines one directed line section; put phase/conductors on S and consumer counts on E. Repeat waypoint identifiers to join sections or form branches. Expand handwritten ditto marks into their actual values.
4. Save to load GPX waypoint matches. Exact waypoint names are used, preserving leading zeros. Reused GPX names require the observation date to identify a unique waypoint. GPX timestamps are converted to the application's configured timezone. Route/track points do not replace named survey waypoints. Enter explicit latitude and longitude for a missing point, or replace the GPX with the correct file. Entering row coordinates takes precedence over GPX.
5. Set comma-separated transformer waypoint aliases and its confirmed latitude/longitude. The aliases represent the common transformer connection, including older transformer/pole waypoint identifiers on the paper form. Both endpoints of a transformer-only pair are retained in the survey audit tables without creating a distribution line. Such rows cannot carry consumers.
6. Review UTM zone, frequency, voltage, line configuration, spacings, height, transformer library type, and kVA per consumer category. Blank consumer cells remain unrecorded unless the user explicitly selects the zero-count convention. Categories with consumers require an entered kVA rate, including an intentional zero if applicable. Confirm the settings, save, then select **Review saved network / Create MDB**.
7. Review the section links, lengths, and network drawing. Resolve missing waypoints, incomplete pairs, disconnected sections, loops, or duplicate incoming sections. Select **Create and download MDB**.

PDF review is embedded in the form and can also open in a separate tab. The wide paper table scrolls horizontally. Surveys are saved explicitly, and navigation warns about unsaved changes. Large repeated rows are submitted as JSON to avoid PHP's `max_input_vars` truncation. Revision checks prevent another tab's saved work from being overwritten.

## Android surveys

The workspace list offers **Start from an Android survey** for the most recent 100 submitted surveys. Copying creates a separate editing workspace retaining the source survey ID. Collection, revisions, attachments, and sync behavior on Android remain unchanged. The workspace keeps the source feeder and preserves the copied observations. Missing S/E pairing or coordinates must be completed before export.

MDB creation does not automatically create daily progress entries or approve survey/MDB workflow items. Continue the existing progress and verification workflow separately.

## Sample findings

The three supplied samples were inspected read-only. `B2308.pdf` contains 27 scanned sheets. `B2308.gpx` provides named WGS84 waypoints. The MDB contains 50 SynerGEE Electric 5.0 network tables, including `Node`, `InstSection`, `InstFeeders`, `InstPrimaryTransformers`, `Loads`, and `SAI_Control`.

Two sample inconsistencies require attention:

- The first paper sheet uses transformer waypoints **878/879**, which are absent from this GPX. They must be explicitly identified as transformer aliases and linked to a confirmed transformer position, or supplied in another GPX. Coordinates are never guessed from a nearby point.
- The MDB header says **WGS 1984 UTM Zone 43N**, but its point coordinates agree with **42N**. For GPX waypoint 517 (`71.917033 E, 30.464656 N`), PROJ gives approximately `780080.397 E, 3373891.596 N` in 42N, matching the MDB's rounded node `780080, 3373892`. In 43N, the same point is `203980.044 E, 3374315.133 N`. Select the correct project zone; the coordinate chooser suggests the standard zone for its longitude.

## Output and modelling conventions

`resources/mdb/synergee-empty.mdb` is a schema-only, compacted derivative of the supplied sample. All 50 tables were verified empty; no sample transformer, customers, or network rows are bundled. Each export copies this private template and writes a new database transactionally through Microsoft's Access Database Engine. Failed outputs are removed. The uploaded samples are never overwritten.

The generated MDB contains network tables plus `SurveyHeader`, `SurveyRows`, and `SurveySolar` memo records holding the complete entered observations, consumer categories, original identifiers, settings, and source revision. Node IDs combine exact observation date and waypoint text; long identifiers receive a deterministic suffix to fit the sample's 32-character fields. Long narrative remarks are shortened only in the network's 100-character note columns; complete text stays in the memo records.

Distribution sections form a directed radial network rooted at the transformer. Lengths use projected WGS84 northern UTM coordinates in metres. Transformer supply/secondary connection sections are co-located with length zero rather than inventing survey distances. Phase letters are R/Y/B and optional N. A/W/GN map to ANT/WASP/GNAT; other conductor library IDs remain as entered. Consumers on E are summed by category and allocated equally across connected phases, with kVA calculated from the explicitly entered per-category rates. Int is retained separately and is not included in consumer counts. Pole types/heights, category details, and source annotations are retained as survey records.

Solar data is preserved in `SurveySolar`. The paper does not identify each generator's network node or electrical model, so generation is not inserted into electrical generator tables automatically. Assign those connections in SynerGEE.

Library IDs and technical defaults must be reviewed against the target installation's equipment library. The MDB has been reopened and its table contents verified through Access/OLE DB; a SynerGEE load-flow run has not been performed. The template supplies the sample's electrical defaults, with frequency initially 50 Hz. A compatible target equipment library is required for analysis.

## Server setup

This deployment exports on **Windows** using **32-bit Microsoft Access Database Engine (ACE OLE DB 12.0)** and 32-bit Windows PowerShell. The current XAMPP host already has these components. The PHP worker account needs permission to run PowerShell and write private export storage. `MDB_POWERSHELL` can override the executable path; `MDB_TEMPLATE` can select another compatible **empty** template. These settings are server configuration, not user-uploaded executable or template paths. No database or executable is supplied by a browser user.

Apply migration `2026_10_08_000100_create_transformer_mdb_projects_table.php`. Refresh configuration/route caches after deployment. The writer uses positional OLE DB parameters and verifies row counts before download. Export is rate-limited. Output files are deleted after being sent; uploaded sources and editable survey data remain available.

Relevant primary references: [GPX schema and WGS84 coordinate convention](https://www.topografix.com/gpx/1/1/) and [Microsoft OLE DB positional parameters](https://learn.microsoft.com/en-us/dotnet/api/system.data.oledb.oledbcommand.parameters?view=netframework-4.8.1).
