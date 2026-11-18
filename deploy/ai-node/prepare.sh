#!/usr/bin/env bash
# /workspace/prepare.sh — before a Pod serves: the repository at ZON_REF, the node's files where install.sh and serve.py
# look for them, and the profile installed once (a marker per profile on the persistent disk). The start of both Pods
# with no terminal: the measurement (lab-pod.sh) and production, whose start command is
#   bash -c "curl -fsSL https://raw.githubusercontent.com/vv707-848/ZON/<commit>/deploy/ai-node/prepare.sh -o /workspace/prepare.sh && bash /workspace/prepare.sh && exec bash /workspace/entrypoint.sh"
# Production pins ZON_REF to a commit: a restart never brings a change no one measured.
set -euo pipefail
: "${ZON_PROFILE:?}"
REF=${ZON_REF:-main}

# On a Pod without a card (ZON_NO_GPU=1, or no nvidia-smi) it installs and stops there: everything lands on the
# network volume, and the card's Pod then starts in seconds. On the card it also runs gpu-check.sh.

# ① the repository — public, read over HTTPS
if [ -d /workspace/zon/.git ]; then
  git -C /workspace/zon fetch -q origin "$REF"
  git -C /workspace/zon checkout -q FETCH_HEAD
else
  git clone -q https://github.com/vv707-848/ZON.git /workspace/zon
  git -C /workspace/zon checkout -q "$REF"
fi
echo "repository at $(git -C /workspace/zon rev-parse --short HEAD)"

# ② the node's files (deploy/README.md «الملفات»)
mkdir -p /workspace/profiles /workspace/embed
cp /workspace/zon/deploy/ai-node/{entrypoint.sh,serve.py,gpu-check.sh} /workspace/
cp /workspace/zon/deploy/ai-node/embed/server.py /workspace/embed/
cp /workspace/zon/agents/zon_agents/models/*.toml /workspace/profiles/

# ③ the profile's engine and weights, once
if [ ! -f "/workspace/.installed-$ZON_PROFILE" ]; then
  PROFILE="$ZON_PROFILE" bash /workspace/zon/deploy/ai-node/install.sh
  touch "/workspace/.installed-$ZON_PROFILE"
fi

# ④ on the card: what only the card can check
if [ "${ZON_NO_GPU:-0}" != "1" ] && command -v nvidia-smi >/dev/null; then
  ZON_PROFILE="$ZON_PROFILE" bash /workspace/gpu-check.sh
else
  echo "installed $ZON_PROFILE without a card — the card's Pod checks it before serving"
fi
