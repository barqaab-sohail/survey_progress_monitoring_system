# Quick User Setup and Assignment Guide — Technical Reference

Reviewed against this working tree on **31 October 2026**, Asia/Karachi. This separate support file accompanies the new five-page quick guide. It does not replace or amend `USER_CREATION_AND_WORK_ALLOCATION.md`, the existing mobile manual, or their existing Word/PDF files.

## Scope and choices in the quick guide

The guide uses the custom **Administration → Users** screen, not the alternative Filament Users resource. The custom create handler requires a unique email, exact primary role and a confirmed password of at least 10 characters; creation sets the account Active. Organization, phone and photo are optional for the three documented roles. An associated organization must be Active for access. An administrator must hand over credentials securely; these handlers do not send account invitations.

The three roles are `surveyor` (**Surveyor**), `survey_data_entry_operator` (**Data Entry Operator**) and `survey_data_verifier` (**Data Verifier**). A coordinator/team-leader role, MDB Verifier or legacy Verify Survey Entries menu is not a substitute for the PDF Data Verifier role. Do not grant additional roles/permissions to work around a missing assignment or self-review restriction.

**User Assignments** is used for personal mobile allocation because it provides a user history, duplicate checks, explicit review and confirmation. The PDF instructions use the existing **Workflow Task Assignments** screen for first allocation of unowned batches; the stage and assignee choices are direct. To replace an existing PDF owner, prefer **User Assignments**, which asks for the replacement reason and confirmation and protects against stale ownership. Assignment does not change the batch's stage, saved data or revision.

Team membership, shared feeder allocation, personal mobile allocation and PDF batch ownership are separate records. The mobile API checks effective assignment/team scope and dates. Team number must be unambiguous; code `01` and name `Survey Team 01` are a safe example. Do not silently rename an existing historical team to repair an invalid group.

## Workflow limits

- Mobile drafts and attachments are phone-local until submitted and uploaded. Check the form's **Synced** state and the individual photos' **Uploaded** states. A submitted transformer survey may move the feeder task to Submitted without establishing complete feeder coverage.
- Mobile uploads do not automatically create PDF/GPX batches. Obtain those sources separately. There is no complete independent Data Verifier/mobile returned-survey correction loop in the current Android implementation. Synced phone surveys are locked. Management-side corrections/support reconciliation are distinct from PDF batch verification.
- New staged PDF batches follow **Draft → Pending Verification → Verified**, with **Returned for Correction → Pending Verification** when needed. The review decision is for the whole batch. **Flag Discrepancy** keeps the batch pending and does not unlock entry. **Return for Correction** requires useful comments and permits amendments to the same batch.
- Verifier checkboxes are saved review aids; they are not the approval decision and the server does not enforce every tick as a prerequisite to Approve. The verifier must actually review all transformers and sources.
- Self-verification checks the entry operator, current entry assignee and recorded entry contributors. Moving an assignment or changing a role does not erase earlier authorship.
- Verified data is frozen. MDB generation is a later operation requiring its own creator, engineering settings/template and worker. It is outside this quick guide. Reopening a verified batch invalidates earlier approval/output and requires verification again.
- Old legacy batches can have different rules. The quick guide illustrates newly created staged batches, not automatic migration of historical records. Do not delete/recreate a batch, duplicate a transformer, reset a database or alter status directly to bypass these rules.

## Primary implementation evidence

| Topic | Source files |
| --- | --- |
| Role labels and user account fields | `app/Enums/UserRole.php`; `app/Http/Controllers/Admin/UserController.php`; `resources/views/admin/users/index.blade.php`; `app/Models/User.php` |
| Real navigation, teams and feeder scope | `resources/views/layouts/app.blade.php`; `routes/web.php`; `app/Http/Controllers/Admin/TeamController.php`; `resources/views/admin/teams/index.blade.php` |
| Central assignments and existing batch-stage assignment | `app/Services/{UserAssignmentManager,AssignmentChoices,AssignmentOverview}.php`; `resources/views/admin/assignments/index.blade.php`; `app/Http/Controllers/TeamWorkAssignmentController.php`; `resources/views/team-work/workflow.blade.php` |
| Mobile download, local drafts, submission and upload labels | `mobile/field_survey/lib/{main,survey_editor,feeder_picker,field_controller}.dart`; `app/Http/Controllers/Api/FieldDeviceAuthController.php`; `app/Services/{FieldSurveyAccess,FieldSurveyService}.php` |
| PDF sources, queues, entry and submission | `routes/mdb-workflow.php`; `app/Http/Controllers/{MdbWorkflowController,MdbOperatorEntryController}.php`; `app/Services/Mdb/{WorkflowAccess,EntryService}.php`; `resources/views/mdb-workflow/{create,index,show,review,workflow-actions}.blade.php` |
| Independent verification and status labels | `app/Services/Mdb/{WorkflowAccess,ReviewService}.php`; `app/Models/Mdb/SurveyBatch.php`; `database/seeders/MdbWorkflowPermissionSeeder.php` |

## Screenshot provenance and safe production use

Web images are browser captures of actual Laravel controller/Blade responses from the current working tree. The documentation capture harness runs through the guarded test base, which permits **only SQLite `:memory:`**. Users, project, team, feeder, source files and statuses are fictional training fixtures. Their names are examples, not real production assignments. Chrome runs the actual frontend scripts; every HTTP request is intercepted and no write request is submitted. The capture log records zero submitted writes and no JavaScript page errors. No source view/CSS is rewritten to invent interface controls; crops isolate existing controls and omit blank space.

The phone image is the existing `docs/mobile/manual/screenshots/34-synced-clean.png` capture from the Android manual. Its demo sync status is an illustration, not evidence of a live production upload. Its relevant controls/labels were checked against the current Dart source. The original file is reused as-is.

Build/capture sources are under `output/quick-user-setup/build/`. The new document is `output/manual/Quick_User_Setup_and_Assignment_Guide.docx`; its PDF preview is under `output/pdf/`. Page render images and extracted text are under `output/quick-user-setup/rendered/`. Original-manual SHA-256 values are recorded separately before and after generation. Word rendering is restricted to the new filename; the original manuals are not opened for editing.

The capture exercise checks implemented screens using isolated sample records. It does not create production users or prove that every deployed role, Android build, source-processing worker and assignment is configured correctly. Use the quick guide's final sign-in checklist with approved real records before onboarding a full team.

## Less common support issues

- If a permitted menu is missing, check the deployed release and installed role permissions. Do not seed/reset production merely to reproduce a screenshot. The Filament Users resource has different field labels and permission requirements; use the custom Users route documented in the quick guide.
- A shared feeder assignment marked Active can still be outside its start/end dates. Existing active allocations may be skipped rather than extended by an Add operation. Arrange an audited correction instead of creating overlapping work as a workaround.
- Before transferring a mobile task, check the original phone for unsynced drafts. Only unstarted pending tasks have the supported Reassign path; started/completed work keeps its owner. Device notebooks are not transferred to the replacement user.
- For queued/failed PDF sources, inspect the existing source worker and private logs; use the source-processing retry action where authorized. Creating repeated batches is not a fix for a stopped worker.
- For existing-account password resets, leave the password blank when retaining it. The custom Users screen requires confirmation for a replacement password. Forgot-password email delivery depends on production mail configuration.
