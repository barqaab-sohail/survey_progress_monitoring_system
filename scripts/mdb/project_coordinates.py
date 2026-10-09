"""Stdin-only WGS84 projection adapter. Install pyproj; never accepts file paths."""
import json
import math
import sys

from pyproj import CRS, Transformer


def project(payload):
    epsg = payload.get("epsg")
    if not isinstance(epsg, int) or epsg < 1 or epsg > 999999:
        raise ValueError("An explicit valid EPSG code is required")
    crs = CRS.from_epsg(epsg)
    if not crs.is_projected or any(axis.unit_conversion_factor != 1 for axis in crs.axis_info[:2]):
        raise ValueError("Target CRS must be projected with metre units")
    transformer = Transformer.from_crs(CRS.from_epsg(4326), crs, always_xy=True)
    points = []
    for point in payload["points"]:
        lat = float(point.get("latitude", point.get("lat")))
        lon = float(point.get("longitude", point.get("lon")))
        if not math.isfinite(lat) or not math.isfinite(lon) or not -90 <= lat <= 90 or not -180 <= lon <= 180:
            raise ValueError("Coordinates must be valid WGS84 latitude and longitude")
        area = crs.area_of_use
        if area and not (area.west <= lon <= area.east and area.south <= lat <= area.north):
            raise ValueError("Coordinate lies outside the configured CRS area of use")
        x, y = transformer.transform(lon, lat, errcheck=True)
        if not math.isfinite(x) or not math.isfinite(y):
            raise ValueError("Projection returned non-finite coordinates")
        points.append({"x": x, "y": y})
    return {"epsg": epsg, "points": points}


if __name__ == "__main__":
    try:
        print(json.dumps(project(json.load(sys.stdin)), allow_nan=False))
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
