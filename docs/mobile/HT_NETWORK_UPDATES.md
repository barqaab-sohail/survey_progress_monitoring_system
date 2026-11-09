# HT Network Updates and LT Transformer Surveys

## Field procedure

1. Sign in and download feeder data while connected. This also caches the available client 11 kV HT features. Existing LT work is retained.
2. For a registered transformer, use **New LT Transformer Survey** as usual.
3. For a missing transformer, open **HT Network Updates**, select the feeder and choose **New HT Network Update**. The LT details page also offers **Missing transformer? Create HT Network Update**.
4. Choose **HT extension + missing transformer**, or **Missing transformer on existing HT** if the existing route needs no new spans.
5. Record the transformer code (or unique temporary reference), capacity, make, location, mounting and HT phase. Select the actual upstream feature from the cached client network. Record its pole/section reference; do not assume the nearest asset is connected.
6. Enter the route in order: existing connection, each additional HT pole, and transformer last. Record incoming conductor details for new spans. Waypoint references are required. Optional WGS84 latitude/longitude can instead be resolved from GPX during office review. Photograph the transformer/connection and explain the change. Up to three JPEG/PNG photographs are accepted, each up to 1 MB after mobile compression.
7. **Save HT & Start LT Transformer Survey** saves the HT submission and opens a linked LT draft immediately. Internet and admin approval are not required to collect LT. **Submit HT Network Update only** is available when LT will start later. **Save HT draft** retains unfinished work without submission.
8. Complete and submit LT normally. **Sync now** sends HT first and its linked LT afterward. Failed uploads and validation/duplicate errors retain local work. HT drafts, submissions, photographs and LT forms survive restart in account/server-scoped SQLite storage. Do not uninstall or clear phone data.
9. HT status is distinct from LT upload status: **draft**, **queued**, **pending**, **returned**, **verified**, or **conflict**. Download feeder data or synchronize to retrieve web verification status. Pending HT is read-only; a returned update can be corrected with the same UUID. The transformer identity remains fixed after first synchronization.

## Web verification

Open **HT Network Updates** in the web navigation. Super administrators and project managers can independently verify or return a pending update. Collectors cannot verify their own updates, including collectors with an administrator role.

Review the connection reference, source HT feature, ordered route, equipment/phase/conductor details, photographs and coordinates. Optional GPX upload fills only exact, unique waypoint-name matches for preview; missing/ambiguous references remain unresolved. Check the recording session before accepting GPX coordinates. Enter corrections and a review note, confirm the actual connection, then verify. Every route coordinate must be resolved, and the upstream point must be within 30 metres of the selected baseline feature. This distance check supports manual verification; it does not establish electrical connectivity automatically. A transformer recorded without an HT extension must also meet the existing feature.

Verification creates the registered transformer, records reviewer/date/reason, and retains its permanent link to LT observations. If a later imported transformer already has the same normalized code, verification requires explicit reconciliation to that same-feeder transformer ID. Existing competing LT surveys must be reconciled first. Return reasons and submitted coordinates are audited. Returning HT does not delete LT data.

Pending/returned HT blocks final mobile GPX confirmation and JSON/MDB release. The check is enforced on the server, including old/unlinked surveys with the same pending HT transformer identity. Field collection and synchronization remain allowed. The verified transformer coordinates supply the LT starting-transformer coordinates at final snapshot creation, including for LT synchronized before HT verification.

## Updated client KMZ

**Download feeder KMZ — verified HT updates only** creates a new cumulative export from the latest completed client baseline. Original client KMZ bytes remain unchanged. Existing features, folders, styles and bundled files are retained. Verified transformers/new HT poles and extension spans are added using the source attribute labels, including abbreviated and full-name client variants. Original unrelated asset data is not copied into new assets. Reconciled transformer points are replaced in the exported copy to avoid duplicates. Pending/returned additions are excluded.

KML WGS84 geometry is authoritative. Unknown projected Start/End X/Y values remain blank rather than inheriting another span's coordinates. This exports KMZ compatibility; it does not regenerate the client's original GIS database or infer its coordinate reference system. Field photographs remain authenticated web evidence and are not added to the exported KMZ. New HT span connections retain upstream references in their remarks. The mobile map is a zoomable network overview, without an offline background map or phone GPS tracking.

Replacing a client baseline on a feeder with HT updates is blocked to protect field additions and LT links. A future client-file replacement needs deliberate reconciliation before import. This release collects missing transformers and their optional HT extensions; it does not provide general editing/deletion of all existing HT assets or cross-feeder transfers.

## Deployment

Deploy the changed Laravel files before installing the new mobile build. Apply the additive migration from the application directory:

```powershell
php artisan migrate --path=database/migrations/2026_11_09_000001_create_ht_network_updates.php --force
php artisan view:clear
php artisan queue:restart
```

If deployment uses cached routes, rebuild the route cache with the project's normal deployment process. Ensure the web server accepts authenticated JSON bodies up to 6 MB (photos are encoded within the HT submission), PHP request limits allow that size, and the original imported KMZ files remain available on the configured local storage disk. Missing source geometry does not prevent LT collection, but HT verification/export requires its original baseline.

The phone upgrades SQLite from v2 to v3 without deleting existing drafts/photos. Application version is 1.1.0, build 3; confirm build code is newer than the installed build before distribution. Update using the original signing key and application ID. A debug APK is for testing; the unsigned release must be signed with the existing distribution key. Do not install by uninstalling an existing field app with unsynchronized data.

## Validation

New automated checks cover HT/LT synchronization order, permanent identity, immutable offline retries/restart, account isolation, returned corrections, duplicate rejection, independent review, private photo access, verification gates and KMZ preservation. Dry-run exports against BAGNOTER (000412), CANTT HT (000402) and DHAMTOUR (000401) retained source/media and added one test transformer/HT span correctly; no original client files were modified.

Existing broader PHP tests have pre-existing failures involving required survey headers, waypoint rules and GPX group naming. The same 21 failures and five errors were reproduced with the unchanged request/workflow classes from HEAD. Existing broader mobile tests also contain outdated fixture/waypoint expectations. With the pre-existing hanging survey-picker test excluded, 28 failures were reproduced against unchanged HEAD mobile source. The v1-to-v3 migration fixture and renamed navigation labels were updated for this feature. See local verification logs under `tmp/ht-baseline-check` and `tmp/ht-mobile-tests.log`. These broader suites are not reported as passing.
