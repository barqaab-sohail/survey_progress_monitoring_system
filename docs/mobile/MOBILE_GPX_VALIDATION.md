# Mobile GPX implementation validation — 6 November 2026

## Results

- The new MobileGpxWorkflowTest suite passes: 9 tests, 100 assertions. It covers role-only login and old-token denial, account/team assignment, historical first-S identity, assigned-group enforcement, shared GPX reuse, missing/ambiguous points, warning explanations, project scope, stale confirmations, wrong filename groups, foreign sources, changed original bytes, complete GPX names, and rejected forged previews.
- The confirmed mobile JSON passes the existing offline SynerGEE reader/mapper and produces a genuine MDB on Windows DAO/ACE. Generation includes the existing schema, field, relationship and coordinate readback checks. The regression test deletes only its temporary generated MDB after verification.
- The earlier focused API/web/node/mobile regression run passed 67 tests and 1,020 assertions. The final broader relevant PHP run completed 120 tests with 1,581 assertions: 119 passed and one existing recorded-phase expectation failed, described below.
- Assignment/seeder tests pass: 31 tests, 404 assertions. The legacy manual-entry TeamAssignmentTest cases were run with SURVEY_PROGRESS_AUTOMATIC=false in the test command's environment; production configuration was not changed.
- Flutter analysis passes. The full Flutter suite reports 60 passes and two existing storage-lock failures, described below. The affected entry-improvement and submission UI suites also passed independently: 24 tests.
- PHP formatting, Dart formatting and whitespace checks pass. The new Blade pages are exercised by feature requests and compile successfully.
- The additive migration was applied to the local application (batch 4), the Mobile Survey User role was seeded, and the local HTTP login page returned 200. No example account/password was created and existing accounts were not silently reassigned.

PHP feature tests use SQLite :memory: guarded by Tests/TestCase.php. XAMPP CLI has SQLite disabled in its normal configuration, so tests were run with `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit ...`. No application database was used by these tests.

## Existing failures checked against baseline 4a3d1e3

- `local_store_test.dart`: queue survives restart with immutable outgoing bytes and stable UUID.
- `row_relationships_test.dart`: sync acknowledges row links before photos and holds photos while rows are drafts.

Both also fail in an isolated, untouched archive of the baseline mobile application. Their error is the existing rejection of editing a server-acknowledged record: "This survey changed during sync. Reopen it before editing; the saved version is preserved." The storage implementation was not changed by this work.

- `SynergeeOfflineTest::test_recorded_phase_counts_export_to_a_genuine_mdb_with_verified_values` expects 16.0 kVA while the current configuration produces 24.0. The same assertion fails when loading the original baseline NodeTableGeneration, ExportPayloadBuilder, NetworkValidator and EntryService classes through an isolated class map. No unrelated engineering rates were changed to satisfy this assertion.

## Android artifact

- File: `output/android/HAZECO-Field-Survey-v1.0.5-code6-update.apk`.
- Application ID: `com.barqaab.hazeco.hazeco_field_survey`.
- Version: 1.0.5, code 6; build command overrides leave pubspec.yaml unchanged.
- Minimum Android API: 24. Target API: 36.
- APK SHA-256: `5cf5554034eee7bda889f7f061165b5e2e864e83107be9ea22c8c8911e717e58`.
- Signing certificate SHA-256: `f02755733d956280038fd201f684e08a2ba9c4e82ed8b32924d95ba15fbc5e3a`.
- The pre-existing local signing key and this certificate were compared against the saved v1.0.4 / code 5 update APK before building. No substitute key was created. The new APK signature verifies and the certificate matches. ZIP alignment with 16 KB page alignment passes.
- No phone installation/uninstallation was performed. The remote production server was not deployed by this workspace change; deploy the matching PHP changes and migration before using the APK against its fixed production URL. Update the local Windows converter with the same source changes for mobile provenance support.

See [account setup and creator workflow](MOBILE_GPX_WORKFLOW.md).
