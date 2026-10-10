# Staged MDB survey workflow

New batches require `survey_pdf` and `gps_gpx`. Source parsing must finish before operator entry. The existing private source storage, GPX extraction, entry rows, SynerGEE snapshot/export writer, and final validation history remain in use.

The first transformer header requires client-provided information. Survey submission freezes operator access. A separate survey verifier approves or returns the survey with remarks. Every contributor is retained in `entry_actor_ids`, so changing operators or assigning both roles cannot bypass separation of duties, including for administrators.

Survey approval unlocks engineering processing and existing network approval. MDB generation requires a current approved network revision. Final MDB acceptance remains in the existing separate SynerGEE validation history. Returning an MDB reopens survey correction and requires survey approval again.

## Installation

Back up the application database and private storage, then run:

```console
php artisan migrate
php artisan db:seed --class=MdbWorkflowPermissionSeeder
php artisan optimize:clear
```

Assign the new roles through user administration: `survey_data_entry_operator`, `survey_data_verifier`, `mdb_generator`, and `mdb_verifier`. Existing team/project visibility still controls access; users must belong to the relevant survey, MDB, or processing team. Queue tabs show work within that scope.

The migration only adds columns. Existing batches default to the legacy workflow, preserving their existing approval revisions, permissions, source files, and MDB downloads. Role seeding adds permissions and does not revoke existing grants. No existing batch is automatically reclassified or regenerated.

Survey corrections, submissions, and decisions use the existing audit log, including actor, time, before/after values and remarks. Survey status and engineering approval status are separate columns.
