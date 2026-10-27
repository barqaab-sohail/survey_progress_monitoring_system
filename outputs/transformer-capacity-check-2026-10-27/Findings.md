# Transformer capacity inspection — 27 October 2026

Finding: transformer capacity is present in the production JSON but is still missing from the generated MDB transformer type field.

| Source | Transformer | Capacity / type |
|---|---|---|
| Production JSON: synergee_11140223003_batch-17_r79 (1).json | 11140223003 | snapshot.transformers[0].capacity_kva = 100 |
| Generated MDB: synergee_11140223003_batch-17_r79 (3).mdb | 11140223003 | InstPrimaryTransformers.TransformerType = empty string |
| User-designated approved reference: Choper HattaT-3030216724.mdb | See capacity-evidence.json | InstPrimaryTransformers.TransformerType = 200 KVA |

The reference represents a different transformer. Its 200 KVA value must not be copied to the 100 kVA transformer.

## Why the value is lost

The JSON has settings.simple_mdb_generation = true. Its settings.engineering.transformer_types mapping is empty, and settings.mapping.table_values.InstPrimaryTransformers has no TransformerType value.

ExportPayloadBuilder.php lines 98–107 obtains the transformer type from the capacity mapping. It does not automatically turn capacity_kva = 100 into a type label. The checks at lines 194–195 are engineering checks; the engineering() method at line 466 skips enforcement in Simple mode. export-reviewed-mdb.ps1 lines 22–28 writes missing required text as an empty string when the approved schema allows zero-length text. This explains the observed blank field.

## Required correction

Configure the verified 100 kVA capacity to use its exact transformer equipment type in the target SynerGEE library (for example, 100 KVA only if that exact type exists there), then regenerate the MDB and read back InstPrimaryTransformers.TransformerType. The approved reference demonstrates the type-label convention; it does not independently verify that a 100 KVA library entry exists in the target installation.

For an application change, ensure a known entered capacity cannot silently produce a blank transformer type, including in Simple mode. Numeric survey capacity and a verified library type are separate inputs in the current exporter.

## Verification and scope

Both supplied MDB files were opened read-only with the installed 32-bit DAO engine using scripts/mdb/inspect-reference.ps1. Both contain 50 user tables and 41 relationships. SHA-256 hashes were checked before and after reading and were unchanged. No saved queries, macros or modules were executed. No supplied MDB, JSON, application code or production settings were changed. SynerGEE UI display and load-flow operation were not tested.

Raw inspections: generated.json and approved-reference.json. Focused values and source hashes: capacity-evidence.json.
