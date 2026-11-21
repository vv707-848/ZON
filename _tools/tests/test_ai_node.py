"""The GPU node's scripts on a Pod with no one at the terminal (docs/deploy-runbook.md 2.2–2.3).

What a mistake there costs is a bill: a start command that ends is started again by RunPod, so every way out of
lab-pod.sh must report and stop the Pod; the CPU Pod's command must reach bash with its `$p` and `$RUNPOD_POD_ID`
whole, however RunPod passes it; gpu-check.sh refuses a driver below CUDA 12.8. Run with stand-ins for curl, runpodctl,
nvidia-smi and the engine; a local server stands in for GitHub.
"""

import http.server
import json
import os
import pathlib
import re
import shlex
import shutil
import subprocess
import tempfile
import threading
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
NODE = ROOT / "deploy" / "ai-node"


def write(path: pathlib.Path, text: str, executable: bool = False) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8")
    if executable:
        path.chmod(0o755)


RUNPODCTL = """#!/bin/sh
echo "$*" >> "$TEST_DIR/stops"
[ "$1" = pod ] && [ -n "${NEW_SPELLING_FAILS:-}" ] && exit 1
[ "$2" = remove ] || [ "$1" = remove ] && [ -n "${REMOVE_FAILS:-}" ] && exit 1
exit 0
"""


class GitHub(http.server.BaseHTTPRequestHandler):
    """GitHub's comments, and the engine's /v1/chat/completions for the smoke request (one local server)."""

    comments: list[dict] = []
    started: list[dict] = []   # «started at `<sha>`»: one per run, kept apart so the stage comments keep their places
    reports: list[dict] = []   # reports.py put: «report `<name>` · part i/n · sha256 …»
    issues: list[dict] = []    # reports.py open
    asked: list[dict] = []
    status = 201
    answer = "شقة من ثلاث غرف في حي النرجس، واسعة ومشمسة."

    def do_POST(self) -> None:  # noqa: N802
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        if self.path == "/v1/chat/completions":
            GitHub.asked.append({"auth": self.headers["Authorization"], **body})
            reply = {"choices": [{"message": {"content": GitHub.answer}}], "usage": {"completion_tokens": 24}}
            self.send_response(200)
            self.end_headers()
            self.wfile.write(json.dumps(reply).encode())
            return
        if self.path.endswith("/issues"):
            GitHub.issues.append(body)
            self.send_response(201)
            self.end_headers()
            self.wfile.write(json.dumps({"number": 99}).encode())
            return
        text = body["body"]
        into = GitHub.reports if text.startswith("report `") else GitHub.started if "— started at `" in text else GitHub.comments
        into.append({"path": self.path, "body": text})
        self.send_response(GitHub.status)
        self.end_headers()
        self.wfile.write(b"{}")

    def do_GET(self) -> None:  # noqa: N802
        page = int(re.search(r"[?&]page=(\d+)", self.path)[1]) if "&page=" in self.path or "?page=" in self.path else 1
        own = [r for r in GitHub.reports if r["path"] == self.path.split("?")[0]][(page - 1) * 100 : page * 100]
        self.send_response(200)
        self.end_headers()
        self.wfile.write(json.dumps([{"body": r["body"]} for r in own]).encode())

    def log_message(self, *_: object) -> None:
        pass


class LabPodTest(unittest.TestCase):
    """deploy/ai-node/lab-pod.sh: done or failed at any step, it reports and stops — and never just ends."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), GitHub)
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.server.shutdown()

    def setUp(self) -> None:
        GitHub.comments, GitHub.asked, GitHub.status = [], [], 201
        GitHub.started, GitHub.reports, GitHub.issues = [], [], []
        GitHub.answer = "شقة من ثلاث غرف في حي النرجس، واسعة ومشمسة."
        self.dir = pathlib.Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.dir, True)
        w, bin_ = self.dir / "w", self.dir / "bin"
        # curl: a download (-o) gets the fixture prepare.sh; the engine's /v1/models answers once serve.py is up
        write(bin_ / "curl", """#!/bin/sh
out=""; url=""
while [ $# -gt 0 ]; do case "$1" in -o) out="$2"; shift ;; -H|--max-time|--retry) shift ;; -*) ;; *) url="$1" ;; esac; shift; done
if [ -n "$out" ]; then
  [ -n "${DOWNLOAD_FAILS:-}" ] && exit 22
  echo "$url" >> "$TEST_DIR/downloads"; cp "$TEST_DIR/prepare.sh" "$out"; exit 0
fi
case "$url" in */v1/models) [ -f "$TEST_DIR/ready" ] && echo '{"data":[{"id":"zon-ai"}]}' && exit 0 ;; esac
exit 7
""", True)
        write(bin_ / "runpodctl", RUNPODCTL, True)
        write(bin_ / "git", "#!/bin/sh\necho abc1234\n", True)
        write(self.dir / "prepare.sh", 'echo "prepared $ZON_PROFILE" >> "$TEST_DIR/prepared"\n')
        write(w / "serve.py", """import os, pathlib, sys, time
if os.environ.get("ENGINE_DIES"):
    print("engine: out of memory"); sys.exit(1)
if os.environ.get("ENGINE_HANGS"):
    time.sleep(60); sys.exit(0)
pathlib.Path(os.environ["TEST_DIR"], "ready").touch(); time.sleep(60)
""")
        write(w / "venv-tools" / "bin" / "uv", "#!/bin/sh\nexit 0\n", True)
        write(w / "zon" / "agents" / ".venv" / "bin" / "zon-agents",
              '#!/bin/sh\necho "$*" >> "$TEST_DIR/lab"\necho "| table of $1 $2 |"\n'
              'case "$*" in *--sample*) echo "overall 0.5 → warm" ;; esac\n'
              'if [ "$1 $2" = "lab run" ] && [ -n "${FAKE_REPORT:-}" ]; then prev=""; for a in "$@"; do\n'
              '  [ "$prev" = "--out" ] && out="$a"; prev="$a"; done\n'
              '  case "$*" in *--sample*) ;; *) cp "$FAKE_REPORT" "$out" ;; esac; fi\n'
              'if [ "$1 $2" = "lab market" ] && [ -n "${FAKE_MARKET:-}" ]; then prev=""; for a in "$@"; do\n'
              '  [ "$prev" = "--out" ] && out="$a"; prev="$a"; done; cp "$FAKE_MARKET" "$out"; fi\n', True)
        self.env = {
            "PATH": f"{bin_}:{os.environ['PATH']}", "HOME": str(self.dir), "TEST_DIR": str(self.dir),
            "ZON_WORKSPACE": str(w), "ZON_GITHUB_API": f"http://127.0.0.1:{self.server.server_port}",
            "ZON_RAW_URL": "https://raw.example/ZON", "ZON_REF": "0" * 40,
            "LLM_API_KEY": "test-key", "RUNPOD_POD_ID": "pod123", "GH_TOKEN": "test-token", "LAB_ISSUE": "7",
            "ZON_PROFILE": "qwen3.6-35b-a3b-escha-w2",
            "ZON_STOP_GRACE": "0", "ZON_KEEP_SECONDS": "0", "ZON_ENGINE_WAIT": "0.1", "ZON_ENGINE_TRIES": "100",
            "ZON_LLM_URL": f"http://127.0.0.1:{self.server.server_port}",
        }

    def run_lab(self, **env: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(["bash", str(NODE / "lab-pod.sh")], env={**self.env, **env},
                              capture_output=True, text=True, timeout=60)

    def stops(self) -> list[str]:
        path = self.dir / "stops"
        return path.read_text().splitlines() if path.exists() else []

    def test_a_missing_setting_is_posted_and_the_pod_still_goes(self) -> None:
        """A Pod without its key or its profile ended before the exit was in place: nothing posted, nothing stopped,
        and RunPod starts the command again (review of 21 Nov). And no profile is never the 27B by default."""
        for missing, says in (("LLM_API_KEY", "set LLM_API_KEY"), ("ZON_PROFILE", "set ZON_PROFILE")):
            with self.subTest(missing=missing):
                self.setUp()
                env = {k: v for k, v in self.env.items() if k != missing}
                result = subprocess.run(["bash", str(NODE / "lab-pod.sh")], env={**env, "DELETE_WHEN_DONE": "1"},
                                        capture_output=True, text=True, timeout=60)
                self.assertEqual(result.returncode, 1)
                self.assertIn("stopped at «settings»", GitHub.comments[0]["body"])
                self.assertIn(says, GitHub.comments[0]["body"])
                self.assertEqual(self.stops(), ["pod remove pod123"])
                self.assertFalse((self.dir / "prepared").exists(), "nothing installed or measured")

    def test_done_posts_the_tables_and_stops(self) -> None:
        result = self.run_lab()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.dir / "downloads").read_text().strip(), f"https://raw.example/ZON/{'0' * 40}/deploy/ai-node/prepare.sh")
        self.assertEqual((self.dir / "prepared").read_text().strip(), "prepared qwen3.6-35b-a3b-escha-w2")
        self.assertEqual(len(GitHub.comments), 1)
        self.assertEqual(GitHub.comments[0]["path"], "/repos/vv707-848/ZON/issues/7/comments")
        self.assertIn("| table of lab run |", GitHub.comments[0]["body"])
        self.assertIn("| table of lab load |", GitHub.comments[0]["body"])
        self.assertEqual(self.stops(), ["pod stop pod123"])

    def test_a_warm_up_runs_before_the_lab_and_only_its_summary_is_posted(self) -> None:
        result = self.run_lab()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        runs = (self.dir / "lab").read_text().splitlines()
        self.assertEqual([line.split()[:2] for line in runs],
                         [["lab", "run"], ["lab", "run"], ["lab", "load"], ["lab", "market"]])
        self.assertIn("**lab market** (texts from the market, no expected answer: not a gate)", GitHub.comments[0]["body"])
        self.assertIn("--sample 2 --no-variants", runs[0])
        self.assertNotIn("--sample", runs[1], "the lab itself is every case, with its variants")
        body = GitHub.comments[0]["body"]
        self.assertIn("**warm-up** (two cases of every agent, not scored)", body)
        self.assertIn("overall 0.5 → warm", body)
        self.assertLess(body.index("**warm-up**"), body.index("**lab run**"))
        self.assertEqual(body.count("| table of lab run |"), 1, "the warm-up's own table is not the lab's")

    def test_two_ranking_modes_run_one_after_the_other_on_the_same_pod(self) -> None:
        """ZON_RANK_MODES: every case, the fresh cases and production's sizes once per mode, each table headed by it."""
        result = self.run_lab(ZON_RANK_MODES="both compact")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        runs = [line for line in (self.dir / "lab").read_text().splitlines() if "--sample" not in line]
        which = lambda line: "fresh" if "cases_fresh" in line else "accept" if "cases_accept" in line else ""  # noqa: E731
        modes = [(line.split()[1], line.split("--rank-mode ")[1].split()[0], which(line))
                 for line in runs if "--rank-mode" in line]
        self.assertEqual(modes, [("run", "both", ""), ("run", "both", "fresh"), ("run", "both", "accept"),
                                 ("load", "both", ""), ("run", "compact", ""), ("run", "compact", "fresh"),
                                 ("run", "compact", "accept"), ("load", "compact", "")])
        body = GitHub.comments[0]["body"]
        for header in ("### rank both — the cases", "### rank both — the fresh cases", "### rank compact — the cases",
                       "### rank compact — the acceptance set (graded by hand)"):
            self.assertIn(header, body)

    def test_what_the_missed_cases_wrote_is_posted_after_the_tables(self) -> None:
        report = self.dir / "report.json"
        outcomes = [
            {"case": "chat-018", "agent": "chat", "variant": "base", "score": 0.0, "error": None,
             "metrics": {"covered": 1.0, "lang": 0.0}, "output": {"answer": "باقة الباحث بـ 199 ريال"}},
            {"case": "chat-001", "agent": "chat", "variant": "base", "score": 1.0, "error": None, "metrics": {},
             "output": {"answer": "right"}},
            {"case": "real-ad-011", "agent": "clean_listing", "variant": "base", "score": 0.0,
             "error": "model: llm (deed) unavailable", "metrics": {}, "output": None,
             "refusal": {"source": "desc", "reason": "the text says nothing of a deed", "claim": "affirmed"}},
            {"case": "search-004", "agent": "search_listings", "variant": "base", "score": 0.9, "error": None,
             "metrics": {}, "output": {"results": [{"id": 1}, {"id": 2}, {"id": 3}]}},
            {"case": "search-004", "agent": "search_listings", "variant": "reversed", "score": 0.9, "error": None,
             "metrics": {}, "output": {"results": [{"id": 1}, {"id": 2}, {"id": 9}]}},
        ]
        report.write_text(json.dumps({"outcomes": outcomes}, ensure_ascii=False), encoding="utf-8")
        result = self.run_lab(FAKE_REPORT=str(report))
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(GitHub.comments), 2, "the tables, then what missed")
        missed = GitHub.comments[1]["body"]
        self.assertIn("`chat-018` (chat)", missed)
        self.assertIn('"lang": 0.0', missed)
        self.assertIn("→ باقة الباحث بـ 199 ريال", missed)
        self.assertNotIn("chat-001", missed, "a case that scored 1 is not listed")
        self.assertIn("top three changed when reversed: `search-004`", missed)
        self.assertIn("`real-ad-011` (clean_listing): model: llm (deed) unavailable — affirmed a deed in the desc: "
                      "the text says nothing of a deed", missed)
        self.assertNotIn("صك", missed.split("real-ad-011")[1].split("\n")[0], "no word of the wording on GitHub")

    def test_what_the_market_texts_that_missed_wrote_is_posted_too(self) -> None:
        """21 Nov: the market report stayed on the volume, and a second Pod had to read it."""
        market = self.dir / "market.json"
        missed = [{"id": "market-009", "error": "DependencyUnavailable: llm (amount) unavailable", "output": None},
                  {"id": "market-012", "checks": {"grounded": 0.0, "numbers": 1.0},
                   "output": {"desc_clean": "أرض للبيع", "features": ["مساحة 6200 متر"]}}]
        market.write_text(json.dumps({"clean_listing": {"missed": missed}}, ensure_ascii=False), encoding="utf-8")
        result = self.run_lab(FAKE_MARKET=str(market))
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        body = GitHub.comments[-1]["body"]
        self.assertIn("**what the market texts that missed wrote** (lab market)", body)
        self.assertIn("`market-009` (clean_listing): DependencyUnavailable: llm (amount) unavailable", body)
        self.assertIn('{"grounded": 0.0}', body)
        self.assertIn("→ أرض للبيع | features: مساحة 6200 متر", body)

    def with_reports_and_e2e(self, e2e_exit: int = 0) -> None:
        """The real reports.py where lab-pod.sh looks for it, and an e2e.sh that writes a report and a table."""
        node = self.dir / "w" / "zon" / "deploy" / "ai-node"
        node.mkdir(parents=True, exist_ok=True)
        shutil.copy(NODE / "reports.py", node / "reports.py")
        write(node / "e2e.sh", 'echo "rank $ZON_RANK_MODE" >> "$TEST_DIR/e2e"\n'
              'printf \'{"passes": true, "endpoints": {"search": {"p95_ms": 900}}}\\n\' > "$1"\n'
              'echo "| search | 24 | 0 | 24 | 0 | 700 | 900 | 950 |"\n'
              f'echo "within the limits"\nexit {e2e_exit}\n')

    def test_each_stage_s_reports_leave_the_pod_and_read_back_whole(self) -> None:
        """21 Nov: the reports are kept off the Pod, stage by stage, and `reports.py get` reads them back checked."""
        self.with_reports_and_e2e()
        report, market = self.dir / "report.json", self.dir / "market.json"
        report.write_text(json.dumps({"outcomes": [], "note": "ل" * 70000}, ensure_ascii=False), encoding="utf-8")
        market.write_text(json.dumps({"clean_listing": {"missed": []}}), encoding="utf-8")
        result = self.run_lab(FAKE_REPORT=str(report), FAKE_MARKET=str(market), ZON_E2E="1", ZON_RANK_MODE="compact",
                              DELETE_WHEN_DONE="1")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(GitHub.issues), 1)
        self.assertEqual(len(GitHub.started), 1)
        self.assertIn("started at `abc1234`", GitHub.started[0]["body"])
        self.assertIn("e2e 1; reports in #99", GitHub.started[0]["body"])
        self.assertEqual((self.dir / "e2e").read_text().strip(), "rank compact")
        self.assertIn("**e2e** (Laravel → agents → Qdrant → the model, 8 at once, rank compact)", GitHub.comments[0]["body"])
        self.assertIn("| search | 24 | 0 | 24 |", GitHub.comments[0]["body"])
        names = [re.match(r"report `([^`]+)` · part (\d+)/(\d+)", r["body"]).group(1, 2, 3) for r in GitHub.reports]
        self.assertEqual(len({n for n, _, _ in names}), len([1 for _, i, _ in names if i == "1"]), "each report once")
        out = self.dir / "back"
        got = subprocess.run(["python3", "-I", str(NODE / "reports.py"), "get", "99", str(out)],
                             env={**self.env}, capture_output=True, text=True, timeout=30)
        self.assertEqual(got.returncode, 0, got.stderr)
        read = sorted(p.name for p in out.iterdir())
        self.assertEqual([n.split("-")[0] for n in read], ["e2e", "lab", "market"])
        lab = next(p for p in out.iterdir() if p.name.startswith("lab-"))
        self.assertEqual(lab.read_bytes(), report.read_bytes())
        self.assertEqual(self.stops(), ["pod remove pod123"])

    def test_a_report_changed_on_the_way_is_refused(self) -> None:
        self.with_reports_and_e2e()
        self.run_lab(ZON_E2E="1")
        self.assertTrue(GitHub.reports)
        head, _, text = GitHub.reports[0]["body"].partition("\n")
        GitHub.reports[0]["body"] = f"{head}\n{'A' + text[1:]}"
        got = subprocess.run(["python3", "-I", str(NODE / "reports.py"), "get", "99", str(self.dir / "back")],
                             env={**self.env}, capture_output=True, text=True, timeout=30)
        self.assertNotEqual(got.returncode, 0)
        self.assertIn("sha256 does not match", got.stderr)

    def test_a_failed_e2e_is_recorded_and_the_run_goes_on(self) -> None:
        self.with_reports_and_e2e(e2e_exit=3)
        result = self.run_lab(ZON_E2E="1")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("e2e: stopped", GitHub.comments[0]["body"])
        self.assertIn("lab market", (self.dir / "lab").read_text(), "the market texts still ran")
        self.assertEqual(self.stops(), ["pod stop pod123"])

    def test_a_run_past_its_time_posts_what_it_has_and_deletes_the_pod(self) -> None:
        """ZON_MAX_SECONDS: a step that never ends (here the engine) is stopped, the failure posted, the Pod deleted."""
        self.with_reports_and_e2e()
        result = self.run_lab(ENGINE_HANGS="1", ZON_ENGINE_TRIES="100000", ZON_MAX_SECONDS="2", DELETE_WHEN_DONE="1")
        self.assertEqual(result.returncode, 124, result.stdout + result.stderr)
        self.assertIn("stopped at «engine»", GitHub.comments[-1]["body"])
        self.assertIn("the run passed ZON_MAX_SECONDS", GitHub.comments[-1]["body"])
        self.assertEqual(self.stops(), ["pod remove pod123"])

    def test_the_embedding_server_gets_a_transformers_that_flagembedding_imports(self) -> None:
        """21 Nov: FlagEmbedding 1.4.2 with transformers 5 does not import (modeling_attn_mask_utils is gone)."""
        install = (NODE / "install.sh").read_text(encoding="utf-8")
        line = re.search(r"venv-embed/bin/pip install FlagEmbedding(?:[^\n]*\\\n)*[^\n]*", install)[0]
        pins = dict(re.findall(r"([A-Za-z_-]+)==([0-9.]+)", line))
        self.assertEqual(pins.get("FlagEmbedding"), "1.4.2")
        self.assertTrue(pins.get("transformers", "5").startswith("4."), "transformers pinned below 5")
        for package in ("sentence-transformers", "datasets", "huggingface_hub"):
            self.assertIn(package, pins, f"{package} pinned with it: a newer one pulls transformers 5's hub")

    def test_e2e_serves_with_its_workers_and_refuses_a_single_one(self) -> None:
        """21 Nov: without --no-reload `artisan serve` ignores PHP_CLI_SERVER_WORKERS (one request at a time)."""
        e2e = (NODE / "e2e.sh").read_text(encoding="utf-8")
        serve = next(line for line in e2e.splitlines() if "artisan serve" in line and not line.startswith("#"))
        self.assertIn("--no-reload", serve)
        self.assertIn("PHP_CLI_SERVER_WORKERS=$WORKERS", serve)
        self.assertIn('[ "$SERVING" -ge "$WORKERS" ] ||', e2e, "fewer workers than asked: not measured")
        self.assertIn('--workers="$SERVING"', e2e)

    def test_an_engine_that_dies_is_reported_with_its_step_and_the_pod_stops(self) -> None:
        result = self.run_lab(ENGINE_DIES="1")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(len(GitHub.comments), 1)
        self.assertIn("stopped at «engine»", GitHub.comments[0]["body"])
        self.assertIn("engine: out of memory", GitHub.comments[0]["body"])     # the log's last lines
        self.assertEqual(self.stops(), ["pod stop pod123"])

    def test_a_failed_download_of_prepare_is_reported_and_the_pod_stops(self) -> None:
        result = self.run_lab(DOWNLOAD_FAILS="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("stopped at «prepare»", GitHub.comments[0]["body"])
        self.assertFalse((self.dir / "prepared").exists())
        self.assertEqual(self.stops(), ["pod stop pod123"])

    def test_github_down_never_keeps_the_pod_running(self) -> None:
        GitHub.status = 500
        result = self.run_lab(ENGINE_DIES="1")
        self.assertIn("the comment was not posted", result.stdout + result.stderr)
        self.assertEqual(self.stops(), ["pod stop pod123"])

    def test_the_older_runpodctl_spelling_is_tried_after_the_current_one(self) -> None:
        self.run_lab(NEW_SPELLING_FAILS="1")
        self.assertEqual(self.stops(), ["pod stop pod123", "stop pod pod123"])

    def test_a_kept_pod_waits_instead_of_ending_or_running_again(self) -> None:
        result = self.run_lab(STOP_WHEN_DONE="0")
        self.assertEqual(result.returncode, 0)
        self.assertEqual(self.stops(), [])
        self.assertIn("kept: waiting until the Pod is stopped from the connector", result.stdout)
        self.assertEqual((self.dir / "prepared").read_text().count("prepared"), 1)

    def test_one_quick_arabic_request_is_timed_before_the_lab_and_shown_with_the_tables(self) -> None:
        result = self.run_lab()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(GitHub.asked), 1)
        self.assertEqual(GitHub.asked[0]["auth"], "Bearer test-key")
        self.assertEqual(GitHub.asked[0]["model"], "zon-ai")
        self.assertNotIn("chat_template_kwargs", GitHub.asked[0], "the server's own THINK=0 is what is checked")
        body = GitHub.comments[0]["body"]
        self.assertRegex(body, r"smoke: [0-9.]+ s · 24 tokens · [0-9.]+ tok/s · ok")
        self.assertIn("→ شقة من ثلاث غرف في حي النرجس", body)
        self.assertLess(body.index("smoke:"), body.index("**lab run**"))

    def test_an_answer_that_thinks_stops_before_the_lab(self) -> None:
        for answer, problem in (("<think>\nلنفكر</think>\nشقة", "<think> in the answer"), ("", "empty"),
                                ("A three-room flat.", "no Arabic")):
            with self.subTest(problem=problem):
                self.setUp()
                GitHub.answer = answer
                result = self.run_lab()
                self.assertEqual(result.returncode, 1)
                self.assertIn("stopped at «smoke»", GitHub.comments[0]["body"])
                self.assertIn(problem, GitHub.comments[0]["body"])
                self.assertFalse((self.dir / "lab").exists(), "the lab is not run")
                self.assertEqual(self.stops(), ["pod stop pod123"])

    def test_delete_when_done_removes_the_pod_and_falls_back_to_stopping_it(self) -> None:
        for env, calls in (({}, ["pod remove pod123"]),
                           ({"NEW_SPELLING_FAILS": "1"}, ["pod remove pod123", "remove pod pod123"]),
                           ({"NEW_SPELLING_FAILS": "1", "REMOVE_FAILS": "1"},
                            ["pod remove pod123", "remove pod pod123", "pod stop pod123", "stop pod pod123"])):
            with self.subTest(**env):
                self.setUp()
                self.run_lab(DELETE_WHEN_DONE="1", **env)
                self.assertEqual(self.stops(), calls)
                self.assertEqual(len(GitHub.comments), 1, "posted before the Pod goes")

    def test_a_pod_that_cannot_stop_itself_says_so_and_waits(self) -> None:
        (self.dir / "bin" / "runpodctl").write_text("#!/bin/sh\nexit 1\n")
        result = self.run_lab()
        self.assertIn("could not stop itself", GitHub.comments[-1]["body"])
        self.assertIn("kept: waiting", result.stdout)


class CpuPodCommandTest(unittest.TestCase):
    """The CPU Pod's start command in docs/deploy-runbook.md 2.2 (and docs/model-lab.md), run as RunPod may run it."""

    @staticmethod
    def command(doc: str) -> str:
        match = re.search(r"<!-- cpu-pod-start -->\s*```bash\n(.+?)\n\s*```", doc, re.S)
        assert match is not None, "the runbook's CPU command lost its marker"
        return match.group(1).strip()

    def test_the_two_docs_give_the_same_command(self) -> None:
        runbook = self.command((ROOT / "docs" / "deploy-runbook.md").read_text(encoding="utf-8"))
        lab = (ROOT / "docs" / "model-lab.md").read_text(encoding="utf-8")
        self.assertIn(runbook, lab)

    def run_command(self, command: str, exec_form: bool) -> tuple[list[str], list[str]]:
        folder = pathlib.Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, folder, True)
        bin_ = folder / "bin"
        write(bin_ / "curl", """#!/bin/sh
while [ $# -gt 0 ]; do [ "$1" = -o ] && out="$2"; shift; done
printf 'echo "profile=$ZON_PROFILE no_gpu=$ZON_NO_GPU" >> "%s/prepared"\\n' "$TEST_DIR" > "$out"
""", True)
        write(bin_ / "runpodctl", RUNPODCTL, True)
        write(bin_ / "sleep", "#!/bin/sh\nexit 0\n", True)
        command = command.replace("/workspace", str(folder))
        env = {"PATH": f"{bin_}:{os.environ['PATH']}", "TEST_DIR": str(folder), "RUNPOD_POD_ID": "pod123"}
        argv = shlex.split(command) if exec_form else ["sh", "-c", command]
        subprocess.run(argv, env=env, capture_output=True, text=True, timeout=30, check=True)
        read = lambda name: (folder / name).read_text().splitlines() if (folder / name).exists() else []  # noqa: E731
        return read("prepared"), read("stops")

    def test_shell_and_exec_form_both_install_the_two_profiles_and_stop(self) -> None:
        command = self.command((ROOT / "docs" / "deploy-runbook.md").read_text(encoding="utf-8"))
        for exec_form in (False, True):
            with self.subTest(exec_form=exec_form):
                prepared, stops = self.run_command(command, exec_form)
                self.assertEqual(prepared, ["profile=qwen3.8-27b-escha-w2 no_gpu=1", "profile=qwen3.8-27b-fp8 no_gpu=1"])
                self.assertEqual(stops, ["pod stop pod123"])


@unittest.skipUnless(shutil.which("gcc"), "gpu-check.sh compiles a C probe")
class GpuCheckTest(unittest.TestCase):
    """deploy/ai-node/gpu-check.sh: a driver that runs CUDA 12.8 or later, and SGLang's kernels as a CUDA-12 build."""

    def check(self, cuda: str | None, kernel: str = "cu12", torch: str = "2.11.0+cu128", runtime: str = "escha",
              overcommit: str | None = "0") -> subprocess.CompletedProcess[str]:
        """kernel: «cu12» loads; «cu13» fails until pip reinstalls it; «broken» fails whatever pip does.
        torch: the version found, until pip installs 2.11.0."""
        folder = pathlib.Path(tempfile.mkdtemp())
        self.folder = folder
        self.addCleanup(shutil.rmtree, folder, True)
        w, bin_ = folder / "w", folder / "bin"
        write(w / "profiles" / "p.toml", f'runtime = "{runtime}"\n')
        op = {"escha": "escham_decode_gemv", "escha-moe": "escham_moe_linear"}[runtime]
        header = f"| NVIDIA-SMI 570.0   Driver Version: 570.0   CUDA Version: {cuda}     |" if cuda else "| NVIDIA-SMI 470.0 |"
        write(bin_ / "nvidia-smi", f"""#!/bin/sh
case "$1" in --query-gpu*) echo "NVIDIA A40, 46068 MiB, 570.0" ;; *) echo "{header}" ;; esac
""", True)
        include = subprocess.run(["python3", "-I", "-c", "import sysconfig; print(sysconfig.get_paths()['include'])"],
                                 capture_output=True, text=True, check=True).stdout.strip()
        write(w / "venv-tools" / "bin" / "python", f"#!/bin/sh\necho {include}\n", True)
        # the CUDA-12 build is in place once pip has reinstalled it (the file «cu12»), or from the start
        if kernel == "cu12":
            write(w / "cu12", "")
        # the provider's check is True only for this runtime's own op
        write(w / f"venv-{runtime}" / "bin" / "python", f"""#!/bin/sh
case "$2" in
  *torch.__version__*) [ -f "{w}/torch211" ] && echo 2.11.0+cu128 || echo "{torch}" ;;
  *{op}*) echo "True True True" ;;
  *escham*) echo "True False True" ;;
  "import sgl_kernel") [ -f "{w}/cu12" ] && [ "{kernel}" != broken ] && exit 0
    echo "ImportError: libnvrtc.so.13: cannot open shared object file" >&2; exit 1 ;;
  *sglang-kernel*) [ -f "{w}/cu12" ] && echo 0.4.4+cu129 || echo 0.4.6.post1 ;;
  *) echo 1.2.5 ;;
esac
""", True)
        write(w / f"venv-{runtime}" / "bin" / "pip", f"""#!/bin/sh
[ "$1" = freeze ] && exit 0
echo "$*" >> "{folder}/pip"
case "$*" in *torch==*) touch "{w}/torch211" ;; *sglang-kernel*) touch "{w}/cu12" ;; esac
""", True)
        self.overcommit = folder / "overcommit_memory"
        if overcommit is not None:
            write(self.overcommit, overcommit)
        env = {"PATH": f"{bin_}:{os.environ['PATH']}", "ZON_WORKSPACE": str(w), "ZON_PROFILE": "p",
               "ZON_OVERCOMMIT": str(self.overcommit if overcommit is not None else folder / "no" / "such")}
        return subprocess.run(["bash", str(NODE / "gpu-check.sh")], env=env, capture_output=True, text=True, timeout=60)

    def test_a_driver_below_12_8_is_refused_with_what_to_do(self) -> None:
        for cuda in ("12.4", "12.7", None):
            with self.subTest(cuda=cuda):
                result = self.check(cuda)
                self.assertEqual(result.returncode, 1)
                self.assertIn("allowedCudaVersions", result.stderr)

    def test_12_8_and_later_pass(self) -> None:
        for cuda in ("12.8", "12.10", "13.0"):
            with self.subTest(cuda=cuda):
                result = self.check(cuda)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn("escha check: True True True", result.stdout)

    def pip_calls(self) -> list[str]:
        path = self.folder / "pip"
        return path.read_text().splitlines() if path.exists() else []

    def test_a_kernel_that_does_not_load_is_replaced_by_0_4_4_for_cuda_12(self) -> None:
        result = self.check("13.2", kernel="cu13")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("libnvrtc.so.13", result.stderr)
        self.assertEqual(self.pip_calls(), ["install -q --no-deps --force-reinstall sglang-kernel==0.4.4 "
                                            "--index-url https://sgl-project.github.io/whl/cu129/"])
        self.assertIn("sglang-kernel 0.4.6.post1 does not load here (libnvrtc.so.13: cannot open shared object file)", result.stderr)
        self.assertIn("sglang-kernel 0.4.4+cu129 loads", result.stdout)

    def test_a_kernel_that_loads_is_left_alone(self) -> None:
        result = self.check("13.2")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.pip_calls(), [])

    def test_torch_moved_by_the_install_is_put_back_to_2_11_0(self) -> None:
        result = self.check("13.2", torch="2.10.0+cu128")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("torch 2.10.0+cu128 in the runtime's environment — installing 2.11.0", result.stderr)
        self.assertEqual(self.pip_calls(), ["install -q torch==2.11.0 torchvision==0.26.0 "
                                            "--index-url https://download.pytorch.org/whl/cu128"])

    def test_torch_2_11_0_is_left_alone(self) -> None:
        for found in ("2.11.0+cu128", "2.11.0"):
            with self.subTest(torch=found):
                self.check("13.2", torch=found)
                self.assertEqual(self.pip_calls(), [])

    def test_the_moe_runtime_is_checked_in_its_own_environment_with_its_own_op(self) -> None:
        result = self.check("13.2", runtime="escha-moe")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("escha check: True True True", result.stdout)
        self.assertIn("sglang-kernel 0.4.4+cu129 loads", result.stdout)

    def test_overcommit_is_set_where_the_container_allows_and_reported_where_not(self) -> None:
        result = self.check("13.2")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.overcommit.read_text().strip(), "1")
        self.assertIn("vm.overcommit_memory 1", result.stdout)
        result = self.check("13.2", overcommit=None)
        self.assertEqual(result.returncode, 0, "never a failure: serve.sh says it is otherwise safe to ignore")
        self.assertIn("vm.overcommit_memory ? — not settable in this container", result.stdout)

    def test_a_kernel_that_still_fails_stops_before_the_engine(self) -> None:
        result = self.check("13.2", kernel="broken")
        self.assertEqual(result.returncode, 1)
        self.assertIn("sglang-kernel still does not load on this card", result.stderr)


if __name__ == "__main__":
    unittest.main()
