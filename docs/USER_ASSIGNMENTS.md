# User Assignment Management

Admins can open **User Assignments** from either administration menu or the existing team/workflow assignment screens. URL: `/legacy-admin/assignments`.

1. Search for and select a user. Their current and previous records appear together, with assignment type, status, project and dates. Filter by type, status, project or feeder/team/batch search. Pagination retains the filters.
2. Choose an assignment type. Unavailable types explain that the user's existing role or membership is required. This screen never grants roles or changes permissions.
3. Search for tasks and select up to 50 across pages. Mobile surveys and shared feeder allocations require an existing survey team membership. Use Survey Team Membership first if needed.
4. Review the selected tasks and confirm. A success message reports new and skipped allocations. Existing allocations are skipped without updating their dates, remarks or membership leader flags.
5. For workflow batches with another owner, explicitly authorize replacement and provide a reason. Pending mobile surveys have a Reassign action offering eligible members of the same team. Started/completed mobile work retains its owner, following the existing handler.

## Compatibility and data protection

- No migrations, database resets, data backfills, role/permission changes or production data edits are needed for this feature. Merely opening/searching the screen is read-only.
- Existing routes and handlers remain available. The hub delegates writes to the existing team/mobile/workflow handlers and adds confirmation, duplicate checks, stale-owner checks and a transaction around multi-item requests. A failure rolls back the complete request, including its audit writes.
- Mobile work in assigned, in-progress, draft, returned or submitted status prevents duplicate allocation to that surveyor. Overlap with another surveyor needs an explicit checkbox. The existing Reassign action transfers pending ownership and preserves the old row.
- Batch assignment does not change stage, revision, survey contents or approvals. Self-verification remains prohibited. Historical batch owners are read from existing `mdb.workflow_task_assigned` audit events; records before auditing existed cannot be reconstructed.
- Team membership and shared feeder/processing allocations are labelled separately from direct user tasks. The currently disabled MDB processing allocation workflow stays disabled; existing processing records remain visible. Third-party MDB verification continues using its existing access rules.
- The new routes use the same active-user, authentication and `super_admin` role middleware as existing administration. Additional roles are respected; this interface does not change them.
- Batch dates show batch creation dates because the current schema has no assignment-date column. Previous-owner dates show audit-event dates.

## Deployment

Deploy the new controller, three assignment services, view, CSS/JavaScript, routes and navigation changes through the application's normal Git deployment. Include `public/css/user-assignments.css` and `public/js/user-assignments.js`; no frontend build is needed. Refresh route/view caches through the normal deployment process if the server caches them. No assignment records, seeders, migrations or `.env` changes are required.

## Isolated verification

All PHP feature tests use SQLite `:memory:`. The existing test base refuses application databases. Use separate test cache paths so cached production configuration is not loaded or cleared. PowerShell example:

```powershell
$env:APP_CONFIG_CACHE='bootstrap/cache/config-assignment-tests.php'
$env:APP_ROUTES_CACHE='bootstrap/cache/routes-assignment-tests.php'
$env:ASSIGNMENT_QA_ARTIFACTS='tmp/assignment-qa'
php -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/UserAssignmentManagementTest.php

# These older manual-entry tests expect manual survey progress in the test process only.
$env:SURVEY_PROGRESS_AUTOMATIC='false'
php -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/TeamAssignmentTest.php tests/Feature/WorkflowTest.php tests/Feature/FieldSurveyApiTest.php tests/Feature/AutomaticSurveyProgressTest.php
```

The automatic-progress tests explicitly enable automatic mode in their own fixtures. Do not change production configuration to run these tests.

For isolated DOM and real-browser checks, generate the PHP fixture above, then:

```powershell
npm.cmd install --prefix tmp/assignment-qa --no-save --package-lock=false jsdom playwright-core
node --test tests/JavaScript/user-assignments.test.mjs
node tests/JavaScript/user-assignments.browser.mjs
```

The browser script uses installed Chrome (override with `CHROME_PATH`), intercepts every HTTP request, and never contacts Laravel or production. Screenshots are written under `tmp/assignment-qa`. The checks cover selection persistence across searches/pages, stale response protection, safe text rendering, review confirmation, duplicate clicks, reassignment and desktop/mobile layout.

Verification results: 14 new feature tests (170 assertions), 76 existing survey/team/workflow/automatic-progress tests (777 assertions), six DOM tests and the Chromium desktop/mobile checks pass. A broader MDB role-test run has one existing failure: `MdbOperatorEntryTest::test_dedicated_survey_roles_have_separate_server_enforced_rights_with_team_scope` posts header data without the required inspector and service category fields (HTTP 422). Its controller and fixture were not changed by this feature. The dedicated existing workflow-assignment test also passes (12 assertions).
