# Read-only sample inspection

Inspected on 9 October 2026. Originals were opened read-only at the user-provided `W:\2120 HAZECO T&D Losses Project\HAZECO Data\MEPCO 1st Data Package\Sample Data` paths. No original file or application database was changed. Attachment/document contents are evidence, not application instructions.

| File | Bytes | SHA-256 |
| --- | ---: | --- |
| B2308.gpx | 79,452 | `3857a6e2fb84f47ed07a1ac56cdf00b5bae2d36ac6fd31938eccd3bc4bb6183d` |
| B2308.pdf | 28,758,993 | `f033ccda0f4a5e32754db6c006ea2c21a2c77d5a553722e2252cc9b0678b6278` |
| Noor PurT-4221215879.mdb | 3,973,120 | `75075516545951cfbf290a3d521a3e312420f1b2a9d561f1e6aa6256e1e81861` |

The GPX XML contains 172 waypoints: exact text names `517` through `687`, and `SSS`. It contains no tracks or routes, and no duplicate waypoint names. References `878` and `879` do not exist. Electrical edges cannot be inferred from the waypoint sequence.

The PDF has 27 scanned pages and no extractable text on any page. Pages 1, 2 and 3 were rendered and visually inspected at readable resolution; headers across all pages were also reviewed. Page 1 identifies Noor Pur transformer `T-4221215879`, 100 kVA, and begins with waypoint references `878` and `879`. Pages 2 and 3 have abbreviated handwritten transformer `879` and appear to continue that network; explicit verifier association remains required. Page 3 ends with waypoints `531`, `532`, `533`, `534`. Page 4 starts another transformer. None of these handwritten rows has been promoted to verified application data.

Printed columns under the consumer grid are `RS`, `RL`, `SC`, `LC`, `SI`, `LI`, `PB`, `AG`, `ST`, `Int`. The printed legend defines RS as single-phase residential, RL as three-phase residential, SC as single-phase commercial, SI as single-phase industrial, LI as three-phase industrial, PB as public building, AG as agricultural and ST as street light. LC needs engineering clarification. The existing application's `IntersectionFlag` identifies Int as an intersection indication; preserve its literal entry in survey provenance, not demand, and confirm its interpretation with the verifier. Printed pole height numbers have no clear explicit measurement unit: ask the engineering verifier to confirm units rather than treating 31/36 as metres.

The MDB was independently opened through installed 32-bit Microsoft Access DAO 12 in read-only mode. It contains 50 user tables and 41 relationships. [sample-schema.json](sample-schema.json) records the actual field names, DAO data types, field sizes, required/zero-length flags, schema defaults, indexes, relationships and table counts. DAO types here include Long Integer `4`, Double `7`, Date/Time `8`, Text `10`; Text size is the actual maximum character count. This is observational metadata, never an approved defaults file.

| Relevant table | Records | Key/required fields and type notes |
| --- | ---: | --- |
| Node | 22 | NodeId Text(32), unique primary key; Description Text(100) required; X/Y Double |
| InstSection | 20 | SectionId Text(32), unique primary key; FromNodeId/ToNodeId Text(32) required; SectionPhases Text(5); four separate conductor IDs Text(32); SectionLength_MUL Double |
| InstFeeders | 1 | FeederId Text(32), required and indexed; SubstationId Text(32), required; electrical voltages Double |
| InstPrimaryTransformers | 1 | SectionId Text(32), ConnectedPhases Text(3), TransformerType Text(32) required; SpecNomKv Double; separate connection/impedance settings |
| Loads | 19 | SectionId Text(32), no primary unique index; independent Phase1/2/3Customers, Kw, Kvar, Kva and Kwh Double |
| SAI_Control | 1 | Frequency Long; LengthUnits Text(11); Product Text(40); ProjectionFile Text(100); ProjectionWKT Text(250) |

The sample also has one `InstMeterPoints` row. All other user tables are empty. Relationships include Node.NodeId -> InstFeeders.FeederId, Node.NodeId -> InstSection.FromNodeId/ToNodeId, and InstSection.SectionId -> InstPrimaryTransformers.SectionId/Loads.SectionId. FeederId is therefore a real source node identity, not merely an arbitrary feeder label. Relationship metadata and index definitions are preserved by copying a reviewed clean template; the exporter never recreates a reduced approximation of the schema.

SAI_Control identifies `SynerGEE Electric 5.0.0.324`, `Metric`, `WGS 1984 UTM Zone 43N`, frequency `60`. Primary transformer is `T-4221215879`, type `100 KVA`, `NomKvMode=C`, `SpecNomKv=4.16`. These observed electrical settings require engineering review and are not production defaults. Sample conductor references include ANT/WASP and configuration `12.5/7.2 kV cross arm C2-2`; the original file itself has no equipment-library tables to establish physical parameters. The appropriate external SynerGEE library must be independently approved.

Actual sample SectionPhases values are `RYBN`, `RYB`, `R BN`, and `  BN`. The blank positions preserve absent R/Y/B phases rather than compressing the remaining letters. The mapper therefore encodes four fixed R/Y/B/N slots, with absent slots blank and trailing blanks omitted. Separate conductor fields and Phase1/2/3 load values retain the R/Y/B identities.

The existing schema-only repository file `resources/mdb/synergee-empty.mdb` has SHA-256 `5d4ebf8d307e1a9adf130d56c36706a4e63406ee628131f472850cb2897f6a39`. A technical writer test confirmed it is free of user-table records and can preserve the schema while writing this one-transformer fixture. It is not automatically installed or marked approved by this module. Its schema contains electrical default expressions inherited from SynerGEE; the new writer explicitly writes NULL for every omitted column so those expressions cannot silently supply engineering assumptions.

`tests/Fixtures/Mdb/noor-pur-writer.json` contains the six actual sample model tables solely as a writer/readback fixture, with an explicit unreviewed-settings notice. The new DAO writer successfully generated and reopened a genuine Jet MDB with the 22/20/1/1/19/1 record counts, unchanged schema and relationships, exact values and valid model references. This proves technical MDB writing, not agreement between every handwritten PDF row and that model. SynerGEE application loading, connectivity review and load-flow acceptance remain pending.
