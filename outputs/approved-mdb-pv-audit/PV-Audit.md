# Approved SynerGEE MDB PV audit

All MDBs found under the supplied HAZECO folder were inspected except data-entry databases. This includes the main folder, One Complete sample, and Sample data. Files were read-only, and SHA-256 hashes were unchanged. Repeated copies are listed separately.

| Source | PV generator records | Modelled kW (Year 0) |
|---|---:|---:|
| Choper HattaT-3030216724.mdb | 0 | 0.00 |
| Choper HattaT-3030216727.mdb | 0 | 0.00 |
| Jodh PurT-4101220409.mdb | 0 | 0.00 |
| Jodh PurT-4151220461.mdb | 1 | 8.96 |
| Jodh PurT-4171220510.mdb | 1 | 5.65 |
| Jodh PurT-4171220531.mdb | 2 | 14.56 |
| Kot Gohar MT-3150216178.mdb | 0 | 0.00 |
| Noor PurT-4070621060.mdb | 0 | 0.00 |
| Noor PurT-4221215879.mdb | 0 | 0.00 |
| One Complete sample\Kot MadinaT-8160421145.mdb | 1 | 5.00 |
| Sample data\Allah AbadT-4130116282.mdb | 0 | 0.00 |
| Sample data\Allah AbadT-4130116284.mdb | 0 | 0.00 |
| Sample data\City 1T-4060821223.mdb | 2 | 18.60 |
| Sample data\IndustrialT-4060116233.mdb | 0 | 0.00 |
| Sample data\IndustrialT-4120623648.mdb | 0 | 0.00 |
| Sample data\Kot MadinaT-8160421110.mdb | 0 | 0.00 |
| Sample data\Kot MadinaT-8160421145.mdb | 1 | 5.00 |
| Sample data\Mosa PakT-2241017145.mdb | 0 | 0.00 |
| Sample data\New BosanT-2211117034.mdb | 0 | 0.00 |
| Sample data\Z TownT-2301017107.mdb | 0 | 0.00 |
| Sample data\One Data for A0808\valid_synergee.mdb | 1 | 5.00 |

PV-labelled records use InstDGens.DGenDesc values beginning PVGEN-. Their phase kW values are in InstMymDGens, YearNumber 0. InstGenerators is empty in these files; checking that table alone misses PV entries. Generator records identify modelled PV connection points, not necessarily a complete count of individually metered PV customers.

## PV connection details

| Source | Section | Description | Year 0 phase kW (R / Y / B) | Total kW |
|---|---|---|---|---:|
| Jodh PurT-4151220461.mdb | 1059082230825566 | PVGEN-1059082230825566 | 2.986667 / 2.986667 / 2.986667 | 8.96 |
| Jodh PurT-4171220510.mdb | 1059082230825622 | PVGEN-1059082230825622 | 1.883333 / 1.883333 / 1.883333 | 5.65 |
| Jodh PurT-4171220531.mdb | 1059082230825609 | PVGEN-1059082230825609 | 2.340000 / 2.340000 / 2.340000 | 7.02 |
| Jodh PurT-4171220531.mdb | 1059082230825611 | PVGEN-1059082230825611 | 2.513333 / 2.513333 / 2.513333 | 7.54 |
| Kot MadinaT-8160421145.mdb | 103331080825010 | PVGEN-103331080825010 | 1.666667 / 1.666667 / 1.666667 | 5.00 |
| City 1T-4060821223.mdb | 87011140825578 | PVGEN-87011140825578 | 2.200000 / 2.200000 / 2.200000 | 6.60 |
| City 1T-4060821223.mdb | 87011140825581 | PVGEN-87011140825581 | 4.000000 / 4.000000 / 4.000000 | 12.00 |
| Kot MadinaT-8160421145.mdb | 103331080825010 | PVGEN-103331080825010 | 1.666667 / 1.666667 / 1.666667 | 5.00 |
| valid_synergee.mdb | 103331080825010 | PVGEN-103331080825010 | 1.666667 / 1.666667 / 1.666667 | 5.00 |
