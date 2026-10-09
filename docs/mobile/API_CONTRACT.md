# LT field survey mobile API contract (v1)

Reference: B2308.pdf, 27 scanned LT survey sheets. Use blank schema, not handwritten sample values. API base: configured application URL + `/api/v1/field`. Android user enters application URL, not API path. Assigned survey-team-leader accounts or super admin can collect. First login and download require internet; collection afterwards is offline.

## Endpoints

- POST `/login` JSON {email,password,device_name} -> {token,user:{id,name},expires_at}. Bearer tokens on subsequent requests.
- POST `/logout` -> 204 (current device token).
- GET `/bootstrap` -> {user:{id,name},teams:[{id,name,project_id}],feeders:[{id,feeder_code,feeder_name,grid_station_name,division_name,sub_division_name,sub_division_code,team_ids:[int]}],transformers:[{id,feeder_id,transformer_code,capacity_kva,equipment_make,equipment_location}]}. Restrict to active projects/teams/feeders and current user memberships/assignments. Super admin uses all active teams/assignments.
- POST `/surveys/sync` JSON payload below -> {id,client_uuid,revision,status}. 201 first sync, 200 idempotent replay/update. 409 stale base_revision or UUID collision with different owner; 422 field validation; 403 revoked assignment/account.
- POST `/surveys/{client_uuid}/attachments` multipart {client_uuid,kind,file} -> {id,client_uuid}. Stable attachment UUID, retries do not duplicate, different bytes for same UUID ->409. kind=photo or sketch. File JPEG/PNG/PDF <=10MB; private server storage. Survey owner must still be assigned. The phone retains its attached image copy for retry and offline viewing.
- GET `/attachments/{attachment_uuid}` -> authenticated private file download. The API permits the survey owner with current assignment access; web management access uses its separate scoped route.

## Survey sync payload

Root: `client_uuid` UUID, `base_revision` integer (0 for new), `survey_team_id` int, `feeder_id` int, `transformer_id` nullable int, `transformer_code` string max100, `survey_date` YYYY-MM-DD, `status` draft/submitted, `header` object, `rows` array <=500, `solar` array <=100, `remarks` string <=2000.

Header keys (strings <=200 except location <=500): `substation`, `division`, `sub_division`, `sub_division_code`, `transformer_make`, `inspectors`, `location`; `capacity_kva` nullable number >0; `mounting` empty/S.Pole/D.Pole/Pad; `duty` empty/General Duty/Dedicated.

Rows model each printed S/E row, not an invented whole transformer total. Keys: `se` S/E, `group` string<=20, `date` YYYY-MM-DD, `gps_waypoint` string<=50, `latitude` nullable -90..90, `longitude` nullable -180..180, `gps_accuracy_m` nullable number>=0, `phase` string<=30; `conductor_r`,`conductor_y`,`conductor_b`,`conductor_neutral` strings<=100 (offer A,W,GN,2/0 AWG,PVC 7/0.052,PVC 19/0.052,PVC 19/0.083,USAID 50mm2,USAID 95mm2,Ang,Int,Other); `equipment_type` string<=50, `pole_class` string<=50 (S,PCO,PCS,RS,TS,WB), `pole_height_ft` nullable number>=0; `consumers` object with optional nonnegative integer keys `rs`,`rl`,`sc`,`lc`,`si`,`li`,`pb`,`ag`,`st`; `intersection` optional boolean (Intersection checkbox, separate from consumers); `remarks` optional string<=1000.

Solar keys: `consumer_reference` string<=100, `installed_pv_kw` nullable number>=0, `remarks` string<=1000.

Submitted records require transformer_code, capacity_kva, inspectors, at least 1 row with gps_waypoint; all submitted rows need se/date/gps_waypoint. Drafts may leave paper fields blank but team/feeder/date required. Do not invent requirements for solar/photos/GPS fix; Android retains waypoint identifiers but no longer captures phone coordinates or requests location permission. Submitted remains editable via revision sync until a future detailed review module exists. Do not write progress transaction tables or alter existing imported GIS records. Always preserve snapshot header values even if reference import changes.

## Phone state

Persist SQLite records on each save (and debounce autosave); status draft/queued/synced/error/conflict. Upload queued forms before attachment files, maintain unique IDs through retries. Update base_revision only after acknowledged server response. On network loss leave queued data durable; on 409 show conflict with explanation, do not silently replace server records. Login/session secure storage; never retain password. Cache bootstrap per user and URL; local records scoped to user+URL. Logout does not delete unsynced records. Draft editing a synced record marks draft with same UUID and last base_revision, never generates duplicate transformer survey. Retry manual Sync plus safe foreground reconnect retry; no background guarantee in MVP.


## Additional validation limits

Survey and row dates cannot be in the future. Upper numeric limits: capacity/installed solar 10,000,000; GPS accuracy 100,000,000; pole height and each consumer count 1,000,000. These are application bounds, not rules extracted from the paper. JSON survey request limit is 2 MB. For attachments, configure PHP upload_max_filesize to at least 10M and post_max_size to at least 12M; the API still limits each file to 10 MB.

## Automatic phase

Phase is derived from the R, Y and B conductor columns in that order: R + Y gives `RY`, and R + Y + B gives `RYB`. Neutral does not affect Phase. Empty/whitespace values and absence marks (`+`, `-`, en/em dash, `x`, `?`) do not indicate a populated phase conductor. The field is read-only in the web test and Android editors. The shared sync request recalculates Phase before validation/storage; historical records are not rewritten until resubmitted.
