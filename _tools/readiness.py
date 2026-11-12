"""Readiness counts for docs/handover.md, measured from the code, not written by hand.

    cd backend
    php artisan route:list --json > /tmp/routes.json
    ZON_ROUTE_LOG=/tmp/hits.txt php artisan test
    cd .. && python3 -I _tools/readiness.py /tmp/routes.json /tmp/hits.txt app/lib

Prints, for the API, the website and the panel, how many routes the backend's tests reach; and for the API, which
routes the app calls (its string paths in the code that talks to the server). A route reached by a test is not proof
that every case of it is tested; one the tests never reach has no test at all.
"""

import json
import pathlib
import re
import sys


def app_calls(lib: pathlib.Path) -> set[tuple[str, str]]:
    calls: list[tuple[str, str]] = []
    for f in lib.rglob("*.dart"):
        t = f.read_text(encoding="utf-8")
        calls += re.findall(r"\.(get|post|put|patch|delete)\(\s*'([^']*)'", t)
        calls += [("get", p) for p in re.findall(r"_all\('([^']*)'\)", t)]
        # a detail path chosen by a condition: '${project ? 'projects' : 'listings'}/${rawId(appId)}'
        for a, b in re.findall(r"'\$\{\w+ \? '(\w+)' : '(\w+)'\}/\$\{[^}]+\}'", t):
            calls += [("get", a + "/{}"), ("get", b + "/{}")]
    return {(m.upper(), re.sub(r"\$\{[^}]+\}|\$\w+", "{}", p)) for m, p in calls}


def main(routes_file: str, hits_file: str, lib: str) -> None:
    routes = json.loads(pathlib.Path(routes_file).read_text(encoding="utf-8"))
    hits = {line.strip() for line in pathlib.Path(hits_file).read_text(encoding="utf-8").splitlines()}
    called = app_calls(pathlib.Path(lib))

    def part(uri: str) -> str:
        if uri.startswith("api/v1"):
            return "api"
        if uri.startswith(("admin", "livewire", "filament")):
            return "panel"
        return "other" if uri.startswith(("sanctum", "up", "storage", "_", "broadcasting")) else "site"

    groups: dict[str, list[dict[str, str]]] = {"api": [], "site": [], "panel": [], "other": []}
    for r in routes:
        groups[part(r["uri"])].append(r)
    for name in ("api", "site", "panel"):
        rows = groups[name]
        tested = [r for r in rows if r["method"].replace("|", "|") + " " + r["uri"] in hits]
        print(f"{name}: tests reach {len(tested)} / {len(rows)}")
    api = groups["api"]
    wired, untested = [], []
    for r in api:
        path = re.sub(r"\{[^}]+\}", "{}", r["uri"].removeprefix("api/v1/"))
        methods = [m for m in r["method"].split("|") if m != "HEAD"]
        if any((m, path) in called for m in methods):
            wired.append(r)
        if r["method"] + " " + r["uri"] not in hits:
            untested.append(f"{'|'.join(methods)} {r['uri'].removeprefix('api/v1/')}")
    print(f"api: the app calls {len(wired)} / {len(api)}")
    print("api routes no test reaches:", ", ".join(sorted(untested)))


if __name__ == "__main__":
    main(*sys.argv[1:4])
