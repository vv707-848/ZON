"""Writes the market map's geometry: the 13 administrative regions of Saudi Arabia as SVG paths.

Source: Natural Earth, «Admin 1 – States, Provinces» 1:10m (public domain: naturalearthdata.com/about/terms-of-use).
The file is 40 MB, so it is not in the repository; download it, then run:

    curl -sSLo /tmp/ne_admin1.geojson \
      https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_10m_admin_1_states_provinces.geojson
    python3 _tools/saudi_regions.py /tmp/ne_admin1.geojson

Output: backend/resources/geo/saudi-regions.json — for each region by its ISO 3166-2 code (App\\Enums\\Region), its
outline as an SVG path and a point inside it for a label, in a 1000-wide equirectangular frame (cos 24° keeps the
country's proportions). Outlines are simplified (Douglas–Peucker, 1 unit) and islands under 4 square units dropped:
the map compares regions, it is not a survey.
"""

import json
import math
import sys
from pathlib import Path
from typing import Any

OUT = Path(__file__).resolve().parents[1] / "backend" / "resources" / "geo" / "saudi-regions.json"
WIDTH = 1000.0
TOLERANCE = 1.0  # map units (1/1000 of the width)
MIN_AREA = 4.0  # square map units: smaller islands are not drawn
LAT0 = math.radians(24.0)

Point = tuple[float, float]


def rings(geometry: dict[str, Any]) -> list[list[list[float]]]:
    polygons = geometry["coordinates"] if geometry["type"] == "MultiPolygon" else [geometry["coordinates"]]
    return [polygon[0] for polygon in polygons]  # outer rings: the regions have no holes worth drawing


def area(ring: list[Point]) -> float:
    return abs(sum(x1 * y2 - x2 * y1 for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1], strict=True))) / 2


def centroid(ring: list[Point]) -> Point:
    signed = sum(x1 * y2 - x2 * y1 for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1], strict=True)) / 2
    cx = sum((x1 + x2) * (x1 * y2 - x2 * y1) for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1], strict=True))
    cy = sum((y1 + y2) * (x1 * y2 - x2 * y1) for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1], strict=True))
    return cx / (6 * signed), cy / (6 * signed)


def simplify(points: list[Point], tolerance: float) -> list[Point]:
    """Douglas–Peucker, iterative (a long coastline would overflow a recursive one)."""
    keep = [False] * len(points)
    keep[0] = keep[-1] = True
    stack = [(0, len(points) - 1)]
    while stack:
        first, last = stack.pop()
        (x1, y1), (x2, y2) = points[first], points[last]
        length = math.hypot(x2 - x1, y2 - y1)
        far, distance = first, 0.0
        for i in range(first + 1, last):
            x, y = points[i]
            # from the segment; from its one point when it closes a ring (first == last)
            d = abs((x2 - x1) * (y1 - y) - (x1 - x) * (y2 - y1)) / length if length else math.hypot(x - x1, y - y1)
            if d > distance:
                far, distance = i, d
        if distance > tolerance:
            keep[far] = True
            stack += [(first, far), (far, last)]
    return [p for p, kept in zip(points, keep, strict=True) if kept]


def main(source: str) -> None:
    everything = json.loads(Path(source).read_text(encoding="utf-8"))["features"]
    features = [f for f in everything if f["properties"].get("adm0_a3") == "SAU"]
    lon_lat = [(lon, lat) for f in features for ring in rings(f["geometry"]) for lon, lat in ring]
    west, north = min(p[0] for p in lon_lat), max(p[1] for p in lon_lat)
    east, south = max(p[0] for p in lon_lat), min(p[1] for p in lon_lat)
    scale = WIDTH / ((east - west) * math.cos(LAT0))

    def project(lon: float, lat: float) -> Point:
        return (lon - west) * math.cos(LAT0) * scale, (north - lat) * scale

    regions: dict[str, Any] = {}
    for feature in sorted(features, key=lambda f: f["properties"]["iso_3166_2"]):
        drawn = []
        for ring in rings(feature["geometry"]):
            points = simplify([project(lon, lat) for lon, lat in ring], TOLERANCE)
            if len(points) >= 4 and area(points) >= MIN_AREA:
                drawn.append(points)
        largest = max(drawn, key=area)
        label = centroid(largest)
        path = " ".join("M" + " L".join(f"{x:.1f},{y:.1f}" for x, y in ring[:-1]) + " Z" for ring in drawn)
        regions[feature["properties"]["iso_3166_2"]] = {"d": path, "label": [round(label[0], 1), round(label[1], 1)]}

    height = round((north - south) * scale, 1)
    out = {
        "source": "Natural Earth 1:10m Admin 1 (public domain), simplified by _tools/saudi_regions.py",
        "viewBox": [0, 0, WIDTH, height],
        "regions": regions,
    }
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(out, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    print(f"{len(regions)} regions, {OUT.stat().st_size // 1024} KB → {OUT}")


if __name__ == "__main__":
    main(sys.argv[1])
