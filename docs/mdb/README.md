# MDB Creation and Verification

The module adds private, versioned survey batches, source processing, manual PDF entry, explicit transformer networks, validation, immutable verification revisions, retryable genuine MDB export and separate SynerGEE analysis results. It reuses the application's project/feeder/team structure, Spatie permissions, Google Drive OAuth and audit trail.

Operators should start with the [simple entry guide](OPERATOR_GUIDE.md): PDF above the transformer header and individual S/E rows, followed by review and existing MDB generation. Advanced engineering tools and legacy sections remain on separate pages. See [deployment instructions](DEPLOYMENT.md), the [full team guide](USER_GUIDE.md), [sample inspection](SAMPLE_INSPECTION.md), [export mapping](MAPPING.md), [Access worker setup/protocol](WORKER.md), and [test results](TEST_RESULTS.md).

The local MySQL database was activated on 9 October 2026: the module migration and permission grants are installed, a local queue worker is running, and the installed Windows DAO/ACE exporter is configured. See [local activation and checks](LOCAL_ACTIVATION.md) for restart instructions and verification. Other environments default to a disabled exporter until configured.

The compact entry update adds GGDDMMYYWWW survey identities, integrated searchable dropdowns, project-level unit/year settings and automatic source/page/entry-sequence metadata. See [compact-entry verification](COMPACT_ENTRY_VERIFICATION.md) for compatibility and test evidence.

The one-transformer Noor Pur fixture proves genuine Access writing/readback; it does not establish approved electrical values or a verified transcription of all 27 handwritten PDF pages. Approved templates/settings and verified survey inputs are still required. SynerGEE opening, connectivity and load-flow acceptance remain separate Analysis Team steps.
