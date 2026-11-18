#!/usr/bin/env bash
# /workspace/prepare.sh — before a Pod serves: the repository at ZON_REF, the node's files where install.sh and serve.py
# look for them, and the profile installed once (a marker per profile on the persistent disk). The start of both Pods
# with no terminal: the measurement (lab-pod.sh) and production, whose start command is
#   bash -c "curl -fsSL https://raw.githubusercontent.com/vv707-848/ZON/<commit>/deploy/ai-node/prepare.sh -o /workspace/prepare.sh && bash /workspace/prepare.sh && exec bash /workspace/entrypoint.sh"
# Production pins ZON_REF to a commit: a restart never brings a change no one measured.
set -euo pipefail
: "${ZON_PROFILE:?}"
REF=${ZON_REF:-main}

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
cp /workspace/zon/deploy/ai-node/{entrypoint.sh,serve.py} /workspace/
cp /workspace/zon/deploy/ai-node/embed/server.py /workspace/embed/
cp /workspace/zon/agents/zon_agents/models/*.toml /workspace/profiles/

# ③ the profile's engine and weights, once
if [ ! -f "/workspace/.installed-$ZON_PROFILE" ]; then
  PROFILE="$ZON_PROFILE" bash /workspace/zon/deploy/ai-node/install.sh
  touch "/workspace/.installed-$ZON_PROFILE"
fi
