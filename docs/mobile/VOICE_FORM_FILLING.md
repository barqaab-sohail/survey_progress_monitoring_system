# Mobile voice form-filling

Voice entry is off by default for every account, including Super Admin. It uses the phone's speech recognition service through [speech_to_text](https://pub.dev/packages/speech_to_text); it does not require a speech API key in Laravel. This repository currently ships the Android app.

## Deployment and access

1. Deploy the backend changes and run `php artisan migrate --force`.
2. Build and distribute the updated mobile app (version 1.2.0, build 4) using the project's existing signing process. Installing a new dependency requires a new APK; a backend migration alone cannot add microphones to an installed app.
3. As Super Admin, open **Admin Panel → Users → Edit**, enable **Allow voice form-filling in mobile app**, and save. The legacy user administration form also has an Enabled/Disabled selector.
4. On the phone, sign in or tap **Download feeder data** to refresh the account's feature settings.

The switch is writable only by Super Admin. User edit permissions granted to another role do not grant control over this switch. Existing users remain disabled after migration.

## Use

- Tap the microphone beside an editable field. The app checks current server permission before initializing the microphone.
- Allow Android microphone access when prompted. Choose a speech language from those supported by the phone. Urdu or regional languages depend on the installed speech service.
- Speak the value for that field in a short phrase. Stop listening, check **Heard** and **Value to apply**, correct the text or chosen option, then tap **Use value**.
- The app checks permission again before applying the draft. Cancel leaves the original field unchanged. Applying a value uses the same validation and save path as keyboard entry and does not submit the survey.
- Text, numbers, editable dates, S/E conductor/equipment dropdowns, customer counts, and HT text/number and basic option fields support voice. Imported asset and map selections remain explicit selections. Read-only, calculated, and locked fields do not expose a microphone.
- For identifiers, speak digits individually (for example, "zero zero eight"). Leading zeros are preserved. Codes and numbers must be checked carefully. Dates use six digits in DDMMYY order.

An internet connection is required to authorize each voice session and apply a value. Network failure, denied microphone permission, or an unavailable speech service leaves keyboard entry available. A cached enabled flag never authorizes microphone use by itself. Disabling the switch blocks the next voice session or application of a draft, without requiring the user to sign in again.

The app stops listening when the voice window closes or the app leaves the foreground. It does not save audio or transcripts; only the reviewed field value is saved in the usual survey draft. The phone's speech service may process speech online according to its device settings. Recognition accuracy varies with accents, language, noise, and the device; no accuracy percentage is guaranteed.

## Checks

Automated checks cover server authorization/defaults/revocation, Super Admin ownership of the switch, microphone gating, cancel/review/apply behavior, unavailable/offline speech, dropdown matching, and digit preservation. Run speech trials on the team's actual Android phones with their usual languages and field noise before relying on voice input in production.

Implementation checks: the Android arm64 debug APK built successfully; Dart analysis is clean; the 12 voice tests and the backend voice/user-management checks pass. The wider mobile suite is not green: existing fixtures omit required transformer header values/use older waypoint formats, and some group/HT assumptions are out of date. The calendar/identifier fixture failure and the group-field expectation were reproduced from the unchanged HEAD in an isolated baseline copy. These are separate from the voice-specific checks. Real microphone recognition and accent accuracy have not been verified on a physical phone.
