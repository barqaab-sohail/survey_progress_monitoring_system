# Android first-release validation

Validated on 7 October 2026 against synthetic records in an isolated SQLite database. Handwritten values from B2308.pdf were not imported. Generated test attachments were removed from the application's private file storage after checking them.

## Deliverable

- APK: `output/android/HAZECO-Field-Survey-v1.0.0-debug.apk`
- Package: `com.barqaab.hazeco.hazeco_field_survey`; version 1.0.0 / code 1
- Minimum Android API 24 (Android 7.0); target/compile API 36
- Architectures: arm64-v8a, armeabi-v7a, x86_64
- Debug signature verified with Android SDK apksigner
- Size: 170,023,241 bytes
- SHA-256: `931c3a07f8525cdc199938c7a2d76f6aaaae68a3407ebe409056de7653f6fae9`

The APK is a testing build. Production distribution needs the organization's release signing key and HTTPS server configuration.

## Automated checks

- Laravel: 118 tests passed, 1,794 assertions, using SQLite in memory with cached configuration/routes bypassed.
- Flutter: 17 tests passed. Flutter analyzer reports no issues.
- HTTP API: login/bootstrap, first upload, idempotent retry, revision update and stale-revision conflict passed.
- Private attachments: first upload, identical retry, authenticated download with matching hash, and rejected unauthenticated download passed.

## Native Android checks

Installed the built APK on an Android API 36 x86_64 emulator:

1. Signed in and downloaded the assigned team, feeder and transformer.
2. Disabled networking for the app, entered a survey with leading-zero group/waypoint identifiers, and attached a gallery image.
3. Granted foreground location permission and recorded simulated coordinates with accuracy. This verifies the native GPS flow; it does not measure real field accuracy.
4. Force-stopped and reopened offline. The draft, GPS observation and private photo persisted.
5. Submitted offline, then force-stopped and reopened. The submission remained queued with its saved outgoing payload.
6. Restored networking and synced. The server stored one submitted survey at revision 1 and one photo; the phone showed both as synced.
7. Compared the phone image, server file and authenticated download hashes. All matched. Leading zeros survived the server round trip.
8. Installed the final APK update without clearing app data. The synced record remained available. Signed out and signed in again to the same account/server; the survey and photo were retained.
9. Confirmed detailed sync created no daily survey or MDB progress records in the test database.

The existing local application's login page responds successfully and its new API requires authentication through both Laravel's development server and the XAMPP/Apache deployment.

## Checks for the field team's phones

Physical camera capture, gallery providers on older Android versions, denied location permission, actual GPS accuracy, storage exhaustion, and prolonged poor connectivity need trials on the team's devices. Foreground retry is implemented; Android background uploads are not guaranteed. Conflict copies are preserved for manual support; this release has no reconciliation screen. Confirm the paper's ambiguous abbreviations and measurement units with the survey lead using [FORM_MAPPING.md](FORM_MAPPING.md).
