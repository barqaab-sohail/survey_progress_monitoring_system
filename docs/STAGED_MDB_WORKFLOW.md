# Staged MDB survey workflow

New batches require `survey_pdf` and `gps_gpx`. Source parsing must finish before operator entry. The existing private source storage, GPX extraction, entry rows, SynerGEE snapshot/export writer, and final validation history remain in use.

New survey transformers are selected from the batch feeder's registered transformer list. Their codes are populated automatically and kept read-only. Existing saved survey transformers retain their stored codes.

Client-provided transformer information is optional for every transformer header, including the first. Survey submission freezes operator access. A separate survey verifier approves or returns the survey with remarks. Every contributor is retained in `entry_actor_ids`, so changing operators or assigning both roles cannot bypass separation of duties, including for administrators.

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

## Three-stage workspace

The primary tabs are **PDF Data Entry**, **Data Verification**, and **MDB Creation**, with batch counts scoped to the current user's accessible teams/projects. Source collection and incomplete entries appear as Draft, submitted surveys as Pending Verification, returned surveys as Returned for Correction, approved revisions as Verified, and batches with current generated exports as MDB Created. Existing stored status values remain unchanged for compatibility. All batches / legacy records retains access to earlier records; network processing and final SynerGEE verification remain available as secondary links.

The entry screen places the full-width PDF above the data-entry form on all screen sizes. Viewing height, zoom and rotation remain adjustable. Verifiers have an embedded PDF, original source downloads, GPX waypoint coordinates, saved entries, discrepancy flags that retain pending status, and mandatory correction comments. Entry contributors cannot approve their own surveys, including administrators. MDB controls appear on the review page only after survey approval; backend approval, source integrity, field mapping, and generation input checks remain mandatory.

An operator can choose **Reopen for Correction** on the review page of a verified batch, with a required reason and matching revision. This transaction revokes approval, clears current verification identity/date, marks all prior exports outdated, advances the revision, and records the operator in the contributor history. Editing, reviewing, resubmitting, and separate verifier approval are required before another export. Historical files and decisions are retained; outdated exports cannot be downloaded or retried as current files. Generation history shows status, actor, and date in PKT.

Validation uses SQLite in memory and fake private storage. On XAMPP where SQLite extensions are disabled by default, run `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit`. The staged workflow integration test uses the genuine Windows DAO/ACE exporter, generates and downloads an MDB, reopens and edits the approved survey, blocks stale generation/download, then approves and generates a new revision. It skips explicitly on hosts without the Windows writer.
