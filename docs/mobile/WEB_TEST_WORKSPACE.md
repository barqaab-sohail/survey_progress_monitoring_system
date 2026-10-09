# Android survey web test workspace

Super Admin opens **Android Survey Web Test** from the main sidebar or Filament administration. Other roles cannot open the page, download its references/attachments, list its records or submit to its endpoints.

The web version mirrors the current Android field workflow: assigned team and feeder selection, optional reference transformer, full transformer header, individual S/E observations, nine consumer categories, Int, solar installations, GPS coordinates/accuracy and photo/sketch attachments. Codes retain leading zeros and blank consumer counts remain unrecorded. Unknown conductor/pole descriptions remain editable. Browser layout differs from Flutter's native screens.

## Testing

1. Ensure an active survey team has a current feeder assignment under **Teams & Assignments**.
2. Download assignments and start a new survey. Add observations, solar records and optional JPEG/PNG/PDF attachments.
3. Save a draft. Entries and files persist in IndexedDB, scoped to this browser, application path and account. Closing the page retains saved drafts; clearing browser site data removes local copies.
4. Check **Simulate offline**, then submit. The queued snapshot is kept unchanged for safe retry. Reload the page to verify retention. This simulation persists for the browser tab until unchecked. A real offline reload of the application requires a connection; the currently loaded editor continues saving offline.
5. Reconnect/uncheck simulation and choose **Sync now**. The workspace also retries when the browser reports reconnection. The server confirms the survey revision before attachments upload. A failed upload keeps its local file for retry.
6. Edit a confirmed survey and resubmit to test revision updates. Conflicts retain the local snapshot; **Review server copy** downloads that snapshot as JSON before loading the server revision, preserving pending files.
7. Use **Export Android JSON** to inspect the API-shaped payload and compare expected behavior with Android. Exports contain the survey payload, not attachment bytes or login credentials.

GPS requires browser permission and HTTPS or localhost. Desktop positioning may be less accurate than phone GPS; accuracy is displayed and coordinates remain editable. Camera capture depends on the browser/device and otherwise offers a file picker. These tests do not replace physical Android tests for camera, location, permissions, SQLite/secure storage or app lifecycle behavior.

## Shared contract and isolation

The workspace uses `SyncFieldSurveyRequest` directly, so server validation matches the Android API. Bootstrap references use the existing `FieldSurveyApiController::bootstrap` and assignment restrictions use `FieldSurveyAccess`. Sync accepts the same UUID, base revision, draft/submitted status, header, observations, solar and remarks schema, with idempotent retries and stale-revision rejection.

All writes go to `field_survey_tests`, `field_survey_test_attachments` and private `field-survey-tests/` file storage. The workspace does not submit to Android `/api/v1/field/surveys/sync`, modify real `field_surveys`, imported GIS transformers, daily progress or MDB records. Server test records are private to their creating Super Admin account.

The web UI is a JavaScript implementation of the existing workflow, not a Flutter web build. Future changes accepted during web testing still need implementation and platform testing in `mobile/field_survey`; this feature does not automatically change or rebuild the Android APK. Keep the form mapping and API contract aligned when making those changes.

Backend coverage: `tests/Feature/FieldSurveyWebTest.php`. Local browser QA uses a separate SQLite database and isolated attachment storage.

## Automatic phase

Phase is derived from the R, Y and B conductor columns in that order: R + Y gives `RY`, and R + Y + B gives `RYB`. Neutral does not affect Phase. Empty/whitespace values and absence marks (`+`, `-`, en/em dash, `x`, `?`) do not indicate a populated phase conductor. The field is read-only in the web test and Android editors. The shared sync request recalculates Phase before validation/storage; historical records are not rewritten until resubmitted.

## Android GPS removal

The Android editor no longer offers phone GPS capture, accuracy, latitude or longitude input and does not request location permissions. Its GPS waypoint identifier remains for linking external GPX coordinates in the web MDB workflow. Legacy coordinate data and nullable API fields remain compatible. Browser GPS controls remain available in the web test workspace.
