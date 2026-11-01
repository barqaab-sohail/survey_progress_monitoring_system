| # | Column | MDB type | Required | Population |
| --- | --- | --- | --- | --- |
| 1 | SectionId | Text(32) | Yes | Six-digit feeder code + own row group + DDMMYY + waypoint; text suffix for transformer/connection. |
| 2 | FeederId | Text(32) | No | Exact identifier already stored in the relevant Node and InstFeeders record. |
| 3 | FromNodeId | Text(32) | Yes | Actual generated Node in the feeder/transformer/pole/connection chain. |
| 4 | ToNodeId | Text(32) | Yes | Actual generated Node; consumer/PV endpoints come from the shared phase plan. |
| 5 | SectionPhases | Text(5) | No | Verified available phases; recorded allocations first, then unallocated rotation. Fixed R/Y/B/N slots. |
| 6 | Description | Text(100) | No | Feeder / Transformer / Secondary Pole, according to workbook role. |
| 7 | PhaseConductorId | Text(32) | No | Feeder 500 MCM; transformer 4/C 07/.052; pole PDF R conductor; connection PVC 07/0.052. |
| 8 | NeutralConductorId | Text(32) | No | Feeder NULL; transformer 4/C 07/.052; pole PDF N conductor; connection PVC 07/0.052. |
| 9 | PhaseToPhaseSpacing_MUL | Double | No | `121.9` (workbook) |
| 10 | PhaseToNeutralSpacing_MUL | Double | No | `91.4` (workbook) |
| 11 | AveHeightAboveGround_MUL | Double | No | `9.1` (workbook) |
| 12 | PercentDistLoadConstImpedance | Double | No | `40` (workbook) |
| 13 | PercentDistLoadConstCurrent | Double | No | `10` (workbook) |
| 14 | PercentSpotLoadConstImpedance | Double | No | `40` (workbook) |
| 15 | PercentSpotLoadConstCurrent | Double | No | `30` (workbook) |
| 16 | SectionLength_MUL | Double | No | Feeder NULL; transformer 0; first pole 10; later pole existing 3D span; connection E-height cable formula. |
| 17 | PercentLoadGrowthRate | Double | No | `2` (workbook) |
| 18 | MappingLink | Long Integer | No | `0` (workbook) |
| 19 | LoadConnectionPhaseGnd | Long Integer | No | Transformer 0; feeder, poles and consumer/PV connections 1. |
| 20 | UseEquivSpacing | Long Integer | No | `0` (workbook) |
| 21 | IdenticalPhaseConductors | Long Integer | No | `0` (workbook) |
| 22 | PhaseConductor2Id | Text(32) | No | As PhaseConductorId, using the PDF Y conductor for poles. |
| 23 | PhaseConductor3Id | Text(32) | No | As PhaseConductorId, using the PDF B conductor for poles. |
| 24 | PositionCode | Long Integer | No | `0` (workbook) |
| 25 | ConfigurationId | Text(32) | No | `12.5/7.2 kV cross arm C2-2` (workbook) |
| 26 | IsContingency | Long Integer | No | `0` (workbook) |
| 27 | UseSectAmpRating | Long Integer | No | `0` (workbook) |
| 28 | AmpRating | Double | No | `100` (workbook) |
| 29 | DistLoadCustZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 30 | SpotLoadCustZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 31 | UseDistCustZone | Long Integer | No | `0` (workbook) |
| 32 | UseSpotCustZone | Long Integer | No | `0` (workbook) |
| 33 | UseCustClassDistLoadIzpq | Long Integer | No | `0` (workbook) |
| 34 | UseCustClassSpotLoadIzpq | Long Integer | No | `0` (workbook) |
| 35 | ExposureZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 36 | MitiZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 37 | GrowthZone | Text(32) | No | `Unknown` (workbook) |
| 38 | FromElbowOrBayStatus | Text(1) | No | `N` (workbook) |
| 39 | ToElbowOrBayStatus | Text(1) | No | `N` (workbook) |
| 40 | ElbowOrBayAmpRating | Double | No | `100` (workbook) |
| 41 | DuctBankId | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 42 | DuctBankInst | Text(32) | No | Existing mapped value retained; schema default unchanged. |
| 43 | DuctBankCircuit | Long Integer | No | `1` (workbook) |
| 44 | UseDuctBankAmpRating | Long Integer | No | `0` (workbook) |
| 45 | UseDuctBankConfiguration | Long Integer | No | `0` (workbook) |
| 46 | SpotLoadIsOff | Long Integer | No | `0` (workbook) |
| 47 | EnergizeYear | Long Integer | No | `0` (workbook) |
| 48 | RetireYear | Long Integer | No | `100` (workbook) |
| 49 | LoadCategory | Long Integer | No | `0` (workbook) |
| 50 | NeutIsGrounded | Long Integer | No | `1` (workbook) |
| 51 | HideMapResults | Long Integer | No | `0` (workbook) |
| 52 | Note_ | Text(100) | No | Existing mapped value retained; schema default unchanged. |
| 53 | LocationLink | Text(200) | No | `*` (workbook) |
| 54 | AMSLink | Text(125) | No | Existing mapped value retained; schema default unchanged. |
| 55 | Monitor | Text(10) | No | Existing mapped value retained; schema default unchanged. |
| 56 | ExpFactPermFail | Double | No | `1` (workbook) |
| 57 | ExpFactTempFail | Double | No | `1` (workbook) |
| 58 | ExpFactRepair | Double | No | `0` (workbook) |
| 59 | CostZone | Text(32) | No | Existing mapped value retained; schema default unchanged. |