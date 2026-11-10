# Optional consumer service cables

Super Admin enables **Allow consumer service-cable entry (PDF)** on a Data Entry Operator's user account. The same setting is available under legacy Users. It defaults to off. Revoking it blocks the operator's service-cable save/delete endpoints and removes meter records from their entry response. Ordinary batch access and workflow locks also apply.

In PDF Data Entry, select a saved transformer, then enable **Meter/service-cable waypoints available for this transformer** beneath its saved rows. Existing transformers and future transformers start with this option off.

Save the S/E pair first, with the consumer totals at E. Add each meter under that E pole. Record the consumer category and its number within that category (RS 1, RS 2, etc.), the meter's actual two-digit group, DDMMYY date and three-digit waypoint, its GPX source and exact point, and its PDF reference. Consumer/reference number, meter number, phase, cable library code, measured length/evidence and remarks are optional.

For example, E pole `025` may connect to meter `026`, group `02`, date `081026`. The meter's textual identity is `02081026026`. The next waypoint is never assigned automatically. Coordinates come from the selected GPX point.

Meter records describe consumers already counted at E. Five RS consumers plus three meter records still means five RS consumers, with two lacking recorded meter endpoints. Duplicate consumer slots/endpoints, counts above the E total and incompatible recorded phases are rejected. Remove linked meter records before deleting their E pole or disabling the feature. Reducing parent counts cannot invalidate saved meter connections.

Changes invalidate approval and require review again. Verifiers see the cable fields and GPX coordinates with the E row's checks. To correct a meter record, return the survey to entry; an authorized operator or Super Admin edits it.

SynerGEE generation uses actual meter coordinates for recorded connections and retains established internal node/section identifiers. Existing phase model legs for a three-phase customer share that customer's recorded meter position. Consumer totals and demand are not added again. A measured length uses its recorded evidence; otherwise the projected straight endpoint distance is explicitly identified as an estimate in export provenance. Recorded cable library codes must exist in the approved template. Connections without meter records keep their existing generated endpoints and length formula.

The legacy Data Entry MDB retains its existing S/E schema and row counts. It has no columns for these child records; its export warns about this limitation. The approved application snapshot and Transformer JSON preserve the meter records; SynerGEE exports include endpoint and length provenance. Old snapshots and exports with no service-cable records retain their existing representation.

For another installation, apply the additive migration with `php artisan migrate`. This feature does not publish changes to a hosted installation.
