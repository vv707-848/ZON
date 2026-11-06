"""Line coverage from a clover file (PHPUnit), a Cobertura file (pytest-cov) or an lcov file (flutter test
--coverage): the total, the folders under it, and the floor the total must not fall under. The same table goes to
the run's summary on GitHub.

    python3 -I .github/scripts/coverage.py <file> <floor %> <title>

The floor sits a little under the measured total (docs/rules.md «الفحص الآلي»): new code comes with its tests, and
raising the floor after a gain keeps it.
"""

import collections
import os
import sys
import xml.etree.ElementTree as ET


def files(path: str) -> list[tuple[str, int, int]]:
    """(file, lines, covered lines) per source file."""
    if path.endswith(".info"):
        out, name, found = [], "", 0
        for line in open(path, encoding="utf-8"):
            if line.startswith("SF:"):
                name = line[3:].strip()
            elif line.startswith("LF:"):
                found = int(line[3:])
            elif line.startswith("LH:"):
                out.append((name, found, int(line[3:])))
        return out
    root = ET.parse(path).getroot()
    if root.tag == "coverage" and root.find("project") is not None:  # clover
        return [(f.get("name", ""), int(f.find("metrics").get("statements")), int(f.find("metrics").get("coveredstatements")))
                for f in root.iter("file")]
    source = (root.findtext("sources/source") or "").rstrip("/")
    out = []
    for c in root.iter("class"):  # Cobertura
        lines = c.findall("lines/line")
        out.append((f"{source}/{c.get('filename')}", len(lines), sum(1 for x in lines if int(x.get("hits", "0")) > 0)))
    return out


def folder(name: str) -> str:
    parts = name.replace("\\", "/").split("/")
    for anchor in ("app", "lib", "zon_agents"):
        if anchor in parts:
            i = len(parts) - 1 - parts[::-1].index(anchor)
            return "/".join(parts[i:i + 3]) if len(parts) - i > 3 else "/".join(parts[i:-1])
    return "/".join(parts[-3:-1])


def main() -> int:
    path, floor, title = sys.argv[1], float(sys.argv[2]), sys.argv[3]
    by_folder: dict[str, list[int]] = collections.defaultdict(lambda: [0, 0])
    for name, lines, covered in files(path):
        by_folder[folder(name)][0] += lines
        by_folder[folder(name)][1] += covered
    total = sum(v[0] for v in by_folder.values())
    covered = sum(v[1] for v in by_folder.values())
    percent = 100 * covered / total if total else 0.0

    rows = sorted(((100 * c / n, n, f) for f, (n, c) in by_folder.items() if n >= 40), key=lambda r: r[0])[:10]
    table = "\n".join(f"| {f} | {n} | {p:.1f}% |" for p, n, f in rows)
    report = f"**{title}: {percent:.1f}% of {total} lines** (floor {floor:.0f}%)\n\nThe ten weakest folders:\n\n| folder | lines | covered |\n|---|---|---|\n{table}\n"
    print(report)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as out:
            out.write(f"### Coverage\n\n{report}\n")
    if percent < floor:
        print(f"{title}: {percent:.1f}% is under the floor of {floor:.0f}%", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
