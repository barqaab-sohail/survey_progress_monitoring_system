# Administrator guide: creating users and assigning survey, PDF entry and verification work

**Reviewed: 29 October 2026.** Based on this checkout's routes, Blade/Filament screens, Android source, permission rules and assignment handlers. The effective local route table was also checked. No production users, assignments or records were created during this review. Check that the deployed website and Android app contain the same features before using this guide.

## 1. Choose the correct role and workflow

Use the labels below exactly. A user account, team membership and a work assignment are three separate things.

| Person you are creating | Select this **Role** | Work they receive | Where they work |
| --- | --- | --- | --- |
| Surveyor / Mobile Survey User | **Surveyor** | A personal **Mobile App Survey** assignment for a feeder, within a survey team | **HAZECO Field Survey** Android app |
| PDF Data Entry Operator | **Data Entry Operator** | **Data Entry** ownership of an existing survey batch | Website → **MDB Creation & Validation → PDF Data Entry** |
| Data Verifier | **Data Verifier** | **Data Verification** ownership of a submitted survey batch | Website → **MDB Creation & Validation → Data Verification** |

There is no separate role called “Mobile Survey User” or “PDF Data Entry Operator” in the role picker. **Data Entry & Verification Team Leader** is a coordinator role, not the Data Verifier role. **MDB Verifier**, **Verify Survey Entries**, and **Verify MDB Files** belong to other/older workflows and are not substitutes for PDF **Data Verification**.

**The two workflows are separate:**

- Mobile: survey team → personal feeder assignment → Android survey → submit/sync → **Mobile Field Surveys**. The currently implemented mobile path does not provide a complete independent Data Verifier approval and mobile correction/resubmission cycle.
- PDF: project/team/feeder → PDF + GPX batch → Data Entry owner → submit batch → Data Verification owner → approve or return → operator corrects/resubmits → verifier approves. This is the complete verification workflow covered below.

A mobile upload does **not** automatically create a PDF/GPX batch. Do not wait for it to appear in the PDF verification queue. Arrange the source PDF and GPX separately if the same field work must also enter that workflow.

## 2. Navigation and prerequisites

Use an **active Super Admin** for this guide. A Project Manager can access parts of administration, but does not have the same access to the custom user/assignment screens. The Super Admin can complete all allocation steps without creating coordinator accounts.

Paths below are relative to your application address. For the current Android release, that address is `https://hazeco.barqaab.pk`. If the website is installed in a subdirectory, keep that subdirectory before these paths. Prefer the menu links, which generate the correct URL.

| Screen/menu label | Actual path | Purpose |
| --- | --- | --- |
| **Administration → Users**; page heading **User Management** | `/legacy-admin/users` | Recommended account creation/edit screen for this guide |
| **Administration → User Assignments** | `/legacy-admin/assignments` | Central, user-first allocation and history screen |
| **Administration → Teams & Assignments** | `/legacy-admin/teams` | Team creation, membership, shared feeder allocation |
| **Administration → Master Data & Import** | `/legacy-admin/master-data` | Feeder master data and CSV import; use its **Manage hierarchy** link |
| **Administration → Audit Log** | `/legacy-admin/audit-log` | Assignment and workflow audit history |
| **Survey Task Assignments** | `/team-work` | Personal feeder tasks, status, pending reassignment and mobile return comments |
| **Workflow Task Assignments** | `/team-work/workflow` | Batch owners for entry, verification and MDB creation |
| **MDB Creation & Validation** | `/mdb-workflow` | PDF entry and verification queues |
| **Mobile Field Surveys** | `/field-surveys` | Server copies of Android surveys |

There is also a Filament **Users** resource at `/admin/users`. It is a different screen. The panel's **Administration → Users** shortcut opens `/legacy-admin/users`. If the page offers **New user** rather than **Add user**, you are on the Filament resource; see the alternative creation instructions below.

No project, feeder or batch is needed merely to create the three user accounts. Prepare the records below **before assigning work**.

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| P1 | Super Admin | Website login (`/login`) → **Sign in to dashboard** | Sign in with the administrator's own account. Confirm **Administration**, **Users**, **User Assignments**, and **Teams & Assignments** are visible. | You have the required administration access. An ordinary operator/verifier should not receive these powers. |
| P2 | Super Admin | **Master Data & Import → Manage hierarchy** | If missing, create the hierarchy in order: **New project → Add project** (Code, Name, Timezone `Asia/Karachi`), **Add circle**, **Add division**, **Add sub-division**, **Add grid station**. Select parents from the same project. | A consistent project/circle/division/sub-division/grid-station hierarchy exists. New projects created here are Active. |
| P3 | Super Admin | **Master Data & Import** | Use **Download CSV template**, fill its actual columns, then choose **CSV file → Import master data**. Check import errors and confirm the intended feeder is Active and in the intended project. | Feeders exist under the correct hierarchy. Use real feeder codes, not similar names from another project. |
| P4 | Super Admin | **Teams & Assignments → Create team** | Select **Type: Survey**, the **Project**, and enter **Code** and **Name**; click **Create team**. For example, Code `01`, Name `Survey Team 01`. | An Active Survey team exists in the project. All three roles in this guide can belong to it. |
| P5 | Super Admin | **Teams & Assignments → Survey Teams** | For mobile teams, check the team code/name provide the same unambiguous trailing team number. `01` and `Survey Team 01` give Group `01`; avoid a code ending `01` and a name ending `02`. | Android can determine the required two-digit team group. Otherwise **Create survey** can be blocked with “No valid team number.” |
| P6 | Super Admin | **Filament Administration → Transformer KMZ Imports** (optional) | If imported transformer choices are required, use **Bulk import KMZ files**, map each **Link to existing feeder**, choose its **KMZ file**, and review the import result. | Valid transformer master records appear for that feeder. Count-only/warning imports may update quantities without creating usable transformer details. Review warnings before relying on the picker. |
| P7 | Super Admin | **Users / Organizations** | Confirm each person is Active. If an organization is selected, it must remain Active for field/PDF workflow access; **None** is permitted for these three roles. | Access is not blocked by an inactive account or organization. |
| P8 | Super Admin | **MDB Creation & Validation** | Confirm the appropriate PDF queues are visible to the relevant role during the login checks below. Have a real readable Survey PDF and matching GPS GPX available. | Required workflow permissions and source processing are installed. If menus/permissions are missing, this is a deployment/support issue; the account form does not install them. |

Imported transformer master records are helpful but are not a prerequisite for user creation or feeder assignment. Mobile collection supports entering a transformer code when a reference is unavailable; PDF entry also offers **Add Transformer** for an unlisted code. Confirm the real transformer identity first. Creating a survey header does not itself create a transformer GIS master record. Duplicate saved transformer identities can be rejected.

For PDF entry, both source files must reach **Ready** before entry/verification operations can succeed. Local uploads are supported; Google Drive import is optional. An approved engineering template/Windows MDB worker is needed for later MDB generation, not for creating these users or allocating ordinary entry/verification work.

## 3. Create and activate the users

Follow U1–U5 once per person. Use separate people/accounts for entry and verification.

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| U1 | Super Admin | **Administration → Users → Add user** | Enter **Name**, a unique **Email**, the appropriate **Role**, **Password**, and **Confirm password**. Use at least **10 characters** for this screen. | Required account details are complete. The email is the login name. |
| U2 | Super Admin | Same form | Select **Organization** or leave **None**. **Phone** and **Profile picture** are optional. Pictures: JPG/PNG/WebP, up to 2 MB and 4096 × 4096 pixels. | Optional contact/organization information is recorded without granting extra access. |
| U3 | Super Admin | Same form → **Create user** | Create the account with Role **Surveyor**, **Data Entry Operator**, or **Data Verifier**, as appropriate. | “User account created.” The custom form creates the account **Active** and synchronizes its primary application role. It does not create a team membership or task. |
| U4 | Super Admin | **Users → Existing users → [person]** | Confirm **Role** and **Status: Active**. If changing an existing record, click **Save user**. Leave **New password (leave blank to keep)** empty unless resetting it; otherwise enter and confirm a new password. | Account remains usable. Seeded demonstration accounts other than the Super Admin start inactive, so their presence alone does not prove they can log in. |
| U5 | Super Admin | Secure handoff outside the app | Give the person the correct app/website address, their own email, temporary password, and instructions for their role. | The person can sign in. There is no automatic invitation/activation email or mandatory first-login password-change flow in these creation handlers. |

**Filament alternative, if that is your administration screen:** `/admin/users` → **New user** → enter **Name**, **Email address**, **Role**, **Status: Active**, **Password**; choose Organization/Phone/Profile picture as required → **Create**. The Filament form has an 8-character minimum and no confirmation field; use 10+ characters consistently anyway. To amend an account use **Edit → Save changes**; leave Password blank to retain it. Filament user administration requires its resource permissions (for example ViewAny/Create/Update User) in addition to panel access. The custom **User Management** path above is the least ambiguous route for this guide.

Normal operators do **not** need Filament administration access or individual permission checkboxes. With the standard permission setup:

| Role | Existing permissions/access | What it does not confer |
| --- | --- | --- |
| Surveyor | Mobile login/collection eligibility, plus valid personal/team/feeder allocation | PDF Data Verification, batch administration or automatic rights to every team feeder |
| Data Entry Operator | View, Upload, Edit, Submit in the PDF workflow, subject to ownership/team and workflow state | Approval of survey data |
| Data Verifier | View and SurveyVerify, subject to assignment/team, workflow state and separation of duties | Creating/editing entry-stage batches as an operator, or final engineering MDB verification |

Do not combine entry and verification roles to try to bypass a restriction. Anyone recorded as an entry contributor, entry operator or current entry assignee cannot be assigned to verify that batch. Reassigning the operator later does not erase earlier contributions.

**Login checks**

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| L1 | Surveyor | Approved Android **HAZECO Field Survey** app → sign-in screen | Connect to the internet; enter **Email** and **Password**; tap **Sign in and download assignments**. The release app is fixed to `https://hazeco.barqaab.pk`, not an administrator-entered server URL. | Login succeeds and assignments/reference data download. Work appears after allocation, not merely after account creation. |
| L2 | Data Entry Operator | Website `/login` → **Sign in to dashboard** | Enter **Email address** and Password. Use the normal application login, not `/admin/login`. Open **MDB Creation & Validation → PDF Data Entry**. | The entry queue is accessible. It can legitimately be empty until a batch exists and is assigned/in scope. |
| L3 | Data Verifier | Website `/login` → **Sign in to dashboard** | Sign in separately; open **MDB Creation & Validation → Data Verification**. | The verification queue is accessible. Entry-stage or already approved batches do not belong in this queue. |
| L4 | Any user / Super Admin | Login → **Forgot password?**, or **Users → Existing users** | If credentials fail, first confirm Active status and the correct email. Use the password-reset link only when production email delivery is working; otherwise the administrator resets the password on **Users**. | Access is restored without a shared account. **My Profile** currently changes the picture, not the password. |

## 4. Establish membership and the shared feeder pool

Use the same Survey team for the surveyor, entry operator and verifier associated with this work. Although Super Admin can directly assign a batch across teams, consistent membership supports ordinary navigation, batch creation and delegation.

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| T1 | Super Admin | **Teams & Assignments → Assign member** | Choose **Team type: Survey**, **Team**, and **User**. Leave **Survey team leader** unchecked for each ordinary Surveyor, Data Entry Operator and Data Verifier. Click **Assign member** for each person. | Each appears under that Survey team. This grants project/team scope, not a personal task. |
| T2 | Super Admin | **User Assignments** (alternative to T1) | Select the person → **View assignments** → **Assignment type: Survey Team Membership → Choose type** → select Project/find the team → select its checkbox → complete the review/confirmation steps in A1–A5 below. | Membership is added without removing existing memberships or changing an existing leader flag. |
| T3 | Super Admin | **Teams & Assignments → Assign survey feeders** | Select the Survey team, **Feeder selection: One feeder**, the **Feeder**, **Start date**, optional **End date/Remarks** → **Assign feeders**. Use **All active feeders in this project** only deliberately. | **Active feeder assignments** shows the feeder/team/dates. This is a shared team allocation, not a personal mobile task or a PDF batch assignment. |
| T4 | Super Admin | Same page → **Active feeder assignments** | Check dates cover collection/entry and later synchronization. Leave the end date open if operationally appropriate. | The feeder is available within its current active period. “Active” status alone does not override an expired date. |

Creating a personal mobile task also creates a shared feeder allocation when none exists. It does not repair an existing expired allocation. Selecting “All active feeders” allocates the feeders present now; repeat for later imports. Existing active allocations are skipped rather than edited. These screens do not provide a general deadline-extension, membership-removal or feeder-allocation history editor; arrange support-controlled changes if those are necessary.

**Delegation, if needed:** give a coordinator the appropriate **Survey Team Leader** or **Data Entry & Verification Team Leader** role and mark that person as a leader in the Survey team's membership. A Survey Team Leader can use **Survey Task Assignments** for teams they lead. A Data Entry & Verification Team Leader can use **Workflow Task Assignments** for teams they lead; targets must belong to the batch's Survey team. Merely naming a team leader does not grant a different application role. Super Admin can perform these actions directly.

## 5. Use the central User Assignments screen

This common sequence is used for both mobile tasks and batch ownership.

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| A1 | Super Admin | **Administration → User Assignments → 1. Select a user** | Use **Find user**, select **User**, click **View assignments**. Read the displayed name/email/role/Active status. | **All assignments** and **2. Assign tasks to [name]** refer to the intended person. Inactive users show history only. |
| A2 | Super Admin | **2. Assign tasks to [name]** | Select **Assignment type**, then **Choose type**. Use the exact task type in the next sections. | Only types compatible with the person's existing role are enabled. Disabled types do not upgrade their role. |
| A3 | Super Admin | Same section → task finder | Choose **Project**, enter **Find tasks**, and click **Find tasks**. Mobile/shared-feeder types also require **Survey team**. | Available work is narrowed to the relevant project/team and stage. Read each item's current owner and note. |
| A4 | Super Admin | Task results | Select work using checkboxes. Use **Next tasks / Previous tasks** if needed; at most **50** items per operation. **Select available on this page** selects only the current page, not the whole project. | Selection count/summary identifies the intended work. Already allocated items and self-verification conflicts may be disabled. |
| A5 | Super Admin | Bottom of assignment form | Fill the required type-specific fields, tick **I have checked the user, assignment type and selected tasks.** → **Review and assign** → check **Confirm assignment** dialog → **Confirm and save**. | A success message reports assignments created/changed/skipped. Review **All assignments**. A stale-owner error requires reloading and reviewing again; do not assume it partly saved. |

No automatic allocation email/push notification is sent by these assignment handlers. Tell the assignee to refresh/synchronize and confirm access.

## 6. Allocate and perform mobile surveys

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| M1 | Super Admin | **User Assignments → [Surveyor] → Mobile App Survey → Choose type** | Follow A1–A4, selecting the Survey team, Project and feeder(s). Enter **Start date**, optional **Deadline (optional)**, **Priority**, and **Instructions / remarks (optional)**. Keep overlap authorization unchecked for normal allocation. | The selected feeders are valid for this surveyor's existing team/project. A personal task is created per feeder. |
| M2 | Super Admin | Same form → review/confirmation | Complete A5. If another surveyor already has open work on that feeder, use Reassign where appropriate; authorize overlap only for intentionally separate work. | **All assignments** shows **Mobile App Survey / Direct task** and initially **Assigned**. A team allocation alone is insufficient for a Surveyor account. |
| M3 | Surveyor | Android Home → **Download assignments** (refresh icon) | Download while online, then tap **New survey**. Select **Assigned survey team**, then **Select feeder**. Search **Assigned feeders** by code/name. | The assigned feeder is available. The chooser can show **Priority**, **Due**, instructions and **Correction** comments. Future-start or expired tasks/feeders will not grant collection/sync access. |
| M4 | Surveyor | **Start a field survey → Create survey** | Confirm the team/feeder and create the local survey. In **Details**, use **Find imported transformer**, or enter its actual **Transformer code** if there is no suitable reference. Check all imported values. | A local survey notebook is created for that feeder/transformer. Already saved transformer references can be hidden to prevent duplicate collection. |
| M5 | Surveyor | Survey editor → **Details / S/E rows** | Complete the transformer/administrative details. Use **Add / complete S/E pair** and enter the paper-form observations, required group/date/GPS number, conductor/equipment and consumer/PV data. Use **Done** to finish each row. Enter the actual survey date, not a future date. | Changes save on the phone. The assigned team supplies a valid two-digit group; complete pairs are available for submission. |
| M6 | Surveyor | Row photos/solar controls | Where needed use **Take photo**, **Choose image**, **Photograph sketch**, and **Add solar installation**. Check the evidence is linked to the correct row; allow camera/gallery access when requested. | Images are copied into app-private phone storage. They are not server evidence until uploaded successfully. |
| M7 | Surveyor | Editor → **Save draft** (save icon) / **Save and go back** | Save before leaving. Reopen the survey from Home to continue. | **Saved on this phone / On-phone draft**. This is **not** a server save, and does not guarantee that the administrator sees “In progress.” Do not uninstall/clear app data or move phones before submission/sync. |
| M8 | Surveyor | Editor → **Submit survey** | Complete highlighted requirements; confirm **Submit this survey? → Submit**. Use **Go to Home** or **Create New Survey** afterward. | The immutable submission is queued; the phone may show **Submitted · waiting to sync / Waiting to sync**. Submission is not proof of server receipt. |
| M9 | Surveyor | Home → **Sync now** | Keep the app open with connectivity until form and photo uploads finish. Review **Waiting**, **Attention**, **Synced** and any per-image **Uploaded / Waiting to upload / Needs attention** labels. Use **Sign in again** if needed. | **Synced** confirms the form's upload, not independent verification and not necessarily every photo. Automatic retries occur while the app is active; guaranteed Android background uploading is not provided. |
| M10 | Super Admin | **Survey Task Assignments → Assigned tasks and live status**; then **Mobile Field Surveys** | Refresh the task screen. Open **Mobile Field Surveys**, search the transformer/feeder, select **Open survey sheet**, and check rows and attachments. | Server-received work is visible. Task status can become **Submitted**. Check actual feeder coverage: a submitted transformer survey can update a feeder task to Submitted without proving every transformer on the feeder is complete. |

**Mobile verification/correction boundary:** **Mobile Field Surveys** is available to Super Admin/Project Manager/Management Viewer at the route level; a **Data Verifier** does not receive this management screen through their dedicated role. There is no mobile-survey Approve action for that role.

The Survey Team Leader/Super Admin **Survey Task Assignments** screen does offer **Return for correction** on submitted tasks: enter **Correction comments for the surveyor** and submit it. The task becomes **Returned**, and a later assignment download can show the comments in the feeder chooser. However, the current Android code locks surveys with a server revision/Synced state, and downloading assignments does not download/reopen a corrected server survey. This is **not a working phone-side correction/resubmission loop**. An older mobile README describes editing synced records; the current locking code takes precedence for this guide.

For supported server-side corrections, use:

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| MC1 | Super Admin (or permitted Project Manager) | **Mobile Field Surveys → [record] → Edit survey** | Compare the evidence, correct existing values, enter **Correction notes (optional)**, and click **Save corrections**. Record a meaningful note even though optional. | A new server revision and audit entry preserve original row identities/evidence links. This does not grant Data Verifier approval, change the task automatically back from Returned, or refresh the phone notebook. |
| MC2 | Surveyor / administrator | Phone → **Attention / Version conflict**, if present | Retain the phone copy and consult the administrator/support. Do not delete/recreate a duplicate transformer survey or overwrite a newer server record. | Evidence is preserved for reconciliation. A complete returned-mobile-task closure/resubmission process requires additional functionality/support, not an undocumented button. |

## 7. Create PDF work and assign the entry operator

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| B1 | Super Admin | **MDB Creation & Validation → Create survey batch** | Under **1. Select the survey PDF**, provide **Survey PDF** and **GPS GPX**. Local uploads are simplest. If using Drive, connect the account through the supplied link, reload the form and use authorized files. | Source files are chosen. Mobile notebooks/photos are not automatically converted to these files. |
| B2 | Super Admin | Same page → **2. Enter details from the PDF** | Select **Project**, **Survey team**, **Feeder**, **Survey date**; verify they refer to the same work. Click **Create batch & start entry**. | A numbered batch is created, initially Draft/entry. Keep its batch number. Uploading the files does not create transformer entries or automatically assign an operator. |
| B3 | Super Admin | Batch → source status; **Refresh saved records** | Wait until PDF and GPX processing is **Ready**. If queued/processing does not finish, ask hosting support to check the existing background workers. Failed sources have **Retry source processing** in the advanced source-file screen where upload rights permit it. | Both sources are usable; the GPX provides matching waypoints. Re-uploading duplicates is not a replacement for a stopped worker. |
| B4 | Super Admin | **User Assignments → [Data Entry Operator] → Data Entry → Choose type** | Choose Project; **Find tasks** by batch number/feeder; select that Draft or Returned batch. Complete A5. | **All assignments** shows **Data Entry / Direct task**. Entry work can be allocated only while the batch is in entry or returned stage. |
| B5 | Super Admin | **Workflow Task Assignments → [batch] → Current assignees** | Check **Entry: [operator]**. This alternate screen can assign **Stage: Data entry → Assignee → Assignment note → Assign**, but use the central hub for its explicit owner-replacement review. | The intended owner is recorded. Existing content is unchanged. |
| B6 | Data Entry Operator | Separate login → **MDB Creation & Validation → PDF Data Entry** | Use **Project**, **Feeder**, **Survey team**, date/status/transformer filters as needed → **Apply filters** → **Open batch**. | The correct batch is accessible with editable entry controls. An explicit entry owner restricts other ordinary entry operators. Unassigned team batches may be shared, so queue visibility alone is not proof of personal ownership. |

## 8. Perform and submit PDF entry

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| E1 | Data Entry Operator | **Open batch → PDF Data Entry → Active transformer** | Choose a registered transformer. For a genuinely unlisted code use **Add Transformer** / the manual option, enter the correct code, and avoid duplicating a header already in this or another protected batch. | The selected/saved transformer is the target of subsequent rows. Imported reference choices are not themselves saved survey headers. |
| E2 | Data Entry Operator | **Transformer header** | Enter/check **Substation**, **Feeder name**, **Feeder code**, **Division**, **Subdivision**, **Subdivision code**, **Transformer capacity (KVA)**, **Transformer make**, **Mounting arrangement**, **Survey date**, **Group / team number**, **Inspector**, **Location**, **Service category**, **Transformer PDF**, **Starting PDF page no.** as shown/applicable. Click **Save transformer header**. | A server-saved transformer header appears. Required fields and project generation mode determine additional validation; never invent missing engineering data just to pass. |
| E3 | Data Entry Operator | PDF viewer / **Enter S/E row** | Open the correct PDF page. For a continuation belonging to this transformer use **Continue Current Transformer**. Enter **S/E**, two-digit **Group**, **Date (DDMMYY)**, three-digit **GPS number**, Conductor Detail, Type, Pole class, **Pole height (ft)**, consumer counts, optional PV/Int information. Confirm the GPX source when a match is ambiguous. | The row is linked to the correct transformer/PDF location and a valid GPX observation. Complete waypoint is composed as Group + DDMMYY + GPS number. |
| E4 | Data Entry Operator | Row form → **Save & Add Next** | Save S then E observations with the correct **Section pair**. Repeat for the actual network. Use **Copy Previous Values** only where values genuinely repeat; review copied values. | Saved rows appear under **Saved rows for active transformer**, and the next row is prepared. Incomplete pairs/required-field/topology errors must be fixed before submission. |
| E5 | Data Entry Operator | Row form → **Save Draft** | Save a valid partial row/pair before pausing; check the save-status message. Use **New row / cancel edit** to stop editing an existing row. | PDF Save Draft writes to the server, unlike Android Save draft. An incomplete pair can remain in Draft; invalid field values can still prevent a save. Unsaved browser-recovery fields alone are not confirmed server progress. |
| E6 | Data Entry Operator | **Saved rows for active transformer** / **Refresh saved records** | Reopen saved rows to inspect/correct them and check counts. Complete all required header values and paired S/E rows. If another save/source job changed the revision, refresh and review retained fields before saving again. | The server contains the intended records; stale edits are not silently applied. New-source jobs can also advance the revision. |
| E7 | Data Entry Operator | **Submit Transformer** | First save any unsaved fields. Click **Submit Transformer** and resolve any completeness errors. In **Enter another transformer from the same PDF?**, choose **Yes, enter another transformer** when more remain. | The current transformer is checked/saved and the same PDF remains available for the next one. This step alone is not independent verification or final batch submission. |
| E8 | Data Entry Operator | Same dialog → **No, go to MDB Creation & Validation** | When every transformer is entered, choose No. Alternatively use **Review saved survey → Survey workflow → Submit for Verification**. | The whole batch is submitted, becomes **Pending Verification**, and entry is locked until returned/reopened. The No button returns to the workflow hub; it does not generate an MDB. |
| E9 | Data Entry Operator | **MDB Creation & Validation → All batches / legacy records** | Find the batch and inspect **Workflow status**. Record its number for the administrator. | A submitted batch leaves the PDF Data Entry queue. Its disappearance from that queue is expected; verify it is Pending Verification rather than assuming it was deleted. |

For GPX checks, follow the current first-pair location rules shown by the application. The first E point must have a unique valid GPX match, including required coordinates/elevation; the default first S/E location behavior differs from later pairs. Do not fabricate points or change datum/engineering settings to bypass missing source data. Return the source to its owner for correction when necessary.

The Super Admin-only **Transformers in this batch → Delete** action permanently deletes an individual transformer's live entry data, resets its linked PDF progress, and invalidates approval. Whole-batch deletion remains available on the batch list. Neither action is a reassignment tool or a way to “complete” unfinished work.

## 9. Assign verification, approve, or return for correction

The assignment unit is the **whole batch**. Reviewing a transformer's tab or ticking values does not approve just that transformer independently.

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| V1 | Super Admin | **User Assignments → [Data Verifier] → Data Verification → Choose type** | Choose Project, search by batch/feeder and select the submitted batch. Confirm it was entered by somebody else. Complete A5. | The batch has a verification owner. Entry-stage batches are unavailable; the normal submitted batch is internally `awaiting_verification` and displays **Pending Verification**. |
| V2 | Super Admin | **Workflow Task Assignments → [batch]** | Check **Verification: [verifier]**. To use this screen directly choose **Stage: Verification**, **Assignee**, enter **Assignment note**, click **Assign**. | The next responsible verifier is identifiable. Assignment does not itself approve the batch. |
| V3 | Data Verifier | Separate login → **MDB Creation & Validation → Data Verification → Open batch** | Filter Project/Feeder, open the batch and confirm its sources and revision. | **Review survey data** opens. If the person entered any part of the batch, verification is denied even after a role change/reassignment. |
| V4 | Data Verifier | **Source PDF and GPX** / transformer tabs | Review **every** transformer tab against the source PDF/GPX. Use **Entered survey - same layout as the PDF**, the per-value checkboxes, **Mark header checked & hide**, **Next pair**, **Next unchecked pair**, and **Show full saved table** as needed. | Checks are saved for that reviewer and current values. A tick is a review aid, not approval; the current server does not require every tick before Approve. Use a human completeness check as well. |
| V5 | Data Verifier | Entered sheet → value / **Correct header** | For an allowed direct correction, click the saved value or Correct header, enter a meaningful **Reason for correction** (at least five characters), then **Save correction & return**. Recheck affected values. | The corrected revision is audited; affected checks no longer count until reviewed again. “& return” closes the editor/returns to review; it does not send the batch back to the entry operator. |
| V6 | Data Verifier | **Survey workflow → Discrepancy / approval / correction comments → Flag Discrepancy** | Enter comments identifying transformer, PDF page and exact error, then flag it when it should remain pending. | **Pending Verification** is retained. Flagging records the discrepancy but does **not** unlock entry for the operator. |
| V7 | Data Verifier | Same comments → **Return for Correction** | Enter actionable comments and return the batch when the operator must amend it. | **Returned for Correction**; the operator can edit the same batch. Data, assignment history and audit trail remain. |
| V8 | Data Entry Operator | **MDB Creation & Validation → PDF Data Entry → [Returned batch]** | Read **Verification / correction comments**, correct the cited header/rows and save them. Review the whole batch, then repeat E7–E8 / **Submit for Verification**. | The same batch and accumulated records become **Pending Verification** again, with a newer revision. Do not create a replacement batch to avoid the comments. |
| V9 | Super Admin / Data Verifier | **Workflow Task Assignments** and **Data Verification** | Confirm the existing verification owner is still correct; reassign at this stage if needed. The verifier refreshes and rechecks corrected values. | The resubmitted batch returns to the proper verifier; resubmission does not automatically select a different person. |
| V10 | Data Verifier | **Survey workflow → Approve** | Once sources/entries are complete and checked, optionally record an approval comment and approve. | **Verified**; the batch's data revision is frozen for later MDB creation. Approval is a batch decision, not an assignment-completion checkbox or mobile-survey approval. |
| V11 | Super Admin / Data Entry Operator | Approved batch review → **Reopen for Correction** | If genuinely necessary after approval, provide **Reason for correction** and reopen using an authorized entry actor. Correct and resubmit normally. | The batch returns for correction; previous approval/MDB outputs become outdated and a new verification is required. |

Normal PDF workflow: **Draft → Pending Verification → Verified**, or **Pending Verification → Returned for Correction → Pending Verification → Verified**. **Flag Discrepancy** stays Pending Verification. **MDB Created** is a later engineering output status, not the Data Verifier's immediate approval result.

## 10. View work, allocate more, and reassign without losing history

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| H1 | Super Admin | **User Assignments → [person] → All assignments** | Use **Type**, **Status**, **Project**, **Search assignments → Apply filters**. Use **Clear** if records seem missing. Check both **Direct task** and shared/team allocations. | You see the person's current tasks, memberships, shared allocations and available completed/historical records. An inactive account retains history. |
| H2 | Super Admin | Same person → **Assign tasks** | Repeat A1–A5 with additional available feeders or stage-ready batches. | New tasks/memberships are added. Existing unrelated assignments and completed records are not cleared. Membership by itself does not represent a personal batch/mobile allocation. |
| H3 | Super Admin + original surveyor | Mobile task before transfer | Contact the original surveyor and check the phone for drafts/Waiting uploads. Finish/sync any work first and refresh server status. | You do not strand an unsynced local draft. A task still showing Assigned on the server can have substantial work saved only on a phone. |
| H4 | Super Admin | **User Assignments → [old surveyor] → All assignments → Reassign** | For a genuinely unstarted **Assigned** task, choose **New owner (same team)**, enter **Reason** (3+ characters), tick **Confirm transfer to the selected user.**, click **Confirm reassignment**. | The old assignment becomes **Reassigned** and a new Assigned task is created. Deadline/priority/instructions and the old history are retained. The replacement must be Active, eligible and already in the same team. |
| H5 | Old and new surveyors | Android → **Download assignments** | Refresh both accounts/devices. | The replacement receives the feeder task; the old task no longer grants collection rights. Phone notebooks are not moved/copied between users. |
| H6 | Super Admin | **All assignments** where task says **Owner retained** | Do not force transfer of In progress, Draft, Returned or Submitted mobile work. Let its owner complete/correct it, or arrange an explicit support-assisted handover that preserves authorship. | Started/completed data retains its owner. “Allow overlapping work” creates additional work; it does not transfer existing submissions or local drafts. |
| H7 | Super Admin | **User Assignments → [new operator/verifier] → Data Entry / Data Verification** | Select the existing batch only when ready for that stage. Read Current owner, enter **Reason (required when replacing an owner)**, tick **I authorize replacing the current owners of selected batches.**, then complete A5. | The stage's owner changes on the **same batch**; saved rows/sources and approval history are retained. The old stage owner appears in **Previous owner · audit history** where the audit record exists. |
| H8 | Super Admin | **Workflow Task Assignments → [batch] → Current assignees** | Confirm the new owner, then ask both people to refresh and close stale edit tabs. | Current responsibility is clear and stale saves are rejected. One owner exists per batch per stage; this does not split one batch's transformers/pages between multiple entry operators. |
| H9 | Super Admin | **Audit Log → Filter action** | Inspect `survey.tasks_assigned`, `survey.task_reassigned`, `mdb.workflow_task_assigned`, or the relevant `mdb.survey_*` decision; expand **View JSON** for old/new values. | Previous owners, reasons and workflow decisions can be checked without deleting/recreating work. Batch history dates in the hub may reflect creation/current stage, so use the audit event for the actual transfer time. |
| H10 | Super Admin | **Users → Existing users → Status: Inactive → Save user** | Deactivate a departing person only after handling pending tasks and confirming unsynced device data is safe. | Login/new work is blocked; stored work/history remains. Deactivation does not automatically transfer responsibilities. |

The central hub requires a reason and confirmation when replacing batch owners and checks for stale ownership. The older **Workflow Task Assignments → Assign** screen supports changing the owner too, but its **Assignment note** is optional; use the central hub for controlled reassignment. Do not change dates by making a duplicate task, change a person's role to claim their previous work, or use Delete as a handover mechanism.

If the batch is in the wrong stage for a transfer: a pending-verification batch must first be **Returned for Correction** before changing its entry owner; an already verified batch must first be **Reopened for Correction** by an authorized actor. A new verification owner can be selected once the corrected batch is resubmitted. Do not alter status outside these workflow actions to make a task appear in the finder.

## 11. Worked example — creation through verification

This is a **fictional training example**, not a statement that these records already exist in production. Use an approved training project or substitute your real existing records. The PDF and GPX must be real matching source files; example names do not make placeholder files valid.

Project **TRAINING — Training Survey**, feeder **000407 — Training Feeder**, Survey team Code **01**, Name **Survey Team 01**, transformer **TX-1001**. People: **Ali Surveyor** (`ali.survey@example.com`), **Sara Entry** (`sara.entry@example.com`), **Nadia Verifier** (`nadia.verify@example.com`). Example assignment starts **29 October 2026**; deadline **5 November 2026**. Example batch number **42** must be replaced by the number the app actually creates.

| Step | Responsible user | Menu path | Action | Expected result |
| --- | --- | --- | --- | --- |
| X1 | Super Admin | **Master Data & Import / Teams & Assignments** | Confirm the Active training project, hierarchy/feeder, and team 01. Use the real transformer reference when available. | A valid scope exists for this exercise. |
| X2 | Super Admin | **Users → Add user** | Create Ali with **Surveyor**, Sara with **Data Entry Operator**, Nadia with **Data Verifier**, each using their own unique email and 10+ character password. Confirm all Active. | Three distinct accounts; no shared entry/verifier identity. |
| X3 | Super Admin | **Teams & Assignments → Assign member / Assign survey feeders** | Add all three as ordinary Survey team 01 members; allocate feeder 000407 to the team with a period covering the exercise. | Shared team scope is ready. |
| X4 | Super Admin | **User Assignments → Ali → Mobile App Survey** | Select team 01, TRAINING, feeder 000407. Set start/deadline, Normal priority, instructions “Collect TX-1001 and attach the observed S/E evidence.” Review and confirm. | Ali has an Assigned personal feeder task. |
| X5 | Ali | Android → **Sign in and download assignments → New survey** | Select team 01/feeder 000407, create survey, choose/enter TX-1001, fill the actual Details/S/E observations and evidence, **Save draft**, then **Submit survey → Submit → Sync now**. | The form becomes Synced, with evidence separately confirmed Uploaded. The admin confirms it in Mobile Field Surveys. This does not create Batch #42. |
| X6 | Super Admin / source owner | Operational source handoff → **Create survey batch** | Separately obtain the survey PDF (for example `Training-291026.pdf`) and matching GPX from the survey source owner. Create the TRAINING/team 01/feeder 000407 batch dated 29 October; record its actual number and wait for Ready. | A new, independent PDF-entry batch exists, assumed #42 here. If these files are unavailable, this handoff cannot proceed automatically from the phone notebook. |
| X7 | Super Admin | **User Assignments → Sara → Data Entry** | Find/select Batch #42 and confirm assignment. | Sara sees #42 in PDF Data Entry; Entry owner is Sara. |
| X8 | Sara | **PDF Data Entry → Open batch → Active transformer** | Save TX-1001's actual header, including Inspector/Location/Service category and starting PDF page. Enter and save the complete S/E pair(s). For the example date, Date is `291026` and Group is `01`; use the actual three-digit GPS numbers matching the GPX. | Server-saved header/rows pass the applicable completeness checks. |
| X9 | Sara | **Submit Transformer → No, go to MDB Creation & Validation** | Finish the batch submission when no further transformer remains. | #42 is Pending Verification; entry is locked. |
| X10 | Super Admin | **User Assignments → Nadia → Data Verification** | Find #42, confirm Sara is the entry owner and Nadia did not contribute to entry, then assign it. | Nadia sees #42 in Data Verification. |
| X11 | Nadia | **Data Verification → Open batch → Survey workflow** | Compare the PDF and saved entries. For this exercise, identify one real transcription discrepancy and enter a precise correction comment with transformer/page/field. Choose **Return for Correction**. | #42 becomes Returned for Correction; no records are discarded. |
| X12 | Sara | **PDF Data Entry → #42** | Read the comment, correct the cited value to match the original, save and resubmit the **same** batch. | #42 returns to Pending Verification with a new revision. |
| X13 | Nadia | **Data Verification → #42** | Refresh, recheck all transformers/affected values, then **Approve**. | #42 becomes Verified and its approved data revision is frozen. |
| X14 | Super Admin | **User Assignments / Workflow Task Assignments / Audit Log** | Confirm Ali's received submission, Sara/Nadia's batch ownership and #42's return/resubmission/approval history. Separately confirm all intended feeder transformers were collected. | The administrator has evidence of each actual stage, rather than equating Synced, Assigned or a checkbox with verified completion. |

## 12. Known gaps and troubleshooting

| Observation | Actual behavior / administrator response |
| --- | --- |
| User exists but has no work | Creation does not add membership, personal feeder tasks or batch ownership. Check all three separately. |
| Mobile feeder absent / upload forbidden | Check Active user/organization/project/team/feeder, Survey team membership, shared allocation dates, personal Mobile App Survey assignment dates, then **Download assignments**. PDF batch ownership is not a mobile assignment. |
| Deadline passed | Mobile authorization enforces dates, including upload time. Existing allocation screens do not generally edit deadlines/expired records; arrange a controlled extension with support before travel/offline work runs past the deadline. |
| Mobile work looks Submitted but feeder is unfinished | Task progress is not a verified roll-up of every transformer on that feeder. Check actual survey sheets and coverage. |
| Mobile Return for correction is available | It records task/comments, but the current Android app cannot reopen a Synced/server-revision survey for normal correction. Use supported server correction/support; do not promise a completed mobile resubmission loop. |
| Data Verifier cannot open Mobile Field Surveys | That role reviews PDF batches, not the Android management collection. Independent mobile-survey approval for this role is missing. |
| No mobile survey in PDF verification | No automatic mobile-to-PDF/GPX-batch conversion/import is implemented. Arrange sources and create the batch separately. |
| Operator's project/feeder choices empty | Add the correct Survey team membership, ensure matching project/Active feeder/current shared allocation and clear search filters. A direct batch assignment may permit opening an existing batch without giving membership needed for creating new ones. |
| Can't assign verifier yet | The batch must be submitted. Entry/returned work belongs to Data Entry; verified work belongs to the later MDB stage. Check the intended dedicated role and separation of duties. |
| Different user sees an unassigned team batch | Team visibility is a fallback for unassigned work. Explicitly allocate batch ownership for individual accountability. The assignment hub does not discover every team-visible unassigned batch as a personal Direct task. |
| Verification self-assignment disabled | Entry contributors/current or earlier operator participation cannot verify their own batch. Choose another person; do not erase/recreate history. |
| Flagged discrepancy did not unlock entry | **Flag Discrepancy** stays pending. Use **Return for Correction** when the operator must edit. |
| Checked values but batch not Verified | Review checks are not approval. **Approve** is a separate batch decision. Conversely, all review checkboxes are not enforced by the server as an approval prerequisite; follow your review procedure. |
| Sources remain Queued / Processing | Source workers are a hosting prerequisite. A user cannot resolve a stopped worker through allocation. Use the failure/retry information and escalate persistent stalls. |
| No account invitation/task email | Those handlers do not automatically send invitations or assignment notifications. Provide credentials/instructions securely and confirm by logging in/refreshing. Forgot-password delivery needs configured mail. |
| My Profile lacks password change | Current profile screen is picture management. Use the working forgot-password flow or an administrator password reset. |
| Surveyor sees My Survey Entries / Returned Entries links | The web sidebar shows them for Surveyor, but the underlying daily-survey routes restrict entry to Survey Team Leader/Super Admin; automatic survey-progress mode adds other limits. Do not use these as the mobile user's work inbox. |
| “All batches / legacy records” behaves differently | Legacy and new staged batches have different workflow rules. This guide's full entry→Data Verification path is for newly created staged survey batches. Do not assume every old legacy row can use it unchanged. |
| Cannot split one batch between two entry operators | Ownership is per batch/stage, not per transformer/page. Create separate genuinely distinct source batches when appropriate; do not create duplicate transformer entries to work around the ownership rule. |
| Need to remove membership/allocate expiry/change completed owner | The reviewed custom screens focus on adding membership/allocation and stage-ready transfers. They do not implement a general history-preserving removal/deadline/completed-mobile-transfer editor. Existing team code/name editing is also absent from the custom team page; escalate invalid existing team identifiers rather than silently changing historical work. |
| MDB output not created after approval | Data verification freezes survey data. Engineering input/template configuration, MDB creator assignment and generation are later operations outside these three roles' collection/entry/verification steps. |

## 13. Workflow acceptance checklist

Use a training record first. Mark each applicable item only after observing the result.

- [ ] The administrator can open User Management, User Assignments, Teams & Assignments and Audit Log.
- [ ] The three primary roles are exactly Surveyor, Data Entry Operator and Data Verifier.
- [ ] All three accounts are Active, with unique emails; any linked organization is Active.
- [ ] Each person can sign in to their intended website/app; ordinary users do not need admin-panel access.
- [ ] Project, team and feeder are Active and matched; the hierarchy and survey date are correct.
- [ ] Mobile team group is valid and unambiguous (for example 01).
- [ ] Shared feeder dates and personal task dates cover field work and eventual synchronization.
- [ ] Each user has appropriate Survey team membership; ordinary users are not accidentally marked team leaders.
- [ ] The surveyor has a Direct Mobile App Survey task, not just shared team membership.
- [ ] Download assignments exposes the right feeder and its instructions/priority/deadline on the phone.
- [ ] A mobile draft survives reopening; everyone understands it is phone-local.
- [ ] Submitted form and each attachment reach the server; Mobile Field Surveys shows the correct collector and data.
- [ ] Synced/Submitted is not being reported as independently verified or complete feeder coverage.
- [ ] PDF and matching GPX were supplied separately; the expected numbered batch and Ready sources exist.
- [ ] The entry operator is the batch's explicit Entry owner and can open it in PDF Data Entry.
- [ ] A saved header and complete S/E pairs are visible after reopening/refreshing; merely uploading sources did not count as entry.
- [ ] Batch submission changes it to Pending Verification and locks ordinary entry editing.
- [ ] A different, non-contributing verifier is explicitly assigned and can open it in Data Verification.
- [ ] Return for Correction records useful comments and lets the operator amend the same batch.
- [ ] Resubmission preserves data/history and returns the batch to the intended verifier.
- [ ] Approve produces Verified and a frozen approved revision.
- [ ] Additional assignments do not remove other work.
- [ ] A test pending-mobile reassignment retains the old record; unsynced phone work was checked first.
- [ ] A stage-ready batch owner replacement retains saved data, records a reason and shows previous-owner history.
- [ ] Audit Log identifies the assigning/deciding users and relevant old/new values.
- [ ] No one is relying on the incomplete mobile approval/returned-phone-resubmission path, automatic PDF conversion, or unsupported deadline/completed-owner editing.

## 14. Code and route evidence for support/audit

The operating steps above require no code changes or terminal commands. These references let support verify the behavior against a deployed version.

| Topic | Primary sources |
| --- | --- |
| Role labels and automatic primary-role synchronization | `app/Enums/UserRole.php`; `app/Models/User.php` |
| Custom user fields/activation; alternative Filament form | `app/Http/Controllers/Admin/UserController.php`; `resources/views/admin/users/index.blade.php`; `app/Filament/Resources/Users/Schemas/UserForm.php`; Filament English Create/Edit action translations |
| Actual menus and legacy URL prefix | `resources/views/layouts/app.blade.php`; `routes/web.php`; `app/Providers/Filament/AdminPanelProvider.php`; effective `route:list` checked for user/assignment URLs |
| Assignment hub, task labels, availability and history | `resources/views/admin/assignments/index.blade.php`; `app/Services/UserAssignmentManager.php`; `AssignmentChoices.php`; `AssignmentOverview.php` |
| Team scope, shared feeders and pending/stage transfers | `app/Http/Controllers/Admin/TeamController.php`; `app/Http/Controllers/TeamWorkAssignmentController.php`; `resources/views/team-work/{assignments,workflow}.blade.php` |
| Mobile scope, tokens, data and statuses | `app/Http/Controllers/Api/{FieldDeviceAuthController,FieldSurveyApiController}.php`; `app/Services/{FieldSurveyAccess,FieldSurveyService}.php`; `routes/api.php` |
| Android labels, local saving, release URL and locking | `mobile/field_survey/lib/{main,survey_editor,feeder_picker,field_controller,models}.dart` |
| PDF rights, ownership, queues and completeness | `app/Services/Mdb/WorkflowAccess.php`; `database/seeders/MdbWorkflowPermissionSeeder.php`; `app/Services/Mdb/EntryService.php`; `app/Http/Controllers/MdbWorkflowController.php` |
| PDF screen labels and Submit Transformer dialog | `resources/views/mdb-workflow/{index,create,show,review,workflow-actions}.blade.php`; `public/js/mdb-entry.js` |
| Verifier checks, direct corrections and decisions | `app/Http/Controllers/MdbSurveyReviewController.php`; `resources/views/mdb-workflow/partials/review-workspace.blade.php`; `app/Services/Mdb/ReviewService.php`; `MdbWorkflowController::surveyDecision` |
| Supported server-side mobile correction | `app/Http/Controllers/FieldSurveyController.php`; `app/Http/Requests/UpdateFieldSurveyRequest.php`; `FieldSurveyService::updateOnServer`; `resources/views/field-surveys/edit.blade.php` |

This guide describes implementation, not a successful live-account acceptance test. Use the checklist to validate production role permissions, deployed mobile build, source processing and actual assignments before onboarding a full team.
