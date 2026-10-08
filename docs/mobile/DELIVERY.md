# Android field collection

B2308.pdf supplies the LT survey fields. Its handwritten MEPCO examples are not imported into the HAZECO system. The app uses assigned HAZECO feeders and available transformer references.

Install the [Android test APK](../../output/android/HAZECO-Field-Survey-v1.0.0-debug.apk) on an Android 7.0 or newer phone. The [validation record](VALIDATION.md) describes the completed checks and remaining phone trials.

## Current local connection

On 7 October 2026, the existing XAMPP/Apache deployment responds at `http://192.168.1.70/survey_progress_monitoring_system/public`. Enter that application URL on a phone connected to the same local network, using an active, assigned survey-team-leader account. The phone does not use `127.0.0.1`. The computer's LAN address can change; if it does, replace `192.168.1.70` with its current address. This is a local HTTP test connection; field deployment uses HTTPS.

## Field workflow

1. Log in online with an active survey-team-leader account and download assignments.
2. Select the team, feeder and transformer. Fill header data, then add S/E observations with waypoint, phase, conductors, pole data and consumers.
3. Capture optional GPS coordinates with accuracy, photos, and photographed sketches. Add solar/net-metering records as needed.
4. Save drafts locally and collect without internet. Queue completed surveys, then sync while online.
5. Review detailed records in the web application's Field Surveys menu. Sync does not automatically change existing survey progress or MDB capacity.

## Server setup

The additive migration is applied in this workspace. When setting up another server, apply it from the Laravel application directory and clear cached routes/configuration. The final command below is an alternative Laravel development server when Apache is not serving the application; choose an available port.

```powershell
php artisan migrate
php artisan optimize:clear
php artisan serve --host=0.0.0.0 --port=8000
```

For photo uploads, set PHP `upload_max_filesize` to at least `10M` and `post_max_size` to at least `12M` in the web-server PHP configuration.

Use an account assigned to an active survey team, project and feeder. A phone on the same Wi-Fi uses `http://<computer-LAN-IP>:8000` with the debug APK. The computer's `127.0.0.1` is not accessible as the server address from a phone. An Android emulator uses `http://10.0.2.2:8000`. Retain any Apache deployment subdirectory in the application URL. Production uses HTTPS.

## Operating scope

- First login and assignment download require internet. Collected forms and photos persist on the phone.
- Foreground sync and explicit retries are provided; unattended background sync is not promised.
- Survey-team-leader accounts are reused; a separate surveyor role is not introduced.
- Detailed records do not automatically increment the existing daily progress quantities. This prevents duplicate reporting.
- Server revisions and UUIDs protect retries. Conflicts preserve the phone copy and need manual support to reconcile; this release does not include a conflict-resolution screen.
- Attachments are kept in authenticated private storage. GPS capture is optional and reports accuracy separately from printed waypoint IDs.
- See FORM_MAPPING.md for ambiguous printed abbreviations and units.
- A debug APK is for testing. Production distribution requires the organization's release signing key.

## Implementation references

- [Flutter offline-first guidance](https://docs.flutter.dev/app-architecture/design-patterns/offline-first)
- [Flutter Android deployment guidance](https://docs.flutter.dev/deployment/android)
- [API contract](API_CONTRACT.md)
- [Paper field mapping](FORM_MAPPING.md)
