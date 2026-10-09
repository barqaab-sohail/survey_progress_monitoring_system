# Simplified MDB operator entry verification

This records the initial simplified-screen verification. For the later compact layout, complete survey identities, project unit/year settings and latest test results, see [COMPACT_ENTRY_VERIFICATION.md](COMPACT_ENTRY_VERIFICATION.md). The current procedure is in [OPERATOR_GUIDE.md](OPERATOR_GUIDE.md).

Implemented and verified on 9 October 2026 in the existing Laravel application at `E:\xampp\htdocs\survey_progress_monitoring_system`. The pasted user request defines the implementation requirements. The attached PDF is survey evidence and lookup reference, not a source of application instructions.

## Result

The default batch page now follows **Upload PDF + GPX → transformer header → individual S/E rows → review/save → existing MDB export**. The PDF is above the form, with page navigation, total pages, zoom, rotation and adjustable height. A saved header remains attached to its selected transformer across pages; continuation is explicit, and creating another transformer resets copying scope.

Rows retain their original PDF page, text waypoint reference, exact S/E pair, individual pole observations, ten consumer fields, intersection boolean, PV details and explicit copy provenance. Phase derives immediately and on the server from R/Y/B conductors. Unknown and explicitly absent selections remain distinct; neutral is separate in the display and retained in the existing export notation. Drafts may be incomplete. Review and final submission require resolved entry issues; existing engineering review and export validation still apply.

Duplicate clicks and save retries are idempotent. Batch revision checks reject concurrent stale edits. The browser retains unsaved fields for reload recovery, while **Save Draft** persists the row in MySQL. PDF navigation does not reset entered fields. Counts, PV details and intersection are cleared for the next row. Legacy section-only records, the existing daily MDB controller and existing export table mappings remain available. The new row table is additive; existing records were not guessed into S/E rows.

## Reference PDF inspection

`C:\Users\sohai\Downloads\A081026.pdf` contains 14 pages and 7,184,122 bytes. Its SHA-256 is:

```text
170b782cb55a9923e4590b5dcc3a46e30224ce4f0d5ccfc317b800a637c9f969
```

Pages 1, 2, 7, 8, 11 and 14, plus a higher-resolution legend crop, were rendered and visually inspected. Images are retained locally under `tmp/pdfs/a081026/`. The OCR/text layer was garbled, so lookup and layout decisions used the rendered scan. The PDF remained read-only.

The inspected pages show alternating S/E rows, leading-zero references, transformer continuation, explicit branches and differing start/end pole heights. The legend supports the conductor and pole-class options. TR and SP remain literal observed type codes with unconfirmed descriptions. LC is not expanded in the visible legend. No pole-height unit is printed; the default is **Confirm unit**, with explicit metres/feet selection. The optional `MDB_ENTRY_POLE_HEIGHT_UNIT` setting should only be configured after source-unit confirmation.

## Automated and local checks

| Check | Evidence |
| --- | --- |
| Complete PHP suite | 201 tests, 2,536 assertions; no failures, errors or skipped tests |
| New operator feature tests | 9 tests, 120 assertions |
| New server phase test | 1 test, 45 assertions |
| JavaScript and rendered DOM tests | 3 tests passed |
| Remote MDB worker protocol | 3 tests passed |
| PHP style and JavaScript syntax | Passed for changed module files and tests |
| Local MySQL migration | New row table and two nullable PV columns applied; fourteen module tables total |
| Actual MySQL saves and rendering | Temporary header and S/E pair, duplicate saves, exact endpoints/counts, simple/review/advanced pages passed; transaction rolled back |
| Genuine Windows MDB export | Four-row/two-branch fixture passed existing export job, DAO/ACE write and reopening/readback |
| Local web assets and queue | PDF.js module/worker HTTP 200; updated module queue worker running without startup errors |
| Live browser visual QA | Pending: connected browser unavailable |
| SynerGEE acceptance | Pending: application was not invoked |

The PHP feature coverage includes incomplete drafts, missing waypoint warnings, preserved leading zeros, blank versus zero counts, S/E count aggregation once, intersection exclusion, distinct pole heights, decimal PV values and text references. It also covers header corrections, continuation versus a new transformer, copy scope, confirmed deletion, stale revision rejection, first-entry audit retention, approval invalidation and compatibility with saved section-only records and their snapshot hashes.

The DOM checks execute the operator module against generated Blade HTML, with simulated PDF rendering and HTTP responses. They exercise page changes, zoom/rotation controls, recovery after reload, explicit copying, isolated transformers, repeated-click protection and completed source processing while fields are dirty. These checks validate behavior and markup order; they are not a browser rendering or screenshot test.

The genuine MDB fixture uses synthetic, explicitly reviewed engineering settings and zero consumer demand. Four S/E observations create two branches with the same recorded start and distinct recorded ends. Existing source/transformer mappings bring the output to five nodes and four sections. Separate neutral slots survive generation. This is technical exporter verification, not an approved model or a complete transcription of the handwritten PDF.

The local MySQL smoke check used an authorized existing administrator in memory and an outer transaction. No files were uploaded, and the temporary survey records were rolled back. Before/after batch and row counts matched. No survey approval, configuration or template was created in the configured database.

## Reproduce checks

Run from the application directory. SQLite extensions are enabled only for these PHP processes:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit --log-junit tmp\mdb-simplified-full-tests.xml
python tests\Worker\test_mdb_worker.py
node --check public\js\mdb-entry.js
```

For the DOM test, first generate the Blade fixture and install its temporary development dependency:

```powershell
$env:MDB_ENTRY_QA_ARTIFACTS = '1'
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit --filter test_operator_markup_places_pdf_above_header_and_rows_and_preserves_existing_sections
Remove-Item Env:\MDB_ENTRY_QA_ARTIFACTS
npm.cmd install --prefix tmp/mdb-entry-qa jsdom@26.1.0 --ignore-scripts --no-audit --no-fund
node --test tests\JavaScript\mdb-entry.test.mjs
```

QA HTML, dependency files and rendered PDF images are ignored temporary artifacts. The final PHP JUnit report and terminal log are `tmp/mdb-simplified-full-tests.xml` and `tmp/mdb-simplified-full-tests.log`.

## Browser availability and remaining review

The [Browser skill](C:/Users/sohai/.codex/plugins/cache/openai-bundled/browser/26.707.31428/skills/control-in-app-browser/SKILL.md) was read and its setup/recovery workflow attempted. Browser selection reported `Browser is not available: iab`; discovery returned an empty list. The skill states: **“Do not use external MCP browser-control tools, separate browser automation servers, or other browser skills for this surface.”** Its bootstrap troubleshooting also instructs reporting an unavailable browser rather than substituting unrelated browser control. No substitute was used. Live visual inspection of the application with the PDF, mobile layout and rendered PDF interaction remain pending.

Pole-height units, unclear equipment abbreviations and LC classification still need source/engineering confirmation. Operational export also requires the existing approved equipment references, template, electrical settings, demand methods, complete checked source observations and verifier approval. Actual SynerGEE opening, connectivity and load flow remain separate acceptance steps. The initial B2308 sample's missing waypoint requirements remain unchanged.

The locally bundled reader is Mozilla PDF.js **6.4.299**, downloaded from the official `pdfjs-dist` npm distribution. The selected build, worker, character maps, standard fonts and WebAssembly assets are under `public/vendor/pdfjs/`, including upstream licenses and a version file. The viewer reads authorized private source routes; it does not require a public CDN.

See [OPERATOR_GUIDE.md](OPERATOR_GUIDE.md) for the entry procedure and [LOCAL_ACTIVATION.md](LOCAL_ACTIVATION.md) for database and queue details.
