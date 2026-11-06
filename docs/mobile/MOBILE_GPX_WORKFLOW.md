# Mobile users, group codes and shared GPX linking

Implemented 6 November 2026. This is the mobile survey path; the PDF operator/verifier workflow remains separate.

Updated APK: [HAZECO Field Survey 1.0.5, code 6](../../output/android/HAZECO-Field-Survey-v1.0.5-code6-update.apk). See [validation and signing checks](MOBILE_GPX_VALIDATION.md). Deploy the matching server changes before using the APK against the production URL.

## Account setup

1. As Super Admin, open **Teams & Assignments**. Set an explicit two-digit group code, for example `02`. If existing GPX names use a letter, set **GPX filename group** to that letter, for example `A`. Codes and filename aliases must be unique within a project.
2. Assign the survey team's feeders using the existing assignment controls.
3. Open **User Management**, select **Mobile Survey User**, and choose the configured **Mobile survey team / group**. The membership is saved with the account. Filament user administration supports the same relationship.
4. Only active **Mobile Survey User** and **Super Admin** roles may log in or use an existing mobile device token. Older Surveyor and Survey Team Leader accounts need an explicit role change to collect with mobile. Existing surveys and attachments are preserved.
5. The Admin assignment hub accepts Mobile Survey Users for personal mobile tasks. Where personal task records exist, their feeder/date restrictions apply; otherwise collection uses the team's assigned feeders.

New rows use the assigned team code automatically. The first historical S remains editable for its original group and date; its E starts with the current survey date and assigned group. Existing saved row identities are not rewritten after team renumbering. The complete identity remains `GGDDMMYYWWW`, with the original short GPS text retained. New nonhistorical rows cannot submit a different group through a modified API request.

The phone and server display `Group_DDMMYY_TransformerCode`, for example `02_061126_TR-025`. Internal links use the survey UUID and revision, not that display name. The survey name uses the actual survey date, not the sync date.

## MDB Creator workflow

MDB Creators need an active MDB team membership in the relevant project and their existing preparation permission. Super Admin can access all active projects. The creator receives GPX linking access without acquiring permission to edit mobile observations or approve paper-entry surveys.

1. Open **Mobile Survey GPX Linking**.
2. Upload the original shared GPX once, selecting its survey team. `A_061126_06.gpx` means GPX group A, 6 November 2026, six expected transformers. Numeric filenames such as `02_061126_06.gpx` also work for group 02. Identical bytes in the same team reuse the existing original. Distinct files are retained separately even when their filenames are identical.
3. Open each related submitted transformer survey. Select the source file(s) and **Preview waypoint matches**. A multi-day survey can select sources for its different row dates. Extra waypoints for other transformers are normal.
4. Review each row's complete identity, GPX name, recording time in Pakistan, coordinates, relative location plot, and geographic location links. Connections follow explicit S/E pairs rather than GPX order. Required points must resolve uniquely within the selected group/date sources. Exact names take precedence; a numeric-equivalent short name can match only without ambiguity and is reported as a warning. Original references are retained. The first historical S keeps the existing registered-transformer/first-E coordinate policy and is not required to occur in the new GPX.
5. Resolve missing/ambiguous points and invalid pairs. Missing timestamps or recording-date discrepancies require a confirmation note documenting surveyor clarification. Check the session with the surveyor: matching filename/count/waypoint labels alone cannot establish physical provenance. Unlinked PV observations also block confirmation.
6. Confirm the linkage. The application freezes the row mapping, selected original GPX hashes, survey data/revision, engineering settings, template identity, report, actor, time and explanation. Each transformer is confirmed independently; one pending transformer does not block another in the shared file. The source list shows expected versus linked current transformer counts; the filename count is a reconciliation aid, not verified physical progress.
7. Download **confirmed SynerGEE JSON**. Export rechecks access, current revision, frozen snapshot hash, original GPX bytes, template and model readiness. Changed surveys invalidate the current linkage; changed project settings require a fresh confirmation. Historical link records remain available in the database/audit trail.
8. On the local Windows application, open **JSON to SynerGEE**, upload the downloaded JSON, review, and generate the MDB. Confirmed coordinates and the selected clean template are embedded. No GPX replacement is needed locally. The existing DAO/ACE writer and exact readback verification are reused. Install this server update on the local converter too so it recognizes mobile provenance.

The JSON source is marked `mobile_survey`; creator GPX confirmation is not represented as independent paper-survey approval. Original mobile rows, header, solar records and remarks remain in the snapshot. The checksum detects changes/corruption; it is not a signature proving origin. Local offline conversion cannot check whether a previously downloaded package has since been superseded on the server; download a current package before generation.

## Deployment

Run the additive migration and role seeder:

```powershell
php artisan migrate --path=database/migrations/2026_11_06_000001_add_mobile_survey_gpx_linking.php --force
php artisan db:seed --class=MobileSurveyRoleSeeder --force
php artisan route:clear
php artisan view:clear
```

Deploy the backend before the updated mobile build. No sample account/password is created and existing users are not silently converted to the new role. Use User Management to select which people receive mobile access. Use the existing trusted Android signing identity for an in-place app update; do not uninstall a phone app containing unsynced data.

Project engineering requirements still apply. GPX confirmation does not manufacture missing phase/neutral evidence, equipment values, transformer mounting, or engineering approvals. JSON export explains missing model inputs instead of omitting observations or inventing values. The C# converter remains the separately recorded future recommendation; this implementation reuses the working PHP/Windows converter.
