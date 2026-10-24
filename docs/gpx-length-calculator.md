# GPX Length Calculator & KMZ Export

Available from the application sidebar at `/gpx-calculator`.

## Workflow

1. Upload a GPX (maximum 10 MB). Optionally link it to an accessible active project and feeder.
2. Inspect the points and their IDs. GPX routes and track segments use their original adjacent points automatically. Separate routes, tracks and segments are never joined. Standalone waypoints are never connected automatically.
3. For waypoint connectivity or support spans, enter point IDs separated by commas, with one separate chain/branch per line. For example, `p1,p2,p3` and a second line `p2,p4`. Shared physical spans must appear only once. Confirm the connections. This example illustrates syntax; it is not a claim about the supplied survey.
4. Both **Include Elevation** and **Include Sag** start unchecked. Enable either independently. Sag also requires explicit sequences, confirmation of actual consecutive support endpoints and a default sag in metres. Omit intermediate GPS samples from support sequences. Individual span overrides are available in the results table. Changing a sequence clears confirmations and turns sag off.
5. Missing elevation with elevation enabled requires an explicit global choice: use horizontal distance for affected spans, or exclude those spans. No implicit zero elevations are introduced. Blocked spans prevent a final total/export; explicitly excluded spans are reported separately. At least one included span is needed to export.
6. Changes produce a live server-calculated preview. **Save calculation** persists settings/results. **Export KMZ** recalculates and saves the current form, then downloads the archive. Preview requests do not write to the database.

Linking a calculation does not overwrite feeder progress or official feeder length records. Calculations store parsed GPX points/groups, the source file hash, options, confirmations, overrides, detailed span results and warnings. Numeric results remain full-precision floating-point values; rounding is for display only (metres to three decimals, kilometres to six).

## Sample inspection

`tests/Fixtures/gpx/Waypoints_2026-10-08.gpx` is a copy of the supplied sample:

- 120 standalone waypoints, named `001` through `120`.
- All 120 have valid coordinates and elevations.
- Zero routes, route points, tracks or track segments.
- No explicit support connectivity or attachment heights.

It therefore imports with **zero inferred spans**, no final length and a connectivity warning. Actual span/branch connections require survey confirmation; numerical point names, XML order and recording time do not establish topology. Automated/browser tests use explicitly declared test connections only and do not establish this sample's actual network or feeder length.

## Calculation specification

For each selected span, let `H` be the WGS84 ellipsoidal geodesic distance between its endpoints, `dz` the elevation difference, and `s` the user-entered sag (metres).

- Reuses `App\Services\SurveyProgress\SpanCalculator::horizontal()`, the existing Vincenty inverse calculation on WGS84. Failed/nonconvergent geodesics are blocked with a review warning; no spherical approximation is substituted.
- Elevation disabled: `dz = 0` for length calculation; original GPX elevations remain available as attributes.
- Elevation enabled with available elevations: `dz = end elevation - start elevation`.
- Straight span length: `hypot(H, dz)`.
- With sag: for `t` from 0 to 1, `z(t) = dz*t - 4*s*t*(1-t)`.
- Estimated conductor length: integral from 0 to 1 of `sqrt(H² + (dz - 4*s + 8*s*t)²) dt`.
- The parabola is below the straight chord by `4*s*t*(1-t)`, so its maximum vertical chord-relative drop is exactly `s` at the midpoint, including with unequal endpoint elevations.
- Adaptive Simpson integration targets 1e-9 m absolute quadrature error with a recursion cap of 18. This is numerical integration precision, not survey/engineering accuracy. Explicit zero sag returns `hypot(H,dz)` directly and exactly reproduces the corresponding straight result.
- Additional sag length is the sum of each included span's curved length minus its straight length. Final length is the sum of individual included span lengths. No phase or conductor count multiplier is applied.
- A zero horizontal span with positive sag is undefined and blocked; zero sag can still reproduce a vertical-only straight result.

The parabolic conductor model assumes uniform vertical loading, shallow sag and local flat-span geometry. It does not solve a catenary, tension, clearances, thermal expansion, wind, ice loading or attachment offsets. Sag greater than 5% of `H` receives an engineering-review warning; this is a review threshold, not a claim that every smaller ratio is suitable. The unusually-long-span warning threshold is independently configurable (default 500 m).

Totals distinguish all defined valid horizontal distances from included straight/final lengths. Excluded spans and unconnected points remain visible and do not silently enter the final length. A pending/invalid calculation shows a subtotal, not a final total. Duplicate coordinates/names are retained with warnings; reversed/repeated definitions of the same point-ID span cannot double-count it. Invalid points break automatic connectivity and are never skipped to bridge the surrounding points.

References: [Vincenty, inverse/direct geodesics](https://www.ngs.noaa.gov/PUBS_LIB/inverse.pdf), [USDA RUS Bulletin 1724E-152, overhead conductor mechanics](https://www.rd.usda.gov/files/UEP_Bulletin_1724E-152.pdf).

## KMZ behavior

- A ZIP archive containing exactly `doc.kml`, with KML 2.2 namespace and longitude, latitude, altitude coordinate order.
- Includes all valid surveyed points, separate span LineStrings, source/sequence folder names, selected options, per-span values/status, warnings, and the application's total in descriptions/ExtendedData. Invalid-coordinate points cannot be plotted and are reported in document warnings.
- Elevation disabled: points and lines use `clampToGround`. A zero in an ignored KML altitude coordinate is not a missing-elevation substitution in the calculation.
- Elevation enabled: known GPX elevations are preserved using `absolute`. **Unverified datum assumption:** values are treated as metres above mean sea level for KML, without conversion; a source using ellipsoidal heights may need a geoid correction. GPX survey/ground elevations are not represented as conductor attachment heights.
- Missing-elevation points, and span lines with either elevation missing, are clamped to ground and retain the original known/missing elevation attributes. Explicitly excluded spans are styled grey and labeled excluded.
- Sag is **attributes only**. Lines join surveyed endpoints; no sagged conductor curve is generated because attachment elevations are not collected/verified by this module. Choosing support endpoints does not certify the elevation datum or attachment heights.
- Google Earth's own terrain/geometry measurement can differ from the application's WGS84/parabolic calculation. The application-calculated values and options are explicitly included.
- File names/point names/descriptions are escaped as XML, and description contents are additionally escaped for KML's HTML interpretation. Temporary archives are deleted after sending.

References: [Google KML altitude modes](https://developers.google.com/kml/documentation/altitudemode), [Google KMZ archives](https://developers.google.com/kml/documentation/kmzarchives).

## Access and security

The module uses existing authentication, active-account/organization checks and role/permission services. Super Admin, Project Manager, Survey Team Leader and Surveyor can use it; other users require the existing workflow Upload ability (including Data Entry Operators with that permission). Other users cannot access another creator's calculations; Super Admin and Project Manager have administrative access. Non-administrative project/feeder associations reuse `FieldSurveyAccess`, including active membership/allocation dates and surveyor task restrictions. Revoked associations remove access to the linked record for non-administrators. Unlinked calculations remain private utility calculations.

All state-changing requests use the existing web/CSRF stack and rate limits. Inputs are validated server-side; clients cannot supply trusted calculated totals. GPX uses DOM parsing with networking/external resolution/entity substitution disabled. DTDs, entity declarations, NUL-containing encodings and oversized files are rejected. At most 10,000 points, 1,000 source groups and 10,000 span edges are accepted. Upload, save and export actions use the existing audit log. No new production packages are required; existing PHP DOM/libxml and ZipArchive extensions are used.

## Changed files

| Files | Purpose |
| --- | --- |
| `app/Services/Gpx/{Parser,Calculator,KmzExporter,Access}.php` | Parsing, geometry, sag, KMZ generation and scoped access |
| `app/Http/Controllers/GpxCalculatorController.php` | Upload, preview, persistence and download endpoints |
| `app/Models/GpxCalculation.php` | Saved source/settings/results and project/feeder relationships |
| `database/migrations/2026_10_21_000001_create_gpx_calculations_table.php` | New calculation table with existing-user/project/feeder foreign keys |
| `routes/web.php`, `app/Providers/AppServiceProvider.php` | Routes and module gate |
| `resources/views/gpx-calculator/{index,show,association}.blade.php` | Upload/history, calculator and scoped association controls |
| `resources/views/layouts/app.blade.php` | Authorized sidebar navigation |
| `public/js/gpx-calculator.js`, `public/css/gpx-calculator.css` | Live previews, confirmations, overrides, responsive styling |
| `tests/Unit/GpxCalculatorTest.php`, `tests/Feature/GpxCalculatorFeatureTest.php` | Numerical/topology/export and authenticated integration checks |
| `tests/Fixtures/gpx/Waypoints_2026-10-08.gpx` | Supplied sample regression fixture |
| `tests/Fixtures/kml22/` | Official KML/Atom/xAL schemas for offline export validation |
| `docs/gpx-length-calculator.md` | Usage, assumptions and verification record |

## Verification (21 October 2026)

- **15 tests / 167 assertions pass**: horizontal-only, elevation-only, sag-only, combined, exact zero sag, individual overrides, explicit support span vs GPS samples, missing-elevation choices, disconnected routes/track segments, invalid/duplicate coordinates, long spans, nonconvergent geodesics, secure XML rejection, the actual sample, KML escaping/altitude modes/precision, KMZ archive structure, preview/save/export and access/assignment restrictions. Exported KML validates against the official KML 2.2 XSD in all four sample calculation modes, plus both altitude modes in the escaping/precision test; schema fixtures resolve locally without network access.
- Command on this XAMPP installation (SQLite is enabled only for the test process): `php -d extension=pdo_sqlite vendor/bin/phpunit --filter GpxCalculator`.
- Pint passes for the new PHP services/controller/model/migration/tests. `node --check public/js/gpx-calculator.js` and `git diff --check` pass.
- Real Chrome browser check against an isolated SQLite database: sample upload; both options initially off; manual separate branches; connection/support confirmation; automatic elevation/sag and override recalculation; save/reload; real KMZ download; confirmation reset on sequence editing; desktop/mobile layout; no page JavaScript errors. Both views were visually inspected. Test-created records are confined to the disposable database.
- A broader run of the module plus existing geometry/MDB/team tests ran 68 tests before the final additional edge-case test: 60 passed, eight failures and one risky test. A separate clean sparse worktree at baseline commit `b531788` reproduces the exact same eight failures and risky test among 54 existing tests. They predate this module: three failures in `MdbWorkflowTest` (Drive source access and missing `capacity_kva` expectations), five in `TeamAssignmentTest` (MDB membership and survey routes redirecting under automatic mode). These existing workflows were not modified.
- Applied the new migration to the application database and refreshed the route/config caches. No existing migrations or feeder length/progress records were changed. The temporary browser server/database and baseline worktree were removed after verification.

Deployment elsewhere: apply the new migration and clear/rebuild Laravel route/config caches using the site's normal deployment workflow. PHP DOM and ZIP must be available. Enable SQLite for test commands if it is not enabled in the CLI configuration; the test suite refuses to reset a non-memory database.


## Verified transformer calculations

The calculator index offers an approved survey selector (project, feeder, batch and revision) and its transformer selector. No upload is needed. The server checks access to the active project and feeder, current approval, revision number, snapshot checksum, complete S/E pairs, matching stored GPX coordinates and connectivity. The first S/E pair locates the transformer at the first E point and contributes no line length. Remaining S/E pairs define spans and branches; unrelated points are excluded.

A saved calculation retains its approved revision and checksum. Its connections are fixed in previews and exports, including when a client submits altered sequence fields. Elevation and sag default to off and can be enabled independently using the existing calculation options. Sag requires a value in metres; missing elevations require an explicit fallback or exclusion choice. KMZ includes survey provenance and the calculation settings. A later approval requires a new calculation; previous calculations remain labelled with their original revision.
