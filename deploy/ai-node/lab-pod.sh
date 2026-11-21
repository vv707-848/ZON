#!/usr/bin/env bash
# /workspace/lab-pod.sh — a measurement with no one at the terminal (docs/model-lab.md «جلسة بلا طرفية»).
# The Pod's start command on a TEMPORARY Pod (never production's): it brings the repository, installs the profile once,
# serves it, runs the model lab and the production-size check, posts the two tables to a GitHub issue, and stops
# the Pod. Made for a RunPod connector that can create, start and stop Pods but cannot open their terminal.
#
# Start command (saved, then run: a script piped into bash can have its rest read by any command that reads stdin):
#   bash -c "curl -fsSL https://raw.githubusercontent.com/vv707-848/ZON/<commit>/deploy/ai-node/lab-pod.sh -o /workspace/lab-pod.sh && bash /workspace/lab-pod.sh"
# Pod settings (environment):
#   LLM_API_KEY      required — any random string; the engine and the lab share it inside the Pod
#   ZON_PROFILE      the profile to measure — required: a Pod never measures a model it was not asked for
#   GPU_NAME         the card, for the report (default A40)
#   ZON_REF          the repository's branch or full commit (default main)
#   GH_TOKEN         optional — a fine-grained token with «Issues: read and write» on vv707-848/ZON only
#   LAB_ISSUE        optional — the issue the tables are posted to (with GH_TOKEN)
#   STOP_WHEN_DONE   1 (default) stops the Pod at the end; 0 keeps it for a look at /workspace/logs
#   DELETE_WHEN_DONE 1 deletes the Pod instead of stopping it (the network volume stays); a delete that fails stops it
#   ZON_RANK_MODES   optional — "both compact": the lab, the fresh cases and lab load once per ranking mode
#   ZON_E2E          1: then search and matching through Laravel's API, MySQL, Redis, Qdrant and the agents on this
#                    Pod (e2e.sh, `php artisan zon:lab-e2e`), with ZON_RANK_MODE (default compact) in the agents
#   ZON_MAX_SECONDS  the whole run at most (default 7200): past it the Pod posts what is done and deletes itself
#
# Reports: each stage's JSON goes out of the Pod as soon as it is written, to an issue of its own («lab reports …»,
# deploy/ai-node/reports.py): the disk goes with the Pod, and a run that fails keeps what it finished.
#
# Before the lab, one quick request (smoke): Arabic, the seconds it takes, and no <think> in the answer — the
# server is started with thinking off (THINK=0 for Escha). An answer that fails it is posted, and the lab is not run.
#
# However it ends — done, or failed at any step — the same exit (finish) runs: a failure is posted to the issue with
# the step and the log's last lines, then the Pod is stopped. It never just ends: RunPod is reported to start the
# command again when it exits, which would repeat the run and the bill. Kept (STOP_WHEN_DONE=0) or not stoppable, it
# waits until someone stops it from the connector.
set -euo pipefail
W=${ZON_WORKSPACE:-/workspace}                       # another folder for the tests only (_tools/tests/test_ai_node.py)
API=${ZON_GITHUB_API:-https://api.github.com}
RAW=${ZON_RAW_URL:-https://raw.githubusercontent.com/vv707-848/ZON}
PROFILE=${ZON_PROFILE:-unset}
GPU=${GPU_NAME:-A40}
REF=${ZON_REF:-main}
STAMP=$(date -u +%Y%m%d-%H%M)
LOG="$W/logs/lab-$PROFILE-$STAMP.log"
STEP="start"
ENGINE=""
LLM_URL=${ZON_LLM_URL:-http://127.0.0.1:30000}       # the engine, as serve.py serves it
mkdir -p "$W/logs" "$W/reports"
exec > >(tee -a "$LOG") 2>&1

# A comment on the issue: its body from the file given (nothing without GH_TOKEN and LAB_ISSUE). Fails quietly:
# a comment never stops the Pod from stopping.
# the repository's raw files and git: with GH_TOKEN when one is given (a private repository needs «Contents: read»)
fetch() { if [ -n "${GH_TOKEN:-}" ]; then curl -fsSL --retry 3 -H "Authorization: Bearer $GH_TOKEN" "$@"; else curl -fsSL --retry 3 "$@"; fi; }

# a stage's reports out of the Pod at once, to this run's reports issue (opened at the start); quietly as post()
REPORTS=""
upload() {
  [ -n "$REPORTS" ] || return 0
  local files=()
  for f in "$@"; do [ -f "$f" ] && ! grep -qxF "$f" "$W/logs/uploaded-$STAMP" 2>/dev/null && files+=("$f"); done
  [ ${#files[@]} -gt 0 ] || return 0
  python3 "$W/zon/deploy/ai-node/reports.py" put "$REPORTS" "${files[@]}" && printf '%s\n' "${files[@]}" >> "$W/logs/uploaded-$STAMP" \
    || echo "the reports were not posted: ${files[*]}" >&2
}

post() {
  [ -n "${GH_TOKEN:-}" ] && [ -n "${LAB_ISSUE:-}" ] || return 0
  python3 - "$1" "$API" <<'PY' || echo "the comment was not posted" >&2
import json, os, sys, urllib.request
body = open(sys.argv[1], encoding="utf-8", errors="replace").read()[-60000:]   # a tail cut inside a letter
request = urllib.request.Request(
    f"{sys.argv[2]}/repos/vv707-848/ZON/issues/{os.environ['LAB_ISSUE']}/comments",
    data=json.dumps({"body": body}).encode(), method="POST",
    headers={"Authorization": f"Bearer {os.environ['GH_TOKEN']}", "Accept": "application/vnd.github+json"})
print("posted:", urllib.request.urlopen(request, timeout=30).status)
PY
}

delete_pod() {
  command -v runpodctl >/dev/null && [ -n "${RUNPOD_POD_ID:-}" ] || return 1
  runpodctl pod remove "$RUNPOD_POD_ID" || runpodctl remove pod "$RUNPOD_POD_ID"
}

stop_pod() {
  command -v runpodctl >/dev/null && [ -n "${RUNPOD_POD_ID:-}" ] || return 1
  # runpodctl's current spelling first (docs.runpod.io «runpodctl pod»), then the older one
  runpodctl pod stop "$RUNPOD_POD_ID" || runpodctl stop pod "$RUNPOD_POD_ID"
}

finish() {
  local code=$?
  set +e
  trap - EXIT
  trap '' PIPE TERM                                  # a write to a log that is gone never stops the deletion
  [ -n "$ENGINE" ] && kill "$ENGINE" 2>/dev/null
  [ -n "${WATCHDOG:-}" ] && { pkill -P "$WATCHDOG" 2>/dev/null; kill "$WATCHDOG" 2>/dev/null; }   # and its sleep
  upload "$W"/reports/*.json                         # what was done, before the disk goes
  if [ "$code" != 0 ]; then
    local note="$W/logs/failed-$STAMP.md"
    {
      printf '**%s** on **%s** · %s — **stopped at «%s»** (exit %s)\n\n' "$PROFILE" "$GPU" "$STAMP" "$STEP" "$code"
      printf 'The last lines of the log (`%s`):\n```\n' "$LOG"
      tail -n 80 "$LOG" 2>/dev/null
      printf '\n```\n'
    } > "$note"
    post "$note"
  fi
  if [ "${STOP_WHEN_DONE:-1}" = "1" ]; then
    if { [ "${DELETE_WHEN_DONE:-0}" = "1" ] && delete_pod; } || stop_pod; then
      sleep "${ZON_STOP_GRACE:-300}"                 # the stop takes the container down meanwhile
      exit "$code"
    fi
    printf '**%s** · %s — the Pod could not stop itself: stop it from the connector.\n' "$PROFILE" "$STAMP" > "$W/logs/unstopped-$STAMP.md"
    post "$W/logs/unstopped-$STAMP.md"
  fi
  echo "kept: waiting until the Pod is stopped from the connector"
  sleep "${ZON_KEEP_SECONDS:-infinity}"
  exit "$code"
}
trap finish EXIT
trap 'exit 124' TERM                                 # the watchdog's: finish() runs, posts what is done, deletes the Pod
# the watchdog: the script is told first, then its steps are stopped — bash runs the trap as soon as the step it
# waits on ends, before a next command could read a stopped engine as a failure of its own
( sleep "${ZON_MAX_SECONDS:-7200}"; echo "the run passed ZON_MAX_SECONDS" >&2
  kill -TERM $$
  for child in $(pgrep -P $$); do
    [ "$child" != "$BASHPID" ] && [ "$(ps -o comm= -p "$child")" != tee ] && kill -TERM "$child" 2>/dev/null
  done ) &
WATCHDOG=$!
# the settings are checked with the exit in place: a Pod missing one posts why and goes, it never just ends (RunPod
# would start the command again)
STEP="settings"
: "${LLM_API_KEY:?set LLM_API_KEY in the Pod settings}"
[ "$PROFILE" != "unset" ] || { echo "set ZON_PROFILE in the Pod settings: the profile to measure" >&2; exit 1; }

# ①–③ the repository at ZON_REF, the node's files, the profile installed once (prepare.sh — production's too).
# Downloaded to a file first: a script read from a failed or cut download would run empty, or half
STEP="prepare"
fetch "$RAW/$REF/deploy/ai-node/prepare.sh" -o "$W/prepare.sh"
ZON_PROFILE="$PROFILE" bash "$W/prepare.sh"
# the run's reports issue, and a first word on the issue: the script began (the creator's side watches for it)
if [ -n "${GH_TOKEN:-}" ] && [ -n "${LAB_ISSUE:-}" ]; then
  REPORTS=$(python3 "$W/zon/deploy/ai-node/reports.py" open "lab reports $STAMP · $PROFILE · $(git -C "$W/zon" rev-parse --short HEAD)" 2>/dev/null || true)
  printf '**%s** on **%s** · %s — started at `%s`, rank modes «%s», e2e %s; reports in #%s\n' "$PROFILE" "$GPU" "$STAMP" \
    "$(git -C "$W/zon" rev-parse HEAD)" "${ZON_RANK_MODES:-default}" "${ZON_E2E:-0}" "${REPORTS:-?}" > "$W/logs/started-$STAMP.md"
  post "$W/logs/started-$STAMP.md"
fi

# ④ the engine, as production serves it: zon-ai on 127.0.0.1:30000 with the key. The first start compiles kernels
# and captures CUDA graphs — up to 30 minutes are allowed before giving up
STEP="engine"
python3 "$W/serve.py" "$PROFILE" > "$W/logs/engine-$PROFILE-$STAMP.log" 2>&1 &
ENGINE=$!
ready() { curl -sf --max-time 10 -H "Authorization: Bearer $LLM_API_KEY" "$LLM_URL/v1/models" | grep -q zon-ai; }
for _ in $(seq 1 "${ZON_ENGINE_TRIES:-360}"); do
  ready && break
  kill -0 "$ENGINE" 2>/dev/null || { echo "the engine stopped — see engine-$PROFILE-$STAMP.log" >&2; tail -50 "$W/logs/engine-$PROFILE-$STAMP.log"; exit 1; }
  sleep "${ZON_ENGINE_WAIT:-5}"
done
ready || { echo "the engine did not answer in time" >&2; tail -50 "$W/logs/engine-$PROFILE-$STAMP.log"; exit 1; }
grep -m1 -o "max_total_num_tokens=[0-9]*" "$W/logs/engine-$PROFILE-$STAMP.log" || true

# ④½ one quick request before the lab: an Arabic answer, its seconds and tokens per second, and no <think> in it
STEP="smoke"
python3 - "$LLM_URL" "$W/logs/smoke-$STAMP.txt" <<'PY'
import json, os, re, sys, time, urllib.request
url, out = sys.argv[1], sys.argv[2]
ask = "اكتب جملتين قصيرتين تصف شقة من ثلاث غرف في حي النرجس بالرياض."
body = {"model": "zon-ai", "messages": [{"role": "user", "content": ask}], "max_tokens": 160, "temperature": 0.3}
request = urllib.request.Request(f"{url}/v1/chat/completions", data=json.dumps(body).encode(), method="POST",
                                 headers={"Authorization": f"Bearer {os.environ['LLM_API_KEY']}", "Content-Type": "application/json"})
started = time.monotonic()
answer = json.load(urllib.request.urlopen(request, timeout=120))
seconds = time.monotonic() - started
message = answer["choices"][0]["message"]
text = (message.get("content") or "").strip()
tokens = (answer.get("usage") or {}).get("completion_tokens") or 0
problems = [name for name, bad in (("empty", not text),
                                   ("<think> in the answer", "<think>" in text or bool(message.get("reasoning_content"))),
                                   ("no Arabic", not re.search("[؀-ۿ]", text))) if bad]
line = f"smoke: {seconds:.1f} s · {tokens} tokens · {tokens / seconds:.1f} tok/s · {'; '.join(problems) or 'ok'}"
open(out, "w", encoding="utf-8").write(f"{line}\n{ask}\n→ {text[:600]}\n")
print(line)
print(text[:600])
sys.exit(1 if problems else 0)
PY

# ④¾ a warm-up, not scored: the first requests after a start compile the engine's kernels for shapes it has not run
# and each answer schema's grammar. On the A40 the smoke took 16.5 s for 52 tokens, and the lab's first eight (all
# chat) timed out in both runs (#119) — the engine's start, not the model. Two cases of every agent, eight at once
STEP="warm-up"
cd "$W/zon/agents"
"$W/venv-tools/bin/uv" sync --locked --no-dev
.venv/bin/zon-agents lab run --profile "$PROFILE" --sample 2 --no-variants --notes "warm-up $STAMP, not scored" \
  --out "$W/reports/warmup-$PROFILE-$STAMP.json" > "$W/logs/warmup-$STAMP.txt" 2>&1 || true
upload "$W/reports/warmup-$PROFILE-$STAMP.json"

# ⑤ the model lab, then production's sizes — the same commands as at the terminal (docs/model-lab.md, step 5)
STEP="lab"
# a failed run is still posted: its table says what failed
if [ -z "${ZON_RANK_MODES:-}" ]; then
  .venv/bin/zon-agents lab run --profile "$PROFILE" --gpu "$GPU" --notes "lab-pod $STAMP · $(git rev-parse --short HEAD)" \
    --out "$W/reports/lab-$PROFILE-$STAMP.json" 2>&1 | tee "$W/logs/lab-table-$STAMP.txt" || true
  .venv/bin/zon-agents lab load --profile "$PROFILE" --out "$W/reports/load-$PROFILE-$STAMP.json" 2>&1 \
    | tee "$W/logs/load-table-$STAMP.txt" || true
else
  # two ways of asking the ranking model, on the same card one after the other (ranking.RANK_MODE): every case,
  # the fresh cases no one tuned on (lab/cases_fresh), and production's sizes — each mode's own tables
  for MODE in $ZON_RANK_MODES; do
    {
      printf '### rank %s — the cases\n' "$MODE"
      .venv/bin/zon-agents lab run --profile "$PROFILE" --gpu "$GPU" --rank-mode "$MODE" \
        --notes "lab-pod $STAMP · $(git rev-parse --short HEAD)" --out "$W/reports/lab-$PROFILE-$STAMP-$MODE.json" 2>&1 || true
      printf '### rank %s — the fresh cases\n' "$MODE"
      .venv/bin/zon-agents lab run --profile "$PROFILE" --gpu "$GPU" --rank-mode "$MODE" --cases zon_agents/lab/cases_fresh \
        --notes "lab-pod $STAMP fresh" --out "$W/reports/fresh-$PROFILE-$STAMP-$MODE.json" 2>&1 || true
    } | tee -a "$W/logs/lab-table-$STAMP.txt"
    upload "$W/reports/lab-$PROFILE-$STAMP-$MODE.json" "$W/reports/fresh-$PROFILE-$STAMP-$MODE.json"
    { printf '### rank %s\n' "$MODE"
      .venv/bin/zon-agents lab load --profile "$PROFILE" --rank-mode "$MODE" --out "$W/reports/load-$PROFILE-$STAMP-$MODE.json" 2>&1 || true
    } | tee -a "$W/logs/load-table-$STAMP.txt"
    upload "$W/reports/load-$PROFILE-$STAMP-$MODE.json"
  done
  # what missed is read from the first mode's run of every case
  cp "$W/reports/lab-$PROFILE-$STAMP-${ZON_RANK_MODES%% *}.json" "$W/reports/lab-$PROFILE-$STAMP.json" 2>/dev/null || true
fi
# texts from the market as the owner collected them, with no expected answer: measured, never a gate (lab/market.py)
.venv/bin/zon-agents lab market --profile "$PROFILE" --out "$W/reports/market-$PROFILE-$STAMP.json" 2>&1 \
  | tee "$W/logs/market-table-$STAMP.txt" || true
upload "$W"/reports/lab-*.json "$W"/reports/load-*.json "$W/reports/market-$PROFILE-$STAMP.json"

# ⑤½ search and matching through Laravel's API and Qdrant, eight at once (e2e.sh): a failure is recorded, and the run
# goes on to post what it has
if [ "${ZON_E2E:-0}" = "1" ]; then
  STEP="e2e"
  ZON_RANK_MODE="${ZON_RANK_MODE:-compact}" bash "$W/zon/deploy/ai-node/e2e.sh" "$W/reports/e2e-$PROFILE-$STAMP.json" 2>&1 \
    | tee "$W/logs/e2e-table-$STAMP.txt" || echo "e2e: stopped — see the log above" | tee -a "$W/logs/e2e-table-$STAMP.txt"
  upload "$W/reports/e2e-$PROFILE-$STAMP.json"
fi

# ⑥ the two tables to the issue: what the connector cannot read from the Pod, GitHub shows. The JSON reports stay
# on /workspace/reports, for the panel's «مختبر النماذج»
STEP="report"
{
  printf '**%s** on **%s** · %s\n\n**smoke** (one request before the lab)\n```\n' "$PROFILE" "$GPU" "$STAMP"
  cat "$W/logs/smoke-$STAMP.txt"
  printf '```\n**warm-up** (two cases of every agent, not scored)\n```\n'
  grep -E "^overall|^errors|cases ·" "$W/logs/warmup-$STAMP.txt" || tail -3 "$W/logs/warmup-$STAMP.txt"
  printf '```\n**lab run**\n```\n'
  tail -c 30000 "$W/logs/lab-table-$STAMP.txt"
  printf '\n```\n**lab load** (production sizes, 8 at once)\n```\n'
  tail -c 30000 "$W/logs/load-table-$STAMP.txt"
  printf '\n```\n**lab market** (texts from the market, no expected answer: not a gate)\n```\n'
  tail -c 3000 "$W/logs/market-table-$STAMP.txt"
  if [ -f "$W/logs/e2e-table-$STAMP.txt" ]; then
    printf '\n```\n**e2e** (Laravel → agents → Qdrant → the model, 8 at once, rank %s)\n```\n' "${ZON_RANK_MODE:-compact}"
    grep -E "^\||^within|^misses|^== e2e|stopped|did not come up|^PHP" "$W/logs/e2e-table-$STAMP.txt" | tail -40
  fi
  printf '\n```\nReports out of the Pod: #%s (`python3 deploy/ai-node/reports.py get %s <dir>`)\n' "${REPORTS:-?}" "${REPORTS:-?}"
} > "$W/logs/tables-$STAMP.md"
post "$W/logs/tables-$STAMP.md"

# ⑥½ what each case that missed wrote, in a second comment: the tables say how many, this says which and why — read
# without a Pod after the run (#119: the chat's language and «covered» could not be read from the tables)
python3 - "$W/reports/lab-$PROFILE-$STAMP.json" "$W/logs/missed-$STAMP.md" <<'PY' && post "$W/logs/missed-$STAMP.md" || true
import json, sys
try:
    report = json.load(open(sys.argv[1], encoding="utf-8"))
except (OSError, ValueError):
    sys.exit(1)
lines, top = [], {}
for o in report.get("outcomes", []):
    out = o.get("output") or {}
    for name in ("results", "matched"):
        if isinstance(out.get(name), list):
            top[(o["case"], o["variant"])] = {r.get("id") for r in out[name][:3]}
            break
    missed = o.get("error") or (o["agent"] in ("chat", "report_write", "clean_listing", "summarize") and o["score"] < 1)
    if o["variant"] != "base" or not missed:
        continue
    said = out.get("answer") or out.get("analysis") or out.get("desc_clean") or out.get("summary") or ""
    if o["agent"] == "report_write":
        said = " | ".join([said, *(r.get("text", "") for r in out.get("recommendations", []))])
    bad = {k: v for k, v in o.get("metrics", {}).items() if v != 1}
    why = o.get("error") or f"score {o['score']} {json.dumps(bad, ensure_ascii=False)}"
    if r := o.get("refusal"):  # a wording refused for its deed: why, where, the excerpt (redacted by the lab)
        why += f" — {r['reason']}, in the {r['source']}: «{r['excerpt']}»"
    lines.append(f"- `{o['case']}` ({o['agent']}): {why}\n  → {' '.join(str(said).split())[:280]}")
flips = sorted(c for (c, v), ids in top.items() if v == "reversed" and top.get((c, "base")) not in (None, ids))
if flips:
    lines.append("- top three changed when reversed: " + ", ".join(f"`{c}`" for c in flips))
if not lines:
    sys.exit(1)
body = "**what the cases that missed wrote** (lab run)\n\n" + "\n".join(lines)
open(sys.argv[2], "w", encoding="utf-8").write(body[:60000])
PY

# ⑥¾ what the market texts that missed wrote, in a third comment (21 Nov: it took a second Pod to read them)
python3 - "$W/reports/market-$PROFILE-$STAMP.json" "$W/logs/market-missed-$STAMP.md" <<'PY' && post "$W/logs/market-missed-$STAMP.md" || true
import json, sys
try:
    report = json.load(open(sys.argv[1], encoding="utf-8"))
except (OSError, ValueError):
    sys.exit(1)
lines = []
for agent, row in report.items():
    for m in row.get("missed", []):
        out = m.get("output") or {}
        said = out.get("desc_clean") or out.get("answer") or ""
        if out.get("features"):
            said = f"{said} | features: {' · '.join(out['features'])}"
        why = m.get("error") or json.dumps({k: v for k, v in m.get("checks", {}).items() if v != 1})
        if r := m.get("refusal"):
            why += f" — {r['reason']}, in the {r['source']}: «{r['excerpt']}»"
        lines.append(f"- `{m['id']}` ({agent}): {why}\n  → {' '.join(str(said).split())[:280]}")
if not lines:
    sys.exit(1)
body = "**what the market texts that missed wrote** (lab market)\n\n" + "\n".join(lines)
open(sys.argv[2], "w", encoding="utf-8").write(body[:60000])
PY

# ⑦ stop the Pod (finish) — billing is by the second; the volume stays until deleted
STEP="done"
