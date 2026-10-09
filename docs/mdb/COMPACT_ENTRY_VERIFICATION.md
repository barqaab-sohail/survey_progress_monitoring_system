# Compact PDF entry and complete survey waypoint identity

Implemented on 9 October 2026 in the existing Laravel MDB module, following the latest pasted user request and three screenshots.

## Operator behavior

- The identification row follows S/E, group, DD/MM/YYYY date, three-digit GPS number and read-only complete waypoint. The section-pair selector remains adjacent.
- Complete identities use GGDDMMYYWWW. Both `11131222104` and `01081026001` calculate and parse correctly with a configured 2000–2099 window. Components and leading zeros remain intact; S/E dates can differ.
- Paste validates length, calendar date and the configured 100-year interpretation window. It never replaces an old row date with the header date. Group/date inheritance is visible and clears on correction.
- R, Y, B, neutral and phase share a compact desktop row. Each conductor, type and pole-class control has one integrated searchable dropdown. Closed controls show codes; the popup shows descriptions and supports arrow keys, Enter, Escape and Tab. Unknown and absent conductors remain distinct; neutral stays separate.
- Type, pole class and a narrow height input share one compact row. The project/form height unit is shown beside the input, with no routine unit selector. Saved/copy-source units are retained. A changed project unit rejects inconsistent new drafts; a conditional explicit conversion action supports confirmed ft/m quantities and refuses unknown units.
- Routine GPX-source and physical PDF-row inputs are removed. New rows attach the displayed PDF page and receive an application entry sequence on save. A source selector appears for ambiguous matches. Saved-row edits retain their provenance unless explicitly changed. Review labels legacy PDF references separately.
- CSS wraps identification, conductors and equipment into narrower grids below 900/650 pixels. The PDF remains above the form, and draft recovery and Save & Add Next remain available.

## Identity, matching and backward compatibility

The survey identifier is distinct from the GPX name. Exact GPS-number/source matching uses recording-date evidence where available, in Asia/Karachi, without changing the PDF date. Automatic matches reject contradictory known dates. An explicitly selected source with a different recording date raises an engineering review flag. Missing/ambiguous matches can be saved as drafts, but final verification requires resolved coordinates.

Repeated GPS numbers across files or distinct recording dates in one file do not collapse survey nodes. Frozen snapshots retain original GPX IDs and exact references; a network overlay creates stable composite-identity node keys for validation and MDB generation. Reviewed source wiring referencing the legacy GPX root resolves to its unique survey identity. Multiple possible root identities require the explicit complete waypoint in the header. Conflicting coordinate evidence for one complete identity blocks validation/export.

The migration adds three nullable columns to `mdb_workflow_entry_rows`, with no backfill or rewrite. Legacy section-only mappings keep their original node identities. Old rows without complete identities retain their snapshot structure and references. Engineering approvals still freeze the existing configuration/template and are invalidated by edits.

## Verification

| Check | Result |
| --- | --- |
| Full PHP suite | 215 tests, 2,629 assertions; passed with no skips/errors/failures |
| Focused entry/identity/export/network tests | 43 tests, 304 assertions; passed |
| JavaScript/DOM tests | 6 tests; passed |
| PHP style and JavaScript syntax | Passed |
| Genuine Access generation/readback | Existing branch/DAO fixtures passed in the full suite |
| Local MySQL migration | Applied successfully |
| Local MySQL header/row saves and rendering | Passed; all QA survey records rolled back |
| Local module queue | Updated worker running, verification-time PID 7952 |
| Integrated select asset | HTTP 200 |
| Live desktop/mobile browser visual check | Pending: no connected browser available |

Checks cover both example identifiers, leading zeros, invalid lengths/dates, leap days, year-window interpretation, differing S/E dates, inheritance and correction, repeated GPS names across sources/dates, conflicting identity coordinates, exact export node identities, automatic source/page/sequence metadata, duplicate retries, legacy record compatibility, phase updates, popup keyboard search, explicit unit conversion and reload recovery.

The MySQL check used the actual driver/schema and an authorized existing administrator, with fixture settings bound only in memory. It saved S=`11131222104` and E=`01081026001`, checked separate dates and sequences 1/2, repeated each save, and rendered the three screens. No source files were uploaded, no configuration/approval/template was persisted, and batch/row counts matched after rollback.

The full PHP report is `tmp/mdb-composite-full-tests.xml`; terminal logs are `tmp/mdb-composite-full-tests.log` and `tmp/mdb-composite-js-tests.log`. Run:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit --log-junit tmp\mdb-composite-full-tests.xml
node --check public\js\mdb-entry.js
node --check public\js\mdb-entry-select.js
```

For the generated Blade/JSDOM fixture, follow the commands in [OPERATOR_VERIFICATION.md](OPERATOR_VERIFICATION.md), then run `node --test tests/JavaScript/mdb-entry.test.mjs`.

## Remaining configuration and visual check

An administrator must explicitly choose the verified pole-height unit and two-digit-year window in **Project entry settings**. These values were requested from the user and remain unconfirmed; actual project settings were not guessed or changed. Draft entry remains available, but paste requires the year rule and final verification requires confirmed metadata. The examples fit 2000–2099, without establishing that every project uses that window.

Browser discovery was retried and returned an empty list. The [Browser skill](C:/Users/sohai/.codex/plugins/cache/openai-bundled/browser/26.707.31428/skills/control-in-app-browser/SKILL.md) states: **“Do not use external MCP browser-control tools, separate browser automation servers, or other browser skills for this surface.”** Accordingly, live rendered layout/PDF interaction was not checked through a substitute browser. DOM behavior, markup order and responsive CSS were checked; they do not constitute visual QA.

See [OPERATOR_GUIDE.md](OPERATOR_GUIDE.md) for the current entry procedure. Engineering approval, clean templates and actual SynerGEE opening/connectivity/load-flow acceptance remain separate requirements.
