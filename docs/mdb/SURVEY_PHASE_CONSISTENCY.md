# Survey customer phases and section conductors

Implemented locally on 2 November 2026. No database migration or dependency change is required.

## Data mapping and source of truth

- The existing single-phase customer categories are `rs`, `sc` and `st`. The web PDF-entry form already has R/Y/B counts for these categories. They are not motor fields.
- Web entry rows retain allocations in `original_entry.consumer_phases[category][R|Y|B]`; `EntryRow::surveyData()` exposes the same values as `consumer_phases`. Existing scalar `consumers[category]` totals keep their existing normalization behavior. No duplicate fields were introduced.
- `EntryService::synchronizePair()` derives section conductors from the pair's **S row**. Both S- and E-row counts are checked against that S row, not against E's local conductor selections or PDF image content.
- Mobile/API rows use existing `consumer_phases` and `conductor_r/y/b` keys. The mobile form currently exposes RS/SC phase inputs; ST retains its existing scalar mobile presentation. Web ST phase inputs are included in the rule. Explicit pair IDs and legacy adjacent S/E pairs retain their established matching rules.

## Rule and draft behavior

Positive phase counts conflict with an explicitly absent conductor. Zero and blank counts do not create a contradiction. A missing S row or genuinely unrecorded conductor can remain in an incomplete draft. Submission, verification and export require conductor evidence for positive phase counts.

The web conductor selector now distinguishes **Unrecorded** from **None / Not Present**. The mobile selector also supports an explicit **None / Not Present** value. Removing a previously available conductor is checked against saved counts on both endpoints, including older clients that omit allocation metadata. Replaying a previous save cannot bypass the consistency check.

Unavailable phase fields cannot accept new values. An existing value remains visible and read-only with an error/help message; clearing it requires an explicit **Clear recorded value** action. No count is silently cleared, reassigned or used to invent a conductor. Mobile local recovery continues to retain unsaved changes; conflicting rows cannot be explicitly completed/submitted or accepted by server sync.

Messages identify the transformer, pair/section, waypoint, category, phase and count. Entry and correction screens show field messages and summaries. Submission/approval and model checks return the messages through their existing error summaries. Single-phase totals without recorded phase allocation keep their existing behavior; the new rule does not invent an allocation for them.

## Validation points and files

| Area | Files / behavior |
|---|---|
| Reusable backend rule | `app/Services/Mdb/SurveyPhaseConsistency.php`: availability, row-pair checks, legacy section/demand checks and mobile/API adaptation |
| Row saves and verifier corrections | `app/Http/Controllers/MdbOperatorEntryController.php`: validates the candidate row and its saved partner under the existing batch transaction, including idempotent retries; conflicts roll back |
| Submit / approve / review issues | `app/Services/Mdb/EntryService.php`: includes the shared rule in `issues()` / `assertComplete()`, already used by submission and verification approval |
| Advanced section save | `app/Http/Controllers/MdbWorkflowController.php`: validates allocated customer demand against the owning S row; advanced inputs cannot replace operator-owned conductors |
| Engineering checks | `app/Services/Mdb/NetworkValidator.php`: phase contradictions are errors in both Simple and full modes |
| Engineering export | `app/Services/Mdb/ExportPayloadBuilder.php`, `ConsumerPhaseConnections.php`: checks frozen source observations before model construction |
| Old approvals and cached exports | `app/Services/Mdb/ExportService.php`: checks the frozen revision at the common approval gate used by requests, retries, worker generation and downloads |
| Intermediate MDB / Transformer JSON | `app/Services/Mdb/DataEntryPayload.php`: validates the original entry rows before mapping; existing transfer/export callers inherit the check |
| API and server-side mobile corrections | `app/Http/Requests/SyncFieldSurveyRequest.php`, `app/Services/FieldSurveyService.php`: validates drafts/submissions and rechecks persisted values under transaction when conductors are removed |
| Mobile pair matching | `app/Support/FieldSurveyPairs.php`: exposes the existing grouping logic for reuse without changing pairing behavior |
| Reusable browser rule and field state | `public/js/survey-phase-consistency.js`: shared by entry and verifier correction screens |
| Web entry | `public/js/mdb-entry.js`: live checks on edits, paired-S availability, blocking save errors and explicit unknown/absent choices |
| Verification UI | `app/Services/Mdb/ReviewService.php`, `public/js/mdb-verification.js`, `public/js/mdb-verification-sheet.js`: phase allocations are displayed, checkable and editable; the correction dialog applies the same field restrictions |
| Inline styles | `public/css/mdb-workflow.css` |
| Mobile UI / validation | `mobile/field_survey/lib/models.dart`, `survey_editor.dart`: paired-S validation, disabled/read-only fields, explicit clearing, draft/Done/submission checks |

## Verification

Passed:

- Focused PHP acceptance selection: **61 tests, 610 assertions**, using SQLite `:memory:` and a separate nonexistent `APP_CONFIG_CACHE` path. Covers the shared rule, Simple-mode validation, API sync, entry saving, paired-S authority, zero/unknown drafts, removal/rollback, historical submission/approval, correction fields, advanced demand, idempotent replays and frozen exports.
- Additional PHP regression selection: **83 tests, 1,537 assertions** across `SurveyPhaseConsistencyTest`, `MdbNetworkValidatorTest`, `MdbExportPayloadTest`, `DataEntryMdbExportTest` and `FieldSurveyWebTest`. This overlaps the focused selection; the totals are not unique test counts.
- Browser logic and DOM selection: **13 tests** across `survey-phase-consistency.test.mjs`, `mdb-consumer-phases.test.mjs`, `mdb-verification.test.mjs` and `mdb-verification-sheet.test.mjs`. Includes inline errors, disabled/read-only controls, preserved values, explicit clearing and correction-save blocking.
- Focused Flutter selection: **18 tests** across `phase_consistency_test.dart`, `entry_improvements_test.dart`, `models_test.dart` and `widget_test.dart`.
- `flutter analyze`: no issues. PHP syntax checks and `git diff --check` passed; modified JavaScript modules passed Node syntax checks.

New focused tests are `tests/Unit/SurveyPhaseConsistencyTest.php`, `tests/JavaScript/survey-phase-consistency.test.mjs` and `mobile/field_survey/test/phase_consistency_test.dart`. Existing entry, API and frozen-model tests were extended. Existing success fixtures that placed phase counts on unrecorded/absent conductors were updated to explicitly record the corresponding conductor.

The entire repository test suite is not claimed to pass. Broader runs found unrelated existing header-required-field/page/topology tests and a meter-output fixture mismatch in `MdbNodeTableGenerationTest`. Seventeen existing PHP workflow failures reproduced using the original test file and unchanged header/workflow behavior. Four browser entry tests fail against both the original HEAD entry script and the changed script; a separate starting-pole test also has asynchronous teardown activity after its assertions pass.

The final complete Flutter run passed **60 of 62 tests**. Two existing sync/locking tests also fail when run separately in unchanged `LocalStore.ensureEditable`, with “This survey changed during sync”: `local_store_test.dart` (queue restart/immutable bytes) and `row_relationships_test.dart` (acknowledging row links before photos). Those locking behaviors were not changed for this task.

## Deployment

Changes are local and uncommitted. Deploy the backend, browser modules and stylesheet together, refresh cached browser assets and restart long-running Laravel queue workers. Rebuild/install the mobile client to expose its updated controls; the backend rejects contradictions from older clients too. There is no schema migration. Original records, historical revisions and previously generated files were not rewritten. A historical contradictory approval must be corrected and reverified before a new model or protected MDB download can proceed.
