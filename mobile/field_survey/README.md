# HAZECO Field Survey for Android

An Android first release connected to the existing Laravel survey monitoring system. It digitizes the blank LT survey layout from B2308.pdf: transformer and administrative details, repeated S/E observations, all nine consumer codes, a separate Int field, solar installations, and photo/sketch images. The sample PDF and its handwritten entries are not bundled with the app.

## Install and connect

1. Install the provided **debug APK** on an Android 7.0 / API 24 or newer phone. Android may ask you to allow installation from the application used to open the APK.
2. Open **HAZECO Field Survey**. Enter the application URL, your existing account email, and password. Survey-team leaders need an active team with assigned feeders; super administrators can also collect.
3. Sign in while connected and download assignments. Select **New survey**, team, and feeder. Imported transformer selection prefills values; check them in the field and edit as necessary.
4. Enter the details and add separate S/E rows. Changes autosave after 500 ms, on app pause, and before navigating back. **Save draft** immediately writes changes. The save indicator reports storage errors.
5. Add optional solar installations and photographs. Select **Submit survey** when required details are complete, then **Sync now**. Keep the app open until the forms and images are confirmed.

A phone cannot reach the PC server using `127.0.0.1` or `localhost`; those addresses refer to the phone itself. For local XAMPP testing use the PC's LAN address, for example `http://192.168.1.20:8000`. Run Laravel on the LAN interface (`php artisan serve --host=0.0.0.0 --port=8000`) and make that port reachable from the phone on the same Wi-Fi. Include an installation subdirectory in the URL if your server uses one. The Android emulator can use `http://10.0.2.2:8000` to reach the host PC. Enter the application URL rather than an API endpoint.

Debug builds allow HTTP only for private/local development hosts. **Use an HTTPS server for field deployments.** HTTP development traffic is unencrypted; the app never retains the password. Release builds reject HTTP and need an organization-owned signing key configured before distribution. The current debug signing configuration is for testing, not a published production package.

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

Codes, group identifiers, waypoints, transformer codes, and consumer references remain text to preserve leading zeros. Blank consumer counts mean **not recorded**, while `0` is a recorded zero. Both S and E observations remain separate; they do not automatically change the monitoring system's progress totals or approvals. Existing GIS reference data is used for selection and never rewritten by mobile collection.

The printed form omits several definitions. S/E codes are displayed without inventing expanded meanings; LC stays labelled Commercial (LC). Int is preserved separately rather than counted as consumers. Capacity in kVA and pole height in feet are application conventions because the scanned form does not state these units; confirm units with the survey lead. Other conductor text remains editable. [Form mapping](../../docs/mobile/FORM_MAPPING.md) records the remaining interpretation assumptions.

GPS capture is optional and requests foreground location permission only. Its displayed accuracy describes the phone fix, and the paper waypoint identifier must still be entered. Editing coordinates clears the captured accuracy. Camera/gallery uses Android's picker; no broad media-storage permission is requested.

## Build and verify

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

Automated tests cover local SQLite restart persistence, immutable retries, revision/conflict handling, account/server isolation, attachment upload/deletion races, validation, HTTP payloads, printed consumer fields, leading zeros, and editable GPS focus. Test real camera/gallery, GPS accuracy, denied permissions, low storage, poor networks, and force-stop/reopen on the survey team's phones before general rollout. A debug APK is a testable first release; it is not a Play Store publication.
