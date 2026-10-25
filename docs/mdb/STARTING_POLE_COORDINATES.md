# Starting pole coordinates for new transformer selections

When a registered transformer is selected and its new data-entry header is saved, the server checks that exact registration in the selected feeder. The first S uses valid coordinates from that record. The first E keeps its selected GPX coordinates. Later S/E pairs keep the existing GPX mapping rules.

If there is no registered record (manual entry), or its coordinates are missing/invalid, the existing fallback remains: the first S shares the first E's coordinates and recorded elevation. A unique, valid first E GPX point is still required for verification/export in either case. A later E cannot replace a missing first E.

Coordinate checks use the named `transformers.latitude` and `transformers.longitude` columns, finite numeric values and WGS84 bounds. `(0, 0)` is treated as an unset location. Imported PointX/longitude and PointY/latitude attributes, when available, must agree with those columns within the existing KMZ import tolerance (0.001 degrees). Conflicting order is rejected; coordinates are never automatically swapped. Valid zero latitude or longitude individually is supported. These checks establish consistency and validity; field survey accuracy still depends on the recorded observations. Without source evidence, two in-range numbers alone cannot prove their order.

Registry altitude is retained when finite; otherwise elevation is unknown. The first E's elevation is never borrowed for a registered first S. Existing length warnings handle missing elevation.

## Storage and compatibility

- No migration, backfill, registry edit, GPX edit or permission/workflow change is introduced.
- Only new data-entry transformer headers opt in, using `header.starting_pole_coordinates` (version 1). Valid choices include source, registration ID, latitude, longitude, optional elevation, feeder/code and registration update timestamp. The server captures this data; request-supplied coordinate metadata is ignored.
- Existing transformer headers keep their existing policy, including when opened or edited. Historical surveys and approved revisions/exports are not automatically converted.
- Coordinates are captured once at selection. Later GIS edits cannot silently change an entered survey, its frozen revision or offline JSON model. Read/display/export paths never look up live registry coordinates.
- The existing database `source_waypoint_id` remains the first E GPX evidence. The modelling layer creates a separate first-S node from the captured registration, and uses it as the network root. No synthetic GPX observation or database waypoint is inserted, and original references retain their leading zeros.
- Entry hints and verification show the actual registered latitude/longitude or the first-E fallback. Offline inspection reports the selected source too.

## Verification

Tests cover valid and invalid/missing coordinates, optional altitude, nonfinite/out-of-range values, conflicting latitude/longitude evidence, individual zero axes, manual fallback, immutable captured choices, server-side metadata protection, later poles and separate transformers, frozen revisions, verification display and offline projection/export with both legacy and complete survey identities.

Run PHP tests with SQLite `:memory:` and separate configuration/route cache paths. The repository's `Tests\TestCase` refuses application databases. PowerShell:

```powershell
$env:APP_CONFIG_CACHE='bootstrap/cache/config-coordinate-tests.php'
$env:APP_ROUTES_CACHE='bootstrap/cache/routes-coordinate-tests.php'
php -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Unit/MdbStartingPoleCoordinatesTest.php tests/Unit/MdbSurveyIdentityTest.php tests/Unit/MdbExportPayloadTest.php tests/Unit/MdbNetworkValidatorTest.php tests/Feature/SynergeeOfflineTest.php

$env:MDB_ENTRY_QA_ARTIFACTS='1'
php -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/MdbOperatorEntryTest.php --filter 'new_registered_selection|invalid_registered_coordinates|existing_headers_do_not|new_manual_transformer_without|first_e_supplies|first_pair_coordinate_rule|confirmation_and_complete|operator_markup_places'

# The DOM test uses the rendered registered-start.html fixture generated above.
npm.cmd install --prefix tmp/mdb-entry-qa --no-save --package-lock=false jsdom
node --test tests/JavaScript/mdb-starting-pole.test.mjs
```

The four coordinate/export test files and new coordinate unit tests pass (53 unit tests and 15 offline feature tests). Eight targeted entry tests, three new JavaScript tests, ten existing JavaScript helper tests and all 14 assignment-management tests also pass. The broader MDB workflow run has three failures reproduced against the prior implementation using isolated baseline class overrides: the Drive access expectation in `test_automatic_drive_storage_keeps_sources_processable_and_private_after_local_cleanup`, and missing capacity fixtures in `test_stale_edits_do_not_create_sections_or_invalidate_a_current_revision` / `test_section_and_pv_corrections_preserve_raw_values_and_page_associations`. An existing JavaScript row-validation test expects 201 ft to be invalid despite the prior implementation's 10,000 ft limit; baseline comparison confirms that result is unchanged. No unrelated validation or access rules were changed to make those tests pass.

Deployment requires the updated PHP classes, Blade view and JavaScript, including the new `StartingPoleCoordinates` service. No production data operation is required. These tests verify the coordinate mapping and export payload; native SynerGEE analysis is a separate existing acceptance step.
