# LT field survey mobile API contract (v1)

Reference: B2308.pdf, 27 scanned LT survey sheets. Use blank schema, not handwritten sample values. API base: `https://hazeco.barqaab.pk/api/v1/field`. Android uses the fixed application URL. Assigned survey-team-leader accounts or super admin can collect. First login and download require internet; collection afterwards is offline.

## Endpoints

- POST `/login` JSON {email,password,device_name} -> {token,user:{id,name},expires_at}. Bearer tokens on subsequent requests.
- POST `/logout` -> 204 (current device token).
- GET `/bootstrap` -> {user:{id,name},teams:[{id,name,project_id}],feeders:[{id,feeder_code,feeder_name,grid_station_name,division_name,sub_division_name,sub_division_code,team_ids:[int]}],transformers:[{id,feeder_id,transformer_code,capacity_kva,equipment_make,equipment_location}]}. Restrict to active projects/teams/feeders and current user memberships/assignments. Super admin uses all active teams/assignments.
- POST `/surveys/sync` JSON payload below -> {id,client_uuid,revision,status}. 201 first sync, 200 idempotent replay/update. 409 stale base_revision or UUID collision with different owner; 422 field validation; 403 revoked assignment/account.
- POST `/surveys/{client_uuid}/attachments` multipart {client_uuid,row_id,kind,file} -> {id,client_uuid,row_id}. New uploads require a row_id already saved within this survey. Stable attachment UUID, retries do not duplicate; different bytes or row for the same UUID ->409. Legacy unlinked upload replays remain idempotent. kind=photo or sketch. File JPEG/PNG/PDF <=10MB; private server storage. Survey owner must still be assigned. The phone retains its attached image copy for retry and offline viewing.
- GET `/attachments/{attachment_uuid}` -> authenticated private file download. The API permits the survey owner with current assignment access; web management access uses its separate scoped route.

## Survey sync payload

Root: `client_uuid` UUID, `base_revision` integer (0 for new), `survey_team_id` int, `feeder_id` int, `transformer_id` nullable int, `transformer_code` string max100, `survey_date` YYYY-MM-DD, `status` draft/submitted, `header` object, `rows` array <=500, `solar` array <=100, `remarks` string <=2000.

Header keys (strings <=200 except location <=500): `substation`, `division`, `sub_division`, `sub_division_code`, `transformer_make`, `inspectors`, `location`; `capacity_kva` nullable number >0; `mounting` empty/S.Pole/D.Pole/Pad; `duty` empty/General Duty/Dedicated.

Rows model each printed S/E row, not an invented whole transformer total. Keys: `se` S/E, `group` string containing exactly two ASCII digits, `date` YYYY-MM-DD, `gps_waypoint` string<=50, `latitude` nullable -90..90, `longitude` nullable -180..180, `gps_accuracy_m` nullable number>=0, `phase` string<=30; `conductor_r`,`conductor_y`,`conductor_b`,`conductor_neutral` strings<=100 (offer A,W,GN,2/0 AWG,PVC 7/0.052,PVC 19/0.052,PVC 19/0.083,USAID 50mm2,USAID 95mm2,Ang,Int,Other); `equipment_type` string<=50, `pole_class` string<=50 (S,PCO,PCS,RS,TS,WB), `pole_height_ft` nullable number>=0; `consumers` object with optional nonnegative integer keys `rs`,`rl`,`sc`,`lc`,`si`,`li`,`pb`,`ag`,`st`; `intersection` optional boolean (Intersection checkbox, separate from consumers); `remarks` optional string<=1000.

Solar keys: `consumer_reference` nullable string<=255, `service_load_kw` and `installed_pv_kw` nullable numbers 0..1,000,000, `meter_number` nullable string<=255, `remarks` nullable string<=4000. Meter Number preserves zeros, letters and separators and may be entered without a consumer reference. Old payloads without the added keys remain valid (including their previous capacity limit). Mobile/UI dates use DDMMYY; the API still receives YYYY-MM-DD.

Rows can include optional `pair_id` (UUID), shared by exactly one S and one E. Legacy rows without IDs pair only by adjacent S/E order. Explicit IDs cannot fall back to positional pairing; duplicate or orphan pairs cause a 422 on submission. The mobile app creates/reuses E after S completion and confirms whole-pair deletion. No SQL migration is required.

Each new row also includes a distinct stable `row_id` UUID; it is not a list index or pair ID. When first upgrading a saved legacy row without a UUID, the phone supplies `legacy_row_index` to identify its original row, preserving its Group Identifier even if rows have since moved. New PV includes stable `pv_id` UUID and `row_id`; foreign/missing row links are rejected, while already-saved legacy unlinked PV may stay unlinked. Uploaded photo links cannot be orphaned by deleting a row. Row UUIDs and media links persist through revisions and immutable sync retries; phone metadata `team_group` is excluded from API payloads.

Bootstrap teams include `code` and nullable `group_identifier`. The phone uses the assigned team's last two number digits as the default for new rows. Users may edit each row's `group`; provided values must be strings containing exactly two ASCII digits (`^[0-9]{2}$`). Submitted rows require this value; drafts may leave it blank. The backend preserves explicit edits on both new and saved rows, including leading zeros, independently of subsequent team renumbering. It supplies a default only for a new blank draft row, and retains an existing row's saved value when a draft omits it. Existing access/assignment checks still apply.

Bootstrap omits imported transformers already claimed by saved surveys in the same feeder. New claims are serialized by locking the feeder, checking normalized code/imported ID across collectors, and returning 422 `transformer_code` on duplicate. Revisions of an existing survey with unchanged identity remain allowed, including historical duplicates. The phone also filters local saved drafts; separate offline phones may require duplicate reconciliation at sync time. Deploy the additive attachment/key migration before the updated phone build. Existing records and unlinked media are not deleted or assigned to arbitrary rows.

The application uses fixed production base URL `https://hazeco.barqaab.pk`. API helpers still accept a URL internally for tests; the mobile login does not expose server selection.

New mobile rows include optional `entry_version: 2`, which validates waypoint as a digit string (all row groups require exactly two digits) and equipment against the project EntryLookups source. Older rows may omit this marker. Bootstrap feeders include `entry_lookups` and `entry_settings` for cached/offline equipment choices and the two-digit-year window.

Submitted records require transformer_code, capacity_kva, inspectors and at least one complete S/E pair. Every pair needs exactly one S and one E. Every submitted row requires se, group, date, gps_waypoint, equipment_type, pole_class, pole_height_ft and at least one entered R/Y/B/N conductor. A valid zero is not blank. Drafts may leave paper fields blank but team/feeder/date are required. Do not invent requirements for solar/photos/GPS fix; Android retains waypoint identifiers but no longer captures phone coordinates or requests location permission. Submitted remains editable via revision sync until a future detailed review module exists. Do not write progress transaction tables or alter existing imported GIS records. Always preserve snapshot header values even if reference import changes.

## Phone state

Persist SQLite records on each save (and debounce autosave); status draft/queued/synced/error/conflict. Upload queued forms before attachment files, maintain unique IDs through retries. Update base_revision only after acknowledged server response. On network loss leave queued data durable; on 409 show conflict with explanation, do not silently replace server records. Login/session secure storage; never retain password. Cache bootstrap per user and URL; local records scoped to user+URL. Logout does not delete unsynced records. Draft editing a synced record marks draft with same UUID and last base_revision, never generates duplicate transformer survey. Retry manual Sync plus safe foreground reconnect retry; no background guarantee in MVP.


## Additional validation limits

Survey and row dates cannot be in the future. Upper numeric limits: capacity/installed solar 10,000,000; GPS accuracy 100,000,000; pole height and each consumer count 1,000,000. These are application bounds, not rules extracted from the paper. JSON survey request limit is 2 MB. For attachments, configure PHP upload_max_filesize to at least 10M and post_max_size to at least 12M; the API still limits each file to 10 MB.

## Automatic phase

Phase is derived from the R, Y and B conductor columns in that order: R + Y gives `RY`, and R + Y + B gives `RYB`. Neutral does not affect Phase. Empty/whitespace values and absence marks (`+`, `-`, en/em dash, `x`, `?`) do not indicate a populated phase conductor. The field is read-only in the web test and Android editors. The shared sync request recalculates Phase before validation/storage; historical records are not rewritten until resubmitted.
