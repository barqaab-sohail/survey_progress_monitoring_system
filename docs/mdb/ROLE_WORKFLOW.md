# Three-role survey and MDB workflow

## Roles and account assignment

- Data Entry Operator (`survey_data_entry_operator`): view, upload survey sources, edit draft/returned entries, submit.
- Data Verifier (`survey_data_verifier`): view, approve submitted survey data or return it with comments.
- MDB Creator (`mdb_generator`): view, prepare generation files, generate and download approved MDB outputs.

Dedicated role grants are synchronized by `MdbWorkflowPermissionSeeder`, including removal of the former creator processing/verification grants. Additional roles/direct permissions cannot broaden a dedicated role. Combining dedicated roles retains only shared abilities. Administrator access remains available, with the same staged entry locks and contributor self-approval prohibition.

Role assignment is explicit and requires project/team visibility. Third-party organization type, the Third-Party Processor designation and Project Manager status do not imply either verifier or creator rights. Locally, MDB User retains Data Entry Operator; the earlier verifier assignment and verification-only team membership were removed from Third-Party Processor after the user's clarification. Data Verifier and MDB Creator have no assigned accounts pending explicit selection.

## State and approval

`entry / returned → awaiting_verification → approved`, or `awaiting_verification → returned` with mandatory correction comments.

Submission, return and approval are transactional, reject stale revisions, identify the actor, and create a timestamped audit event and frozen decision revision. Approval sets both survey approval and the current immutable MDB approval revision. The creator cannot edit, submit, approve, correct coordinates, approve demand/lengths, or use legacy network transitions. Approved data cannot be edited by an operator. Authorized changes to data/settings revoke approval and supersede outputs; the survey requires resubmission and a fresh verifier decision.

## Generation inputs

Generation files are private records in `mdb_workflow_generation_inputs`, separate from survey PDF/GPX sources. Only the MDB Creator can add/read them after approval. KMZ uploads must contain valid KML and are retained without archive extraction. The latest KMZ and latest base file per filename join the approved revision's PDF/GPX and approved MDB template in a frozen input manifest. A new package changes the export idempotency key, while a repeated request for the same package reuses its job.

The approved MDB template is always required. Configure any additional mandatory base/reference filenames in its metadata, for example:

```json
{"required_base_files": ["reviewed-equipment.csv", "project.prj"]}
```

The generation screen displays these filenames and rejects requests missing them. Base files and KMZ are preserved references for the generation package; they do not automatically override approved survey values or GPX coordinates. The existing exporter builds mapped tables from the approved survey/configuration and the approved MDB template. Engineering mappings, demand approvals and a configured DAO/ACE worker remain required; source/reference attachments alone do not establish an engineering model.

The manifest is retained in export settings and the approved payload. Input SHA-256 values are checked at request, retry, worker start, completion and download. Missing/changed inputs or superseded approvals block the output. Original files are never overwritten. Upload, request, generation, retry and download actions store users and timestamps; review shows workflow history and generation inputs show their uploader/time.

## Deployment and checks

Run migrations, then `php artisan db:seed --class=MdbWorkflowPermissionSeeder --force` and `php artisan permission:cache-reset`. The additive `2026_10_11_000100_create_mdb_generation_inputs.php` migration is applied locally. Do not assign creator/verifier accounts based on an external designation or job title.

Run the survey/MDB PHP feature suites and `node --test tests/JavaScript/mdb-entry.test.mjs`. JavaScript DOM tests require rendered `operator.html`, `operator-workflow.html` and `readonly.html` fixtures; generate them with `MDB_ENTRY_QA_ARTIFACTS=1` and the operator markup / role-specific page feature tests. Tests exercise forbidden cross-role HTTP requests, submitted/approved locks, return/resubmission, frozen approvals, file requirements, unchanged survey hashes, stale/tampered downloads, readonly PDF navigation and submission with unsaved drafts.
