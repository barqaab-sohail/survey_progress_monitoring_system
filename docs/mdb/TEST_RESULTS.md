# MDB workflow verification

Verified on 9 October 2026 in the existing Windows workspace using PHP 8.3.33, PHPUnit 11.5.56 and Python 3.13. PHP test database connections were SQLite `:memory:` and storage was isolated/fake or private temporary test storage. These automated tests did not change the configured application's MySQL database or original sample files. Local migration, permission seeding, initial read-only MySQL checks and the later rolled-back operator save checks are recorded in [LOCAL_ACTIVATION.md](LOCAL_ACTIVATION.md).

## Results

| Check | Result |
| --- | --- |
| Entire existing and new PHP suite | 215 tests, 2,629 assertions; all passed, none skipped |
| Authenticated remote MDB worker protocol | 3 tests; all passed |
| JavaScript/DOM operator tests | 6 tests; all passed |
| Pint for changed module PHP and tests | Passed |
| JavaScript syntax | Passed |
| Blade rendering | Simple, review and advanced screens passed feature and local MySQL rendering checks |
| Genuine Access writer/readback | Passed using installed Windows DAO/ACE |
| Original sample integrity | All three original SHA-256 hashes unchanged |
| A081026.pdf integrity | Read-only inspection; SHA-256 retained in operator verification |
| Local MySQL operator saves | Header, paired rows, duplicate-save protection and rollback passed |
| Browser visual/interaction QA | Pending; no in-app browser was available |
| SynerGEE opening, connectivity and load flow | Pending; SynerGEE was not invoked |

The final PHP command was:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit --log-junit tmp\mdb-composite-full-tests.xml
```

SQLite is installed but disabled in this workstation's default PHP CLI configuration. Process-local extension flags enable the isolated test database without editing PHP/application configuration. The final JUnit report and terminal output are retained at `tmp/mdb-composite-full-tests.xml` and `tmp/mdb-composite-full-tests.log`. PHPUnit explicitly disables the configured live writer by default; the genuine writer tests bind the Windows exporter themselves.

```powershell
python tests\Worker\test_mdb_worker.py
node --check public\js\mdb-workflow.js
node --check public\js\mdb-entry.js
```

The DOM test uses a generated Blade fixture and temporary JSDOM installation. Reproduction commands are in [OPERATOR_VERIFICATION.md](OPERATOR_VERIFICATION.md). [COMPACT_ENTRY_VERIFICATION.md](COMPACT_ENTRY_VERIFICATION.md) records the latest composite identity, integrated dropdown, project unit and automatic metadata checks.

## Meaningful coverage

GPX tests cover 1.0/1.1 namespaces, text names/leading zeros, nonnumeric names, elevation, descriptions, original XML, UTC timestamps and Asia/Karachi display, DTD/entity rejection, impossible dates, invalid/disguised uploads, duplicates and conflicting coordinates. Source jobs retain metadata across failure/retry, advance the batch revision, and never duplicate waypoint inserts on retry.

Network tests exercise exact branch endpoints, source-scoped identities, missing sample references 878/879 with PDF row context, ambiguous/duplicate points, self/duplicate sections, cycles, disconnected components, transformer/source connectivity, complete manual headers, explicit continuation pages, equipment/phase/conductor references, unknown units, blank counts, demand phase allocation, documented coordinate corrections and measured-length provenance. Real pyproj tests check projected metre coordinates, intermediate geometry, CRS area checks and invalid/nonmetric CRS rejection.

Feature tests cover role/project/source authorization, private hashes/version history, stale-edit rejection, revision-bound approval, approval invalidation/superseded outputs, immutable snapshots, configuration changes, custom analysis-role retention during profile/primary-role updates, original entry audit retention, PV corrections, section removal and PDF page association preservation. The rich entry/review render fixture includes sources, sections, consumer demand, PV, exports and analysis events. Analysis acceptance requires the preceding download/open/connectivity/load-flow steps; returning a model revokes its approval/output.

The current operator feature suite contributes seventeen tests with 186 assertions, alongside one phase test with 45 assertions and five survey-identity tests with 22 assertions. They cover all phase combinations, explicit unknown versus absent conductors, neutral preservation, composite identity and GPX context, individual pole details, blank/zero counts, boolean intersection, decimal PV fields, incomplete drafts, explicit pair topology, continuation pages, transformer isolation, original-entry retention, idempotent saves, stale revisions, confirmed deletion and existing section-only records. DOM tests additionally exercise page navigation, reload recovery, explicit copying, integrated dropdown keyboard search, automatic metadata, source-processing refresh and duplicate-click protection.

Export tests cover exact separate conductors and per-phase demand, canonical R/Y/B/N slots for partial-phase records, signed measured kvar, stable branch mappings, absent source mappings, positive PV exclusions, missing load phases, private disks/source bytes, immutable hashes, idempotent queue requests, retry/failure lifecycle, duplicate delivery, stale approval, altered retained payloads, honest disabled-worker status, disguised SQLite rejection and genuine DAO reopening/readback. Worker protocol tests verify authenticated export/reuse, changed-payload rejection, unauthenticated requests, forbidden paths and invalid table names.

## Genuine MDB fixture and limits

The new DAO writer copied the clean schema fixture, wrote the six relevant tables from the original Noor Pur transformer MDB and reopened the resulting Jet database. It verified Node 22, InstSection 20, InstFeeders 1, InstPrimaryTransformers 1, Loads 19 and SAI_Control 1, exact mapped values, schema/index/relationship preservation and valid model references. Laravel verifies file/payload/output identities and the real Jet header in addition to the worker readback evidence. This is a technical one-transformer writer fixture, including the sample's explicitly unreviewed settings. It does not constitute an approved engineering model or verified extraction of the scanned PDF.

A second genuine fixture starts with four individual S/E rows forming two explicitly recorded branches. It passes through the existing export request/job and DAO writer, then reopens the MDB to verify five nodes, four sections, zero demand loads and canonical neutral slots. Its reviewed engineering inputs are synthetic test data. It proves row-to-export compatibility, without claiming transcription or operational approval of A081026.pdf.

The browser skill runtime reported `iab` unavailable and browser discovery returned no browsers. No alternative browser-control mechanism was substituted. Server rendering, form routes and static responsive CSS/JavaScript checks passed; visual layout, PDF viewer interaction and mobile browser verification remain pending. See the [browser skill](C:/Users/sohai/.codex/plugins/cache/openai-bundled/browser/26.707.31428/skills/control-in-app-browser/SKILL.md) for the browser availability workflow.

The application defaults to a disabled MDB worker; the local development environment now explicitly enables the installed Windows writer. Approved templates, reviewed equipment libraries/electrical settings/load methods and all verified survey rows/coordinates remain required before operational export. MySQL was initially unavailable and was later activated successfully, with local migration, permission grants and read-only application checks recorded in [LOCAL_ACTIVATION.md](LOCAL_ACTIVATION.md). Instructions for other environments remain in [DEPLOYMENT.md](DEPLOYMENT.md).
