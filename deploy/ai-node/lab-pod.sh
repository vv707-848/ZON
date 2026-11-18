#!/usr/bin/env bash
# /workspace/lab-pod.sh — a measurement with no one at the terminal (docs/model-lab.md «جلسة بلا طرفية»).
# The Pod's start command on a TEMPORARY Pod (never production's): it brings the repository, installs the profile once,
# serves it, runs the model lab and the production-size check, posts the two tables to a GitHub issue, and stops
# the Pod. Made for a RunPod connector that can create, start and stop Pods but cannot open their terminal.
#
# Start command (saved, then run: a script piped into bash can have its rest read by any command that reads stdin):
#   bash -c "curl -fsSL https://raw.githubusercontent.com/vv707-848/ZON/main/deploy/ai-node/lab-pod.sh -o /workspace/lab-pod.sh && bash /workspace/lab-pod.sh"
# Pod settings (environment):
#   LLM_API_KEY      required — any random string; the engine and the lab share it inside the Pod
#   ZON_PROFILE      the profile to measure (default qwen3.8-27b-escha-w2)
#   GPU_NAME         the card, for the report (default A40)
#   ZON_REF          the repository's branch or commit (default main)
#   GH_TOKEN         optional — a fine-grained token with «Issues: read and write» on vv707-848/ZON only
#   LAB_ISSUE        optional — the issue the tables are posted to (with GH_TOKEN)
#   STOP_WHEN_DONE   1 (default) stops the Pod at the end; 0 keeps it for a look at /workspace/logs
set -euo pipefail
: "${LLM_API_KEY:?set LLM_API_KEY in the Pod settings}"
PROFILE=${ZON_PROFILE:-qwen3.8-27b-escha-w2}
GPU=${GPU_NAME:-A40}
REF=${ZON_REF:-main}
STAMP=$(date -u +%Y%m%d-%H%M)
mkdir -p /workspace/logs /workspace/reports
exec > >(tee -a "/workspace/logs/lab-$PROFILE-$STAMP.log") 2>&1

# ①–③ the repository at ZON_REF, the node's files, the profile installed once (prepare.sh — production's too)
ZON_PROFILE="$PROFILE" bash <(curl -fsSL "https://raw.githubusercontent.com/vv707-848/ZON/$REF/deploy/ai-node/prepare.sh")

# ④ the engine, as production serves it: zon-ai on 127.0.0.1:30000 with the key. The first start compiles kernels
# and captures CUDA graphs — up to 30 minutes are allowed before giving up
python3 /workspace/serve.py "$PROFILE" > "/workspace/logs/engine-$PROFILE-$STAMP.log" 2>&1 &
ENGINE=$!
for _ in $(seq 1 360); do
  curl -sf -H "Authorization: Bearer $LLM_API_KEY" http://127.0.0.1:30000/v1/models | grep -q zon-ai && break
  kill -0 "$ENGINE" 2>/dev/null || { echo "the engine stopped — see engine-$PROFILE-$STAMP.log" >&2; tail -50 "/workspace/logs/engine-$PROFILE-$STAMP.log"; exit 1; }
  sleep 5
done
curl -sf -H "Authorization: Bearer $LLM_API_KEY" http://127.0.0.1:30000/v1/models | grep -q zon-ai
grep -m1 -o "max_total_num_tokens=[0-9]*" "/workspace/logs/engine-$PROFILE-$STAMP.log" || true

# ⑤ the model lab, then production's sizes — the same commands as at the terminal (docs/model-lab.md, step 5)
cd /workspace/zon/agents
/workspace/venv-tools/bin/uv sync --locked --no-dev
# a failed run is still posted: its table says what failed
.venv/bin/zon-agents lab run --profile "$PROFILE" --gpu "$GPU" --notes "lab-pod $STAMP · $(git rev-parse --short HEAD)" \
  --out "/workspace/reports/lab-$PROFILE-$STAMP.json" 2>&1 | tee "/workspace/logs/lab-table-$STAMP.txt" || true
.venv/bin/zon-agents lab load --profile "$PROFILE" --out "/workspace/reports/load-$PROFILE-$STAMP.json" 2>&1 \
  | tee "/workspace/logs/load-table-$STAMP.txt" || true

# ⑥ the two tables to the issue: what the connector cannot read from the Pod, GitHub shows. The JSON reports stay
# on /workspace/reports, for the panel's «مختبر النماذج»
if [ -n "${GH_TOKEN:-}" ] && [ -n "${LAB_ISSUE:-}" ]; then
  python3 - "$PROFILE" "$GPU" "$STAMP" <<'PY'
import json, os, sys, urllib.request
profile, gpu, stamp = sys.argv[1:4]
def table(name):
    text = open(f"/workspace/logs/{name}-table-{stamp}.txt", encoding="utf-8").read()
    return text[-30000:]
body = (f"**{profile}** on **{gpu}** · {stamp}\n\n**lab run**\n```\n{table('lab')}\n```\n"
        f"**lab load** (production sizes, 8 at once)\n```\n{table('load')}\n```\n"
        f"Reports on the volume: `/workspace/reports/lab-{profile}-{stamp}.json` · `load-{profile}-{stamp}.json`")
request = urllib.request.Request(
    f"https://api.github.com/repos/vv707-848/ZON/issues/{os.environ['LAB_ISSUE']}/comments",
    data=json.dumps({"body": body}).encode(), method="POST",
    headers={"Authorization": f"Bearer {os.environ['GH_TOKEN']}", "Accept": "application/vnd.github+json"})
print("posted:", urllib.request.urlopen(request, timeout=30).status)
PY
fi

# ⑦ stop the Pod — billing is by the second; the volume stays until deleted
kill "$ENGINE" 2>/dev/null || true
# runpodctl comes in every Pod with a Pod-scoped key (RunPod's docs); both spellings of its command are tried
if [ "${STOP_WHEN_DONE:-1}" = "1" ] && command -v runpodctl >/dev/null && [ -n "${RUNPOD_POD_ID:-}" ]; then
  runpodctl stop pod "$RUNPOD_POD_ID" || runpodctl pod stop "$RUNPOD_POD_ID"
fi
