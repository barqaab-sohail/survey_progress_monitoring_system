# HAZECO Field Survey for Android

An Android first release connected to the existing Laravel survey monitoring system. It digitizes the blank LT survey layout from B2308.pdf: transformer and administrative details, repeated S/E observations, all nine consumer codes, a separate Intersection checkbox, solar installations, and photo/sketch images. The sample PDF and its handwritten entries are not bundled with the app.

## Install and connect

1. Install the provided **debug APK** on an Android 7.0 / API 24 or newer phone. Android may ask you to allow installation from the application used to open the APK.
2. Open **HAZECO Field Survey**. The application connects to `https://hazeco.barqaab.pk` automatically. Enter your existing account email and password; no server URL is requested. Survey-team leaders need an active team with assigned feeders; super administrators can also collect.
3. Sign in while connected and download assignments. Select **New survey**, team, and feeder. Imported transformer selection prefills values; check them in the field and edit as necessary.
4. Enter the details, then open **S/E rows** and add a pair. Complete S and tap **Done** to open its automatically created E row. Reopening S reuses its linked E. Back saves unfinished work without completing the row. Changes autosave after 500 ms, on app pause, and before navigating back. **Save draft** immediately writes changes. The save indicator reports storage errors.
5. Add optional solar installations and photographs. **Submit survey** is shown only in the S/E stage after at least one valid pair exists. Every pair must be complete and every required field valid before submission, then use **Sync now**. Keep the app open until the forms and images are confirmed.

The production base URL is fixed. Previously stored notebooks for other server URLs remain in SQLite and are not uploaded to production. Sign in again if an older session points to another server; do not clear app data.

On Apache CGI/FastCGI hosting, preserve the `Authorization` header for PHP. The supplied `public/.htaccess` enables `CGIPassAuth On` alongside Laravel's existing authorization rewrite rule. If a host disallows this directive in `.htaccess`, configure it in the virtual host/directory through the hosting administrator. A login followed by “Sign in again to synchronize” can indicate that the bootstrap request's bearer header was stripped. Test with a dummy 128-character token: the expected response is HTTP 401 with “This device session has expired or was revoked.” A response saying “Sign in again to synchronize” means Laravel did not receive a bearer token of the required length. Do not use or publish real device tokens for this diagnostic. No APK rebuild is needed for this server configuration change. Production should use `APP_DEBUG=false` to avoid returning stack traces.

The mobile login always uses production HTTPS. Release distribution needs the organization's signing key. The debug APK remains available for installation and field testing.

The Laravel endpoints and migration setup are documented in [the delivery guide](../../docs/mobile/DELIVERY.md) and [API contract](../../docs/mobile/API_CONTRACT.md).

## Offline and recovery behavior

- The first sign-in/download requires internet. Thereafter a saved session and cached assignments allow offline collection, including after an app restart.
- Forms and assignments are stored in SQLite, isolated by account ID **and** normalized application URL. Bearer tokens are held in Android secure storage. Passwords are only used for the sign-in request.
- Local drafts remain on the phone until submitted. Submission persists a fixed outgoing copy before attempting any request. UUIDs, outgoing content, and the last acknowledged revision survive retries and process restarts.
- Queued forms are temporarily read-only until acknowledgement. A synced form can be edited and submitted again with the same UUID and acknowledged revision. The app does not silently generate a second survey.
- Network interruption preserves the queue. Manual **Sync now**, app resume, and a 45-second retry while the app is open attempt delivery. This release does **not** guarantee Android background uploads.
- Server validation failures display field errors and permit correction. An expired session requires sign-in again; saved surveys/photos remain. Permission changes are enforced by the server on every upload.
- A version conflict (HTTP 409) preserves the phone copy and stops automatic resubmission. Reconciliation needs manual administrator/support work; this release has no conflict-resolution screen. The app does not overwrite the server.
- Photo files are copied from the picker cache to app-private storage and have independent UUIDs. They upload only after the survey is acknowledged. The app recovers Android image-picker lost data on restart and retries interrupted uploads without duplicating attachments. JPEG/PNG images up to 10 MB are supported; photograph paper sketches or choose their image from the gallery.
- Signing out clears the phone token, not the surveys, cached assignments, or photos. Sign in to the same account and same application URL to resume. If sign-out cannot reach the server, its token remains valid there until expiry/revocation; the local token is still removed.
- Attached images and drafts are retained on this phone. The image picker may resize images to 2400 pixels wide and compress JPEGs at quality 85; the app does not promise to retain the original camera resolution. Android backups are disabled to avoid restoring tokens or survey data to another device. Uninstalling the app, clearing app data, losing the phone, or phone storage failure can lose unsynced work. Confirm uploads before replacing the phone.

## Printed-form conventions

Codes, group identifiers, waypoints, transformer codes, and consumer references remain text to preserve leading zeros. Blank consumer counts mean **not recorded**, while `0` is a recorded zero. Both S and E observations remain separate, linked by an optional UUID pair ID; they do not automatically change the monitoring system's progress totals or approvals. Existing GIS reference data is used for selection and never rewritten by mobile collection.

The printed form omits several definitions. S/E codes are displayed without inventing expanded meanings; LC stays labelled Commercial (LC). Int is an Intersection checkbox and is never counted as a consumer. Capacity in kVA and pole height in feet are application conventions because the scanned form does not state these units; confirm units with the survey lead. Other conductor text remains editable. [Form mapping](../../docs/mobile/FORM_MAPPING.md) records the remaining interpretation assumptions.

Phone GPS capture and manual latitude/longitude entry have been removed. The app does not request location permission. Enter the GPS waypoint identifier to link coordinates from a separately uploaded GPX file in the web MDB workflow. Existing saved coordinate values are retained for compatibility. Camera/gallery uses Android's picker; no broad media-storage permission is requested.

## Build and verify

The field-entry update adds offline feeder search through a transformer-style dialog, automatic S/E pairing, direct six-digit DDMMYY dates, numeric group/waypoint identifiers, shared equipment choices and Copy Previous Values. A new survey and its first row default to the phone's local date; subsequent rows inherit group/date and alternate S/E. Editing does not rewrite existing dates or adjacent rows. Pair IDs preserve the relationship; deleting a row asks for confirmation before removing its entire pair. Legacy adjacent S/E rows receive IDs without rewriting their observation values. Copy Previous Values transfers conductors, equipment, pole class and height; GPS, identifiers, consumers, intersection and remarks stay unchanged.

PV observations include service load, solar capacity and a text Meter Number before Remarks. A meter number can identify a PV observation without a consumer reference. Mobile stores these additions in its existing SQLite JSON records and immutable outgoing queue; no phone database reset is needed.

Deploy the backend **before installing the updated APK**. From the production Laravel directory after pulling the changes:

```sh
php artisan migrate --force
php artisan config:cache
php artisan view:clear
php artisan queue:restart
```

The migration adds a nullable `meter_number` column to MDB PV records. Existing records need no backfill. Old snapshots omit this field when null, retaining their existing hashes. Download assignments again to refresh the cached project equipment options and two-digit-year window (default 2000–2099). Keep the same signing key/application ID when updating the app, and preserve its data.

The checked scaffold uses Flutter 3.44 / Dart 3.12, Android SDK 36, Android Studio JDK, and NDK `28.2.13676358` (Flutter's selected NDK, also used by the JNI dependency). Install it through Android Studio SDK Manager when it is missing; incomplete downloads need to be repaired before building.

The checked Gradle configuration disables Kotlin incremental compilation because this Windows workspace and the Pub cache reside on different drives. This avoids the cross-drive cache error encountered during packaging; it affects build speed, not the app's offline behavior. The setting is documented in [Kotlin's compilation guidance](https://kotlinlang.org/docs/gradle-compilation-and-caches.html).

```powershell
cd mobile/field_survey
flutter pub get
flutter analyze
flutter test
flutter build apk --debug
```

The APK is written to `build/app/outputs/flutter-apk/app-debug.apk`. Connect a test phone or start an emulator, then use `flutter run` for interactive testing.

Automated tests cover local SQLite restart persistence, immutable retries, revision/conflict handling, account/server isolation, attachment upload/deletion races, validation, HTTP payloads, printed consumer fields, leading zeros, waypoint input focus, and absence of phone GPS controls. Test real camera/gallery, denied camera permissions, low storage, poor networks, and force-stop/reopen on the survey team's phones before general rollout. A debug APK is a testable first release; it is not a Play Store publication.

## Required row fields and submission

Every S and E row needs S/E, group identifier, row date, GPS waypoint identifier, equipment type, pole class, pole height and at least one entered R/Y/B/N conductor. Numeric zero is accepted where the existing field rules allow it (including pole height); identifiers remain strings. Errors are shown beside inputs and unfinished rows are highlighted. Incomplete drafts can still be saved. Both the local submission queue and the API reject incomplete, duplicate or mismatched pairs. No database migration is needed for pair IDs because rows already use JSON. Existing immutable queued payloads remain intact; an older incomplete submission rejected by the API must be completed and submitted again.
