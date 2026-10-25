# Department inputs and model completeness

The downloadable form is `public/templates/Simple-Synergee-Inputs.xlsx`: one worksheet formatted to print on one A4 page. Regenerate it with `php scripts/mdb/create-department-proforma.php`; this also refreshes the previous download filename for compatibility. The Engineering Configuration load-demand section links to it. Yellow cells are blank department inputs. A separate grey Reference value column shows the observed 3 kVA/customer ratio and exact `100 KVA` reference ID; the power factor and category-specific rates are marked as not established. Reference observations are not department-approved defaults.

For the current 100 kVA transformer, the short form asks only for kVA per consumer, lagging power factor, the exact transformer library type ID, and department confirmation. A single common demand rate and power factor can cover all categories if the department confirms that policy. The category table is only needed when rates differ. An N/A response is documentary information, not a numeric rate to import; applicability must be resolved against the actual survey before entering numeric application settings.

## Department response

Obtain the diversified kVA per consumer and lagging power factor for RS, RL, SC, LC, SI, LI, PB, AG and ST, or a department-confirmed common rate and power factor. Confirm the application's integer customer balancing policy for automatic category rates. Measured per-phase demand is an alternative, collected separately when required. Leading reactive power requires explicit measured/mapped signed kvar; category rates currently calculate lagging kvar.

Obtain the exact transformer capacity-to-library mapping; the short form focuses on the current 100 kVA transformer. The existing line configuration and source settings are outside this shortened form. Reference labels such as `100 KVA` and `12.5/7.2 kV cross arm C2-2` do not independently prove that the installed equipment definitions match this project.

## Enter and confirm in Simple mode

1. Keep Simple MDB Generation ON and select the clean system schema.
2. Enter the returned line configuration ID and transformer mappings in Engineering Configuration. Example JSON syntax only: `{"100":"department-confirmed-exact-type-ID"}`.
3. Choose category rates and enter the returned kVA rates, power factors and demand-method evidence, or use reviewed measured phase demand.
4. Select the checkbox confirming the entered model settings against the department reference, enter the reason/document reference and save Simple mode settings. This records a limited review of the model settings while leaving full engineering approval unset. The category-rate method requires all nine rates and power factors; missing inputs are rejected instead of guessed.
5. Reverify and approve affected surveys and download fresh SynerGEE JSON. Opening the form does not approve values. Saving another draft without confirmation clears the limited review.

An active built-in schema remains selectable in the Simple-mode template dropdown even though it has no full equipment-library approval. This prevents the selection from disappearing when saving the form.

## Coordinate and construction fixes

WGS84 UTM CRSs EPSG 32601–32660 and 32701–32760 use the native sixth-order Kruger projection in PHP. This removes the Python dependency for the configured EPSG 32643. Longitude zone, hemisphere and latitude limits are checked. Other supported projected CRSs continue to use the Python/pyproj adapter. The mathematical reference is [PROJ's Transverse Mercator formulation](https://proj.org/en/stable/operations/projections/tmerc.html); the native tests compare known coordinate and length fixtures, including both hemispheres.

The logical source terminal is placed at the surveyed transformer point, without inventing a physical offset. Its model link has explicit zero length and uses the configured transformer connected phases when available. Survey section lengths retain the existing geometry, elevation, measured-override and optional sag-allowance rules. Export length implementation is versioned as `gpx-elevation-sag-allowance-v2`, so server exports do not reuse an earlier artifact with missing coordinates.

Survey-specific configuration IDs are retained. A missing survey ID falls back to the entered central configuration ID, then the frozen mapping's ID; NULL survey values no longer erase that mapping. Transformer types come from entered mappings. Missing types or demand are never fabricated from a different transformer's data.

## Preview and acceptance

The JSON-to-SynerGEE preview shows populated/missing counts for map coordinates, lengths, transformer type, configuration IDs, section phases and active-phase demand/customer allocation. An incomplete Simple-mode model remains clearly marked as a draft. This preserves Simple generation's optional-input behavior without presenting a structural MDB readback as engineering acceptance.

Native SynerGEE opening, installed-library resolution, connectivity checks and load-flow acceptance are separate. A completed preview is not certification of the electrical model.

## Local sample verification

Using the existing approved r79 JSON unchanged, the repaired exporter wrote and reopened a new separate MDB through Access DAO. It populated 14/14 node coordinate pairs, 13/13 section lengths and 13/13 configuration IDs. Its one transformer type and ten load-demand records remain incomplete until department data is supplied. The original downloaded MDB and JSON were preserved. The separate regenerated sample is a draft, not an engineering-approved model.
