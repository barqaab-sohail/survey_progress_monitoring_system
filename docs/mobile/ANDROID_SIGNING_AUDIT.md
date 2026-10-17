# Android signing audit — 17 October 2026

## Result

The original HAZECO signing key was not found in the accessible build files. No replacement key or APK was generated, no app was installed or uninstalled, and signing configuration and application version were not changed.

`mobile/field_survey/android/app/build.gradle.kts` specifies application ID `com.barqaab.hazeco.hazeco_field_survey`. Debug builds use Android's default debug signing configuration; the release build type also explicitly selects that debug configuration. There is no project-specific keystore path or custom signing configuration. The current `pubspec.yaml` declares version `1.0.0+1`.

The default Windows key, `C:\Users\BARQAAB\.android\debug.keystore`, is absent. The project's `android/key.properties`, user Gradle properties, and user Gradle initialization overrides are also absent. Android/Gradle environment variables do not identify another signing directory. `android/local.properties` contains SDK paths, without a signing-key reference.

## Checks performed

- Searched the current Windows profile and accessible D: filesystem, including hidden and ignored files, for keystores, JKS files, signing properties, APKs and Android app bundles. Only unrelated OneDrive keystores and Flutter SDK/integration-test keys were found; these are not evidence of HAZECO's signing identity and were not used.
- Inspected 35 readable ZIP archives across Desktop, Downloads, Documents, local projects and Dropbox. None contained a keystore, signing properties, APK or Android app bundle. Two unrelated archives could not be read.
- Checked all available repository history for signing keys, signing-property files, APKs and app bundles; none were recorded.
- Checked Android debug bridge: no device was connected. No registered WSL build environment was found. Historical project files mention E:, which is not mounted on this machine.

The historical validation report records an APK file SHA-256 and version 1.0.0 / code 1. That hash is not a signing-certificate fingerprint, and the referenced APK is unavailable. The certificate and version of the app currently on the phone therefore remain unverified.

## What is needed for an in-place update

1. **The original private signing keystore from the machine or build service that created the installed APK.** For this project's default debug configuration, look for `.android/debug.keystore` under that builder's user home: `%USERPROFILE%\.android\debug.keystore` on Windows, or `~/.android/debug.keystore` on Linux/macOS. A backup of that same file is suitable. Do not substitute an SDK sample key or a newly generated debug key.
2. **If a custom keystore was used:** its `.jks`, `.keystore` or PKCS12 file, signing alias, keystore password and private-key password (if different). Supply credentials through local secure configuration rather than committing them to the repository.
3. **A copy of the APK installed on the phone, or an authorized USB debugging connection to that phone.** This permits read-only verification of its application ID, signing certificate and version code. An APK or public certificate alone does not contain the private key needed to sign an update.

Once the key's certificate matches the installed APK, retain the application ID and build with a version code greater than the installed code. The original key is required to package this as an in-place update; uninstalling or clearing app storage is not a workaround because it would remove unsynchronized drafts and photos.

## Code and test status

All three mobile fixes are implemented. The 32 Laravel API/web tests passed with 464 assertions; all 46 Flutter tests passed. The 15 affected UI/submission tests passed again after the final input-limit and row error-display changes. Flutter analysis, PHP formatting and whitespace checks passed. Those checks include complete mobile payload submission, real PNG photo storage, editable two-digit groups, local reopening and synchronization, and stable PV/photo links to S/E rows.
