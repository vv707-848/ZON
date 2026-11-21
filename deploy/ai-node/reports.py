"""Reports out of a lab Pod, before it goes (owner, 21 Nov 2026): every stage's JSON report, kept on GitHub where
whoever ran the measurement reads it — the Pod's own disk is deleted with it.

    python3 reports.py open <title>              # a new issue for this run's reports: prints its number
    python3 reports.py put <issue> <file>...     # each file gzipped, in base64, in comments of 60,000 letters
    python3 reports.py get <issue> <dir>         # every report back, each checked against its sha256

GH_TOKEN (Issues: read and write) and, for tests, ZON_GITHUB_API. A comment of a report reads
«report `<name>` · part <i>/<n> · sha256 <hex>» then its base64; `get` puts the parts together and refuses a report
whose sha256 is not the one written.
"""

import base64
import gzip
import hashlib
import json
import os
import re
import sys
import urllib.request
from pathlib import Path

API = os.environ.get("ZON_GITHUB_API", "https://api.github.com")
REPO = "vv707-848/ZON"
PART = 60_000
HEAD = re.compile(r"^report `(?P<name>[^`]+)` · part (?P<i>\d+)/(?P<n>\d+) · sha256 (?P<sha>[0-9a-f]{64})\n")


def _call(method: str, path: str, body: dict | None = None) -> object:
    request = urllib.request.Request(
        f"{API}/repos/{REPO}{path}",
        data=None if body is None else json.dumps(body).encode(),
        method=method,
        headers={"Authorization": f"Bearer {os.environ['GH_TOKEN']}", "Accept": "application/vnd.github+json"},
    )
    with urllib.request.urlopen(request, timeout=60) as answer:
        return json.load(answer)


def open_issue(title: str) -> int:
    found = _call("POST", "/issues", {"title": title, "body": "Reports of one lab Pod run (deploy/ai-node/reports.py)."})
    return int(found["number"])  # type: ignore[index]


def put(issue: int, files: list[str]) -> None:
    for name in files:
        path = Path(name)
        if not path.is_file():
            print(f"missing: {name}", file=sys.stderr)
            continue
        packed = gzip.compress(path.read_bytes(), mtime=0)
        digest = hashlib.sha256(packed).hexdigest()
        text = base64.b64encode(packed).decode()
        parts = [text[k : k + PART] for k in range(0, len(text), PART)] or [""]
        for i, part in enumerate(parts, 1):
            _call("POST", f"/issues/{issue}/comments", {"body": f"report `{path.name}` · part {i}/{len(parts)} · sha256 {digest}\n{part}"})
        print(f"posted {path.name}: {len(parts)} part(s), sha256 {digest[:12]}")


def get(issue: int, out: str) -> list[str]:
    pieces: dict[str, dict[str, object]] = {}
    page = 1
    while True:
        comments = _call("GET", f"/issues/{issue}/comments?per_page=100&page={page}")
        assert isinstance(comments, list)
        for comment in comments:
            head = HEAD.match(comment["body"])
            if head:
                entry = pieces.setdefault(head["name"], {"n": int(head["n"]), "sha": head["sha"], "parts": {}})
                entry["parts"][int(head["i"])] = comment["body"][head.end() :]  # type: ignore[index]
        if len(comments) < 100:
            break
        page += 1
    Path(out).mkdir(parents=True, exist_ok=True)
    read = []
    for name, entry in pieces.items():
        parts = entry["parts"]
        assert isinstance(parts, dict)
        if sorted(parts) != list(range(1, int(entry["n"]) + 1)):  # type: ignore[arg-type]
            raise SystemExit(f"{name}: parts missing")
        packed = base64.b64decode("".join(parts[i] for i in sorted(parts)))
        if hashlib.sha256(packed).hexdigest() != entry["sha"]:
            raise SystemExit(f"{name}: sha256 does not match")
        (Path(out) / name).write_bytes(gzip.decompress(packed))
        read.append(name)
    return sorted(read)


if __name__ == "__main__":
    command, *rest = sys.argv[1:]
    if command == "open":
        print(open_issue(" ".join(rest)))
    elif command == "put":
        put(int(rest[0]), rest[1:])
    elif command == "get":
        print("\n".join(get(int(rest[0]), rest[1])))
    else:
        raise SystemExit(__doc__)
