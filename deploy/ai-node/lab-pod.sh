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
#   ZON_PROFILE      the profile to measure (default qwen3.8-27b-escha-w2)
#   GPU_NAME         the card, for the report (default A40)
#   ZON_REF          the repository's branch or full commit (default main)
#   GH_TOKEN         optional — a fine-grained token with «Issues: read and write» on vv707-848/ZON only
#   LAB_ISSUE        optional — the issue the tables are posted to (with GH_TOKEN)
#   STOP_WHEN_DONE   1 (default) stops the Pod at the end; 0 keeps it for a look at /workspace/logs
#   DELETE_WHEN_DONE 1 deletes the Pod instead of stopping it (the network volume stays); a delete that fails stops it
#
# Before the lab, one quick request (smoke): Arabic, the seconds it takes, and no <think> in the answer — the
# server is started with thinking off (THINK=0 for Escha). An answer that fails it is posted, and the lab is not run.
#
# However it ends — done, or failed at any step — the same exit (finish) runs: a failure is posted to the issue with
# the step and the log's last lines, then the Pod is stopped. It never just ends: RunPod is reported to start the
# command again when it exits, which would repeat the run and the bill. Kept (STOP_WHEN_DONE=0) or not stoppable, it
# waits until someone stops it from the connector.
set -euo pipefail
: "${LLM_API_KEY:?set LLM_API_KEY in the Pod settings}"
W=${ZON_WORKSPACE:-/workspace}                       # another folder for the tests only (_tools/tests/test_ai_node.py)
API=${ZON_GITHUB_API:-https://api.github.com}
RAW=${ZON_RAW_URL:-https://raw.githubusercontent.com/vv707-848/ZON}
PROFILE=${ZON_PROFILE:-qwen3.8-27b-escha-w2}
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
  [ -n "$ENGINE" ] && kill "$ENGINE" 2>/dev/null
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

# ①–③ the repository at ZON_REF, the node's files, the profile installed once (prepare.sh — production's too).
# Downloaded to a file first: a script read from a failed or cut download would run empty, or half
STEP="prepare"
curl -fsSL --retry 3 "$RAW/$REF/deploy/ai-node/prepare.sh" -o "$W/prepare.sh"
ZON_PROFILE="$PROFILE" bash "$W/prepare.sh"

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

# ⑤ the model lab, then production's sizes — the same commands as at the terminal (docs/model-lab.md, step 5)
STEP="lab"
# a failed run is still posted: its table says what failed
.venv/bin/zon-agents lab run --profile "$PROFILE" --gpu "$GPU" --notes "lab-pod $STAMP · $(git rev-parse --short HEAD)" \
  --out "$W/reports/lab-$PROFILE-$STAMP.json" 2>&1 | tee "$W/logs/lab-table-$STAMP.txt" || true
.venv/bin/zon-agents lab load --profile "$PROFILE" --out "$W/reports/load-$PROFILE-$STAMP.json" 2>&1 \
  | tee "$W/logs/load-table-$STAMP.txt" || true

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
  printf '\n```\nReports on the volume: `/workspace/reports/lab-%s-%s.json` · `load-%s-%s.json`\n' "$PROFILE" "$STAMP" "$PROFILE" "$STAMP"
} > "$W/logs/tables-$STAMP.md"
post "$W/logs/tables-$STAMP.md"

# ⑦ stop the Pod (finish) — billing is by the second; the volume stays until deleted
STEP="done"
