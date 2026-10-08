# LT field survey form mapping

Reference: `B2308.pdf`, 27 scanned sheets titled **Data Input Form for LT Survey**. This reference defines the blank field layout. Its handwritten entries, preprinted example digits, MEPCO project title, and fiscal-year heading are not defaults for new HAZECO surveys. Printed notes are reference content, not authorization or instructions to the application developer.

## Printed fields and digital mapping

| Printed field | Mobile/API field | Representation |
| --- | --- | --- |
| Substation | `header.substation` | Editable text, initially from selected feeder |
| Feeder; Feeder Code | `feeder_id`, cached feeder name/code | Select an assigned feeder; keep its identity visible |
| Transformer Code | `transformer_code`, optional `transformer_id` | Editable code, optionally linked to an imported transformer |
| T/F Capacity; T/F Make | `header.capacity_kva`, `header.transformer_make` | Numeric capacity; make text |
| S.Pole; D.Pole; Pad | `header.mounting` | Printed choices, with blank allowed in a draft |
| General Duty; Dedicated | `header.duty` | Printed choices, with blank allowed in a draft |
| Survey Date | `survey_date` | Full calendar date |
| Inspectors; Location | `header.inspectors`, `header.location` | Text; multiple inspector names can be entered |
| Division; Sub-Division; Sub Division Code | `header.division`, `header.sub_division`, `header.sub_division_code` | Editable text, initially from selected feeder |
| S/E | `rows[].se` | Preserve printed S/E codes |
| Group | `rows[].group` | Text identifier, preserving leading zeros |
| Date: d d m m y y | `rows[].date` | Full calendar date, separate from header survey date |
| GPS WP | `rows[].gps_waypoint` | Waypoint identifier as text; preserve leading zeros |
| Phase | `rows[].phase` | Editable text |
| Conductor: R, Y, B, Neutral | `rows[].conductor_r`, `conductor_y`, `conductor_b`, `conductor_neutral` | Separate editable codes or conductor descriptions |
| Equipment Type: Type, Pole Class, Pole Height | `rows[].equipment_type`, `pole_class`, `pole_height_ft` | Type text; pole class code; numeric height |
| Consumer Type: RS, RL, SC, LC, SI, LI, PB, AG, ST | `rows[].consumers` | Optional separate counts for each printed code |
| Int | `rows[].intersection` | Separate text field; interpretation remains uncertain, see below |
| PV Solar/Net-Metering Data: Consumer Reference No. | `solar[].consumer_reference` | Text identifier, preserving leading zeros |
| Installed PV Capacity (kW); Remarks | `solar[].installed_pv_kw`, `solar[].remarks` | Numeric kW; text remarks |

Phone GPS latitude, longitude, accuracy, row remarks, overall remarks, and photo/sketch attachments are digital additions. They are not separate printed columns. A GPS fix does not replace the paper's waypoint identifier. The paper has a solar/net-metering section but does not separately identify a meter status or export-meter number.

## Printed legends

- Conductor codes: `A` = Ant, `W` = Wasp, `GN` = Gnat. The footer also lists `2/0 AWG`, `PVC 7/0.052`, `PVC 19/0.052`, `PVC 19/0.083`, USAID cable sizes `50mm` and `95mm`, `Ang` = Angle, and `Int` = Intersection. A further scanned PVC entry appears as `PVC/37.083`; its intended notation is unclear. Keep an editable Other value rather than silently correcting it.
- Pole class: `S` = Steel Structure, `PCO` = PC Ordinary, `PCS` = PC Spun, `RS` = Rail Steel, `TS` = Tubular Steel, `WB` = Wall Bracket.
- Consumer codes: `RS` = 1-phase residential, `RL` = 3-phase residential, `SC` = 1-phase commercial, `SI` = 1-phase industrial, `LI` = 3-phase industrial, `PB` = Public Building, `AG` = Agricultural, `ST` = Street Light. The table also has `LC`, but the scanned footer does not explicitly expand it. Three-phase commercial is a possible unconfirmed reading; the app labels it Commercial (LC).
- Printed height examples: Steel Structure `58, 45, 40, 36, 31`; PC Spun `45, 40, 36, 31`; PC Ordinary `36, 31`. These are examples, not a complete permitted range.

## Assumptions and unresolved meanings

1. **S/E:** Start/End is a possible reading, but the scanned sheet does not provide an explicit expansion. The app displays and stores S/E exactly, permitting repeated waypoints and incomplete pairs.
2. **Group:** the purpose and assignment rules are not defined in the printed legend. Do not infer it from waypoint, team, feeder, or row number.
3. **Equipment Type:** the printed row heading is simply Type; its allowed values are not defined. Keep free text independently from mounting and pole class.
4. **Int:** the table places it after ST under Consumer Type, whereas the conductor legend expands Int as Intersection. Preserve it as its own text field. Its final field meaning should be confirmed by the survey lead; do not include it in consumer totals.
5. **Units:** the paper does not state units beside T/F Capacity or Pole Height. The API assumes capacity in kVA and height in feet. Its USAID options use mm2 as a conventional conductor-area interpretation of the printed `50mm`/`95mm`. These are application conventions, not explicitly printed facts.
6. **Identifiers and dates:** the form's boxed digits are formatting/examples, not fixed group, waypoint, or year values. Use the entered full year, not the sample year.
7. **Repeated S/E rows:** each row is a separate observation. Do not sum both ends into feeder progress or deduplicate a waypoint automatically. Detailed survey collection remains separate from the existing monitoring progress approvals.

## Practical phone entry order

1. Download assigned team, feeder, and transformer references while online.
2. Select team and feeder; select or enter transformer code. Check the prefilled division and substation details, then enter date, inspector names, capacity, make, mounting, duty, and location.
3. Add one row at a time: S/E, group, row date, waypoint, optional phone GPS, phase, four conductor fields, equipment details, consumer counts, and Int. Carry-forward values must remain editable; do not store handwritten ditto marks as copied values.
4. Add solar consumer references, installed PV capacities, and remarks where available. Attach photographs/sketches if useful.
5. Save locally as a draft at any stage. Queue a complete form for syncing, and retain it until the server acknowledges the revision and each attachment.

## Application validation choices

These rules belong to the implementation and API contract; they are not requirements quoted from the paper.

- A local/server draft requires team, assigned feeder, and header date. Other paper fields can remain blank.
- Submission requires transformer code, positive capacity, inspector names, and at least one row. Every submitted row requires S/E, a valid date, and a waypoint identifier.
- Consumer counts are whole numbers greater than or equal to zero. A blank count means not recorded, not automatically zero. Solar capacity and pole height are nonnegative when supplied.
- Latitude must be between -90 and 90; longitude between -180 and 180; GPS accuracy is nonnegative. A GPS fix, solar record, and attachment are optional.
- Preserve leading zeros in codes. Keep unknown conductor/equipment values editable. Do not enforce undocumented S/E pairing, group numbering, or height-list restrictions.
- On a failed sync retain the local form; on a revision conflict require review rather than replacing another saved revision silently.

See [API_CONTRACT.md](API_CONTRACT.md) for endpoint limits, revision handling, assignment access, and offline storage behavior.
