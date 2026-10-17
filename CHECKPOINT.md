# Mobile field-entry improvements — checkpoint

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
