# Reviewed export mapping v1

New operator rows use GGDDMMYYWWW as survey identity while retaining the short GPX name and selected source separately. Frozen sections keep the GPX waypoint IDs plus complete endpoint identities in `original_entry`. Validation/export overlays stable survey node keys, so GPS `104` from 2022 and `104` from 2026 do not merge. Legacy sections without complete identities keep their original mapping. Existing `wp:<GPX ID>` source wiring resolves to the root's unique survey identity; when several identities refer to that root, select `header.source_survey_identifier` explicitly before approval. Conflicting coordinates for one complete identity block export.

Project settings `entry.two_digit_year_start` define a 100-year paste window, and `entry.pole_height_unit` specifies the verified form unit. The configuration screen provides labelled inputs for both. These are explicit project choices, not inferred electrical defaults. Unit information stays with each row and legacy/copy-source units are preserved.

An administrator enters project settings and equipment references; the verifier approves their engineering meaning. The configuration approval identifies the actual reviewer. Values shown below as strings beginning `REPLACE_` are placeholders, not valid export data. Empty dictionaries do not authorize default engineering values: every omitted MDB field is explicitly written as NULL, suppressing schema defaults. Enter every field required by the target SynerGEE workflow in `table_values` or the selected network's explicit fields, including optional engineering values that SynerGEE requires for your use case.

Settings require `frequency_hz`, `nominal_voltage_kv`, `conductor_library_revision`, `transformer_library_revision`; the separate EPSG field must identify an approved projected CRS in metres. The source-node aliases and supply sections below are explicit modeling decisions. They must not be inferred from transformer capacity, sample wiring or sorted waypoints.

```json
{
  "frequency_hz": "REPLACE_APPROVED_NUMBER",
  "nominal_voltage_kv": "REPLACE_APPROVED_NUMBER",
  "conductor_library_revision": "REPLACE_REVIEWED_LIBRARY",
  "transformer_library_revision": "REPLACE_REVIEWED_LIBRARY",
  "mapping": {
    "version": "synergee-reviewed-v1",
    "reviewed_by": "REPLACE_REVIEWER_USER_ID",
    "reviewed_at": "REPLACE_UTC_DATE",
    "consolidated_supported": false,
    "table_values": {
      "SAI_Control": {
        "Frequency": "REPLACE_SAME_APPROVED_FREQUENCY",
        "LengthUnits": "Metric",
        "Product": "REPLACE_SAME_APPROVED_TEMPLATE_SYNERGEE_VERSION",
        "ProjectionFile": "REPLACE_APPROVED_CRS_NAME",
        "ProjectionWKT": "REPLACE_APPROVED_WKT_WITHIN_FIELD_LIMIT"
      },
      "Node": {},
      "InstSection": {"ConfigurationId": "REPLACE_APPROVED_CONFIGURATION_ID"},
      "InstFeeders": {
        "SubstationId": "REPLACE_VERIFIED_SUBSTATION",
        "NominalKvll": "REPLACE_SAME_APPROVED_NOMINAL_VOLTAGE",
        "BusVoltageLevel": "REPLACE_APPROVED_NUMBER",
        "ConnectionType": "REPLACE_APPROVED_CONNECTION"
      },
      "InstPrimaryTransformers": {},
      "Loads": {"IsSpotLoad": "REPLACE_APPROVED_MODE", "LocationToModelSpotLoads": "REPLACE_APPROVED_LOCATION_MODE"}
    },
    "networks": {
      "REPLACE_TRANSFORMER_CODE": {
        "source_nodes": [
          {"key": "feeder-source", "waypoint_id": "REPLACE_APPROVED_COORDINATE_WAYPOINT_ID"},
          {"key": "transformer-hv", "waypoint_id": "REPLACE_APPROVED_COORDINATE_WAYPOINT_ID"}
        ],
        "source_sections": [
          {
            "key": "supply",
            "from": "feeder-source",
            "to": "transformer-hv",
            "fields": {
              "SectionPhases": "REPLACE_PHASE_LETTERS",
              "PhaseConductorId": "REPLACE_R_LIBRARY_ID",
              "PhaseConductor2Id": "REPLACE_Y_LIBRARY_ID",
              "PhaseConductor3Id": "REPLACE_B_LIBRARY_ID",
              "NeutralConductorId": null,
              "ConfigurationId": "REPLACE_APPROVED_CONFIGURATION_ID",
              "SectionLength_MUL": "REPLACE_APPROVED_LENGTH_METRES"
            }
          },
          {
            "key": "transformer-link",
            "from": "transformer-hv",
            "to": "wp:REPLACE_TRANSFORMER_SOURCE_WAYPOINT_ID",
            "fields": {
              "SectionPhases": "REPLACE_PHASE_LETTERS",
              "PhaseConductorId": "REPLACE_R_LIBRARY_ID",
              "PhaseConductor2Id": "REPLACE_Y_LIBRARY_ID",
              "PhaseConductor3Id": "REPLACE_B_LIBRARY_ID",
              "NeutralConductorId": "REPLACE_NEUTRAL_LIBRARY_ID",
              "ConfigurationId": "REPLACE_APPROVED_CONFIGURATION_ID",
              "SectionLength_MUL": "REPLACE_APPROVED_LENGTH_METRES"
            }
          }
        ],
        "feeder_node": "feeder-source",
        "transformer_section": "transformer-link",
        "transformer_fields": {
          "TransformerType": "REPLACE_APPROVED_LIBRARY_TYPE",
          "ConnectedPhases": "REPLACE_APPROVED_PHASES",
          "SpecNomKv": "REPLACE_APPROVED_NUMBER",
          "NomKvMode": "REPLACE_APPROVED_MODE",
          "HighSideConnectionCode": "REPLACE_APPROVED_CONNECTION",
          "LowSideConnectionCode": "REPLACE_APPROVED_CONNECTION",
          "UseInstanceImpedance": "REPLACE_APPROVED_MODE"
        }
      }
    }
  }
}
```

`wp:<id>` references a frozen survey waypoint. Other endpoint keys refer to the explicitly declared aliases. An alias uses the approved coordinate of its declared waypoint, preserving provenance even for an intentionally colocated electrical source/HV node. Stable MDB IDs are derived from batch identities and saved record IDs, not labels or waypoint order. Source sections and surveyed sections are checked for duplicate/self connections, cycles and source connectivity. Use distinct source-node aliases for independent source locations. Enable consolidated export only after reviewing source aliases, equipment compatibility and feeder voltage settings; a shared source alias must have exactly matching coordinates/values.

`InstSection.SectionPhases` uses four positional slots in the observed SynerGEE schema: R, Y, B, N. Use a space in an absent phase's position and omit trailing spaces only. Examples: R/Y/B -> `RYB`; R/N -> `R  N`; R/B/N -> `R BN`; B/N -> `  BN`. Explicit source-section mappings must preserve these positions. Survey phase arrays are encoded into the same slots without changing their original entries. `PhaseConductorId`, `PhaseConductor2Id`, `PhaseConductor3Id` remain R, Y, B respectively; a blue-only load remains in Phase3 fields.

Template metadata:

```json
{
  "permitted_static_tables": [],
  "equipment_references": {
    "conductors": ["REPLACE_APPROVED_R_ID", "REPLACE_APPROVED_Y_ID", "REPLACE_APPROVED_B_ID", "REPLACE_APPROVED_NEUTRAL_ID"],
    "transformers": ["REPLACE_APPROVED_TRANSFORMER_TYPE"],
    "configurations": ["REPLACE_APPROVED_CONFIGURATION_ID"]
  }
}
```

This allowlist records library references approved against the actual target SynerGEE equipment library. It does not create impedance/conductor parameters or prove an external library is installed. Names must match exactly; abbreviations, leading/trailing spaces or inferred ratings are not silently normalized. Preserve the appropriate reviewed library with the deployment.

Keep category counts separate from demand. Enter the separate `load_assumptions` JSON with the approved documented method/version/evidence and use each consumer category's demand JSON to record the actual phase allocation and resulting values:

```json
{
  "method": "REPLACE_MEASURED_OR_APPROVED_ESTIMATION_METHOD",
  "evidence": "REPLACE_RETRIEVABLE_MEASUREMENT_OR_METHOD_REFERENCE",
  "approved_by": "REPLACE_REVIEWER_USER_ID",
  "phase_values": {
    "R": {"customers": "REPLACE_NUMBER", "kw": "REPLACE_NUMBER", "kvar": "REPLACE_NUMBER", "kva": "REPLACE_NUMBER"}
  }
}
```

Each connected load phase requires all four explicit numeric values. Customer allocations across phases must sum to the verified category count. For inactive phases, omitted values become database NULL; explicit reviewed zeros are also supported. The exporter does not split customer counts evenly, infer power factor, or derive kVA/kW/kvar from category counts. A manually confirmed zero-count category with no demand creates no Loads record and remains in the immutable revision. Unreadable/blank counts remain unresolved and cannot be exported. Section lengths use intermediate WGS84 geometry where supplied, otherwise are labeled endpoint estimates. Approved measured overrides require the verifier and reason.

Customers, kW and kVA must be finite and nonnegative. Reactive kvar may be finite and signed when supported by the approved measurement or estimation evidence; its sign is preserved. Missing values and sign conventions are never inferred.

Positive PV capacity blocks final export because this mapping version does not implement generator instances. A verifier may explicitly approve an analysis scope that excludes PV using `mapping.pv_exclusion = {"reason":"documented scope decision","reviewed_by":<configuration approving reviewer ID>,"reviewed_at":"UTC date"}`. Preserve PV details in the revision/payload and record the exclusion in engineering acceptance. Adding generator mapping requires a new reviewed mapping version and writer/readback table support.
