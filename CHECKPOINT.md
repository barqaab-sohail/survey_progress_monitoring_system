# Mobile field-entry improvements — checkpoint

## Latest completed task: row media, transformer uniqueness and assigned-team groups (2026-10-17)
- Initial inspection: clean worktree. Rows/PV are JSON, photos have a separate local/server table; current photos/PV are survey-level. Team access is already authenticated and assignment-scoped.
- Plan: stable row UUIDs with nullable legacy media links; additive attachment migrations; row-level media UI; saved-transformer filtering plus transactional backend duplicate checks; authoritative team number defaults for new rows, original groups retained on existing rows.
- Row media implementation complete: stable row UUIDs in JSON; nullable attachment row_id in server/local DB; PV/photos added and viewed from each row; upload sends row ID after survey ACK. Legacy unlinked media remains unlinked, with explicit mapping for pending local photos. Linked-media row deletion is guarded.
- Transformer implementation complete: selector filters server and fresh local saved surveys (including unsynced); local transactional duplicate guard and normalized backend check under feeder lock. Historical duplicates remain editable without changing identity.
- Group implementation complete: bootstrap supplies assigned-team number; new rows derive last two digits, read-only UI, backend rejects tampering. Saved groups remain unchanged, including when legacy rows first receive UUIDs. Invalid/conflicting team numbers block new collection with an error.
- Verification complete: Flutter analysis and all 44 Flutter tests passed. Includes row-specific PV UI, read-only team groups, stable links across reorder/restart/queue, multipart row IDs, upload-after-survey-ACK, pending draft photos, duplicate filtering after restart/ACK, and non-destructive SQLite v1-to-v2 migration. Existing pairing/validation/search/fixed-URL regressions remain passing.
- API suite passed 23 tests/261 assertions, including team tampering/renumbering, foreign/missing PV/photo rows, idempotent attachment identity, photo-linked row deletion, legacy unlinked records/row-ID onboarding, duplicate claims across collectors, additive migration backfill and historical duplicate editing. Migration rollback test found an indexed-column drop issue; explicitly dropping its index fixed it. Five focused web sync regressions passed (69 assertions); read-only web review shows human-readable row/waypoint links.
- Debug APK built successfully (41.9s) at mobile/field_survey/build/app/outputs/flutter-apk/app-debug.apk. API/README/example payload documentation updated; PHP formatting and git whitespace checks passed. No new dependencies. Task-created logs removed after recording results.
- Remaining deployment only: backend pull, php artisan migrate --force, config:cache, view:clear, queue:restart; then install updated APK preserving signing identity/app data and refresh assignments. No production deployment, physical camera/gallery/Android testing, or real MySQL concurrent-worker test performed. Separate offline phones resolve conflicting new transformer claims at backend sync; rejected local data is retained. No local implementation blockers remain. Changes are uncommitted for the user's Git workflow.

## Previous completed task: paired submission and faster selection
- Requested: submission only at S/E stage after a valid pair, mandatory row fields in app/API, automatic E creation, stable pairing across edits/deletion, fixed production URL, feeder dialog matching transformer search.
- Existing uncommitted sign-in diagnostics must be preserved.
- Plan: optional pair IDs in existing JSON rows; infer legacy adjacent S/E pairs without changing observation values. Preserve incomplete drafts; validate all submitted pairs. Completing S opens its existing or newly created E. Deletion explicitly confirms removal of the pair.
- Fixed URL login is implemented, retaining other-server notebooks without sending them to production. Feeder selection now uses a transformer-style search dialog with code/name result rows, partial matching and a no-results state.
- Pair management, automatic E navigation, mandatory inline validation, incomplete-row highlighting and S/E-stage submission visibility are implemented. Local queueing and backend submission both reject incomplete pairs/required fields. Drafts remain saveable and confirmed pair deletion preserves other pairs.
- Checks: Flutter analysis passed; complete Flutter suite passed (37 tests), including automatic E navigation, submission visibility/blocking, pair editing/deletion, legacy orphan repair, cached project equipment options, zero values, fixed URL and feeder code/name search. API suite passed (18 tests, 193 assertions); web testing sync regressions passed (five tests, 66 assertions). PHP syntax and whitespace checks passed.
- Documentation updated with fixed URL, required fields, pairing semantics and deployment compatibility. No new database migration/dependencies required; pair IDs live in existing JSON rows. Existing queued payloads remain immutable and may require correction if an old incomplete submission is rejected by the new API rules.
- Implementation and verification complete. Debug APK built successfully (16.2s) at mobile/field_survey/build/app/outputs/flutter-apk/app-debug.apk. Temporary logs removed after recording results. No physical-device or production validation performed; changes are local/uncommitted for the user's Git workflow.

## Objective and resume
Implement the eight requested improvements, preserve offline/legacy data, and do not use Credits. Read this file and inspect git diff before resuming. No production changes, commit or push performed.

## Completed
1. Searchable assigned feeder selector (code/name, partial/full code, offline cache).
2. New rows alternate S/E, inherit group/date like web, leave existing rows unchanged; Int checkbox.
3. Numeric keyboards and digit-only pasted identifiers, kept as strings with zeros. New entry_version=2 validates API identifiers/equipment; untouched legacy rows remain accepted.
4. Local current date defaults; six-digit DDMMYY direct entry with calendar/year-window validation and ISO storage; invalid input cannot silently submit an old date; existing dates retained.
5. Read-only derived Phase immediately after conductors; recalculated on changes, copy, payload and API.
6. Equipment options from EntryLookups via cached bootstrap, preserving existing legacy choices.
7. Copy Previous Values copies conductors/equipment/class/height only; no GPS, identifiers, S/E, counts, PV, Int or remarks duplication. First row disabled.
8. PV service load and Meter Number in mobile/API/web PDF-entry, additive nullable DB column, PV projection, advanced forms, verification correction dialog, paper review columns, snapshots and export source records. Meter-only identity accepted. Null meter fields omitted in snapshots to preserve legacy hashes. API/form mapping documentation and example payload updated.

## Remaining
- Implementation complete. Changes remain uncommitted for the user's Git workflow.
- Production deployment and physical Android testing remain external steps; full web-page rendering is blocked by the pre-existing route issue described below.

## Tests completed
- Flutter analyze passed. All 24 Flutter tests passed, including feeder search, S/E/copy exclusions, dates, digits, equipment/phase, Int and SQLite PV queue/reload.
- All 24 JavaScript tests passed: 20 PDF-entry tests and four verification/dialog/paper tests, including meter recovery/save and correction retention.
- 56 focused PHP tests passed (339 assertions): FieldSurveyApiTest, SimpleMdbEntryValidationTest, MdbExportPayloadTest, MdbNetworkValidatorTest.
- Two new MDB PV tests passed (8 assertions): meter-only persistence and legacy snapshot compatibility. Advanced PV update test passed (13 assertions).
- Broader PHP run: 73 tests, 881 assertions, six page-render failures from the pre-existing missing engineering.index route. Other 67 passed. No unrelated route changes.
- Final Flutter debug APK built successfully at mobile/field_survey/build/app/outputs/flutter-apk/app-debug.apk (36.7s rebuild). No device/production testing performed.
- PHP syntax and git diff whitespace checks passed. No new dependencies were added.

## Test environment and deployment
PHP tests use APP_CONFIG_CACHE pointing at a nonexistent tmp path and E:/xampp/php/php.exe with -d extension=pdo_sqlite -d extension=sqlite3. SQLite :memory: only; never use application DB for tests.
After production pull: php artisan migrate --force; php artisan config:cache; php artisan view:clear; php artisan queue:restart. Deploy backend before updated APK, retain signing key/app data, refresh assignments for lookup cache. See mobile/field_survey/README.md.

## Cleanup
Task-created editing scripts and result logs removed after recording results here. Existing tmp/mdb-entry-qa fixtures/node_modules predate this task and remain untouched.

## Follow-up: production mobile sign-in (2026-10-17)
- Screenshot shows the correct HTTPS base URL but generic "Unable to connect" message. Working tree was clean when this follow-up began (previous implementation had been committed externally).
- Production HTTPS is reachable from this computer. Both curl and a Dart HTTP probe reached the API: unauthenticated bootstrap returned expected HTTP 401 JSON; empty login validation returned HTTP 422 JSON. No user credentials or authenticated session were used.
- The existing UI treated every unexpected exception as an internet failure, including storage and application exceptions. Added safe diagnostics distinguishing network/DNS, TLS, timeout, HTTP transport, phone storage and unexpected application failures. No certificate checks were disabled; offline data remains untouched.
- All 25 Flutter tests and final Flutter analysis passed. Diagnostic APK rebuilt successfully (54.2s) at mobile/field_survey/build/app/outputs/flutter-apk/app-debug.apk. Temporary Dart probe and task-created sign-in test/build logs removed after recording results.
- User confirmed the production login page opens normally in Chrome on the same phone. Actual app-specific cause remains unconfirmed; browser success alone does not establish Dart TLS/storage/API success. Next step: install the diagnostic APK without clearing app data and inspect the specific error if it persists.
