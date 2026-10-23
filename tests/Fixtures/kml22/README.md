Offline KML 2.2 schema fixtures used to validate actual exported KML in the regression tests.

- `ogckml22.xsd`: https://schemas.opengis.net/kml/2.2.0/ogckml22.xsd
- `atom-author-link.xsd`: https://schemas.opengis.net/kml/2.2.0/atom-author-link.xsd
- `xAL.xsd`: https://docs.oasis-open.org/election/external/xAL.xsd

Retrieved 21 October 2026. Original notices are retained in the files. The sole change to `ogckml22.xsd` is replacing the absolute HTTP xAL schema location with `xAL.xsd`, so test validation requires no network access.
