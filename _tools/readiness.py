"""Route counts behind docs/handover.md, measured from the code, not written by hand.

    cd backend
    php artisan route:list --json > /tmp/routes.json
    ZON_ROUTE_LOG=/tmp/hits.txt php artisan test
    cd .. && python3 -I _tools/readiness.py /tmp/routes.json /tmp/hits.txt app/lib

What it counts, and no more:

- **Routes a test reaches** (API, website, panel): the route matched during a test (Laravel's RouteMatched). A
  route reached is not every case of it tested, nor a request that passed its acceptance: the test may stop it at
  a refusal. A route no test reaches has no test at all.
- **API routes the app calls**: a call `.get('path')` (post, put, patch, delete) in the app's code, comments and
  string contents not read as code. Found in the code is not a call that runs, nor one a test checks: those are
  the acceptance tests named in docs/handover.md. The two routes the outside calls (the payment provider's and the
  stores' notices) are not the app's, and are not counted for it.

tests: _tools/tests/test_readiness.py
"""

import json
import pathlib
import re
import sys

# API routes that are not the app's to call: the outside's notices to the server.
NOT_THE_APPS = {"api/v1/payments/webhook/{provider}", "api/v1/store/notices/{store}"}


def dart_code(source: str) -> tuple[str, list[str]]:
    """[source] without its comments, each string literal replaced by its number between two NUL characters (its text is the n-th of the
    list returned, `${…}` and `$name` in it read `{}`): what is left is code only, and a string is read only where
    the code passes it."""
    out: list[str] = []
    strings: list[str] = []
    i, n = 0, len(source)
    while i < n:
        c = source[i]
        if source.startswith("//", i):
            while i < n and source[i] != "\n":
                i += 1
        elif source.startswith("/*", i):
            depth = 0
            while i < n:
                if source.startswith("/*", i):
                    depth, i = depth + 1, i + 2
                elif source.startswith("*/", i):
                    depth, i = depth - 1, i + 2
                    if depth == 0:
                        break
                else:
                    i += 1
        elif c in "'\"":
            raw = i > 0 and source[i - 1] == "r"
            quote = c * 3 if source.startswith(c * 3, i) else c
            i += len(quote)
            text: list[str] = []
            while i < n and not source.startswith(quote, i):
                if source[i] == "\\" and not raw:
                    text.append(source[i : i + 2])
                    i += 2
                elif source.startswith("${", i) and not raw:
                    depth, i = 1, i + 2
                    while i < n and depth:
                        depth += {"{": 1, "}": -1}.get(source[i], 0)
                        i += 1
                    text.append("{}")
                else:
                    text.append(source[i])
                    i += 1
            i += len(quote)
            strings.append(re.sub(r"\$\w+", "{}", "".join(text)))
            out.append(f"\x00{len(strings) - 1}\x00")
        else:
            out.append(c)
            i += 1
    return "".join(out), strings


def app_calls(lib: pathlib.Path) -> set[tuple[str, str]]:
    """Every (METHOD, path) the app's code calls the server with; a parameter is `{}`."""
    calls: set[tuple[str, str]] = set()
    for f in lib.rglob("*.dart"):
        code, strings = dart_code(f.read_text(encoding="utf-8"))
        for method, k in re.findall(r"\.(get|post|put|patch|delete)\(\s*\x00(\d+)\x00", code):
            calls.add((method.upper(), strings[int(k)]))
        for k in re.findall(r"\b_all\(\s*\x00(\d+)\x00\s*\)", code):   # every page of a list: its GET
            calls.add(("GET", strings[int(k)]))
    return calls


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
        reached = [r for r in rows if r["method"] + " " + r["uri"] in hits]
        print(f"{name}: routes a test reaches {len(reached)} / {len(rows)}")
    apps = [r for r in groups["api"] if r["uri"] not in NOT_THE_APPS]
    wired, unreached = [], []
    for r in apps:
        path = re.sub(r"\{[^}]+\}", "{}", r["uri"].removeprefix("api/v1/"))
        methods = [m for m in r["method"].split("|") if m != "HEAD"]
        if any((m, path) in called for m in methods):
            wired.append(r)
    for r in groups["api"]:
        if r["method"] + " " + r["uri"] not in hits:
            methods = [m for m in r["method"].split("|") if m != "HEAD"]
            unreached.append(f"{'|'.join(methods)} {r['uri'].removeprefix('api/v1/')}")
    print(f"api: routes the app's code calls {len(wired)} / {len(apps)} (the outside's {len(NOT_THE_APPS)} not counted)")
    print("api routes no test reaches:", ", ".join(sorted(unreached)))


if __name__ == "__main__":
    main(*sys.argv[1:4])
