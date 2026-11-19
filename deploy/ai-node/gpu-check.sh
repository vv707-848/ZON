#!/usr/bin/env bash
# /workspace/gpu-check.sh — on the card, before serving (prepare.sh calls it at every start; seconds). What an
# install on a Pod without a card cannot check, and what a container image can lack:
#   ① a C compiler that really compiles: Triton builds a shim during CUDA-graph capture, and on slim images a
#      stripped libisl breaks cc1 while `gcc --version` still answers — the failure shows ~40 s into the start, as a
#      gcc CalledProcessError in cuda_graph_runner.py (escha-runtime-qwen3dense card)
#   ② the card's driver: it must run CUDA 12.8 or later (the environments are torch cu128, and the Escha runtime asks
#      for it). RunPod places a Pod on hosts of several drivers: create it with allowedCudaVersions ≥ 12.8
#   ③ the provider's check: True True True, and the runtime's version (1.2.1 or later)
set -euo pipefail
PROFILE=${ZON_PROFILE:?}
W=${ZON_WORKSPACE:-/workspace}                       # another folder for the tests only (_tools/tests/test_ai_node.py)
RUNTIME=$(sed -n 's/^runtime = "\(.*\)"/\1/p' "$W/profiles/$PROFILE.toml")
NEED_CUDA=12.8

nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader
cuda=$(nvidia-smi | sed -n 's/.*CUDA Version: *\([0-9][0-9.]*\).*/\1/p' | head -n 1)
if [ -z "$cuda" ] || [ "$(printf '%s\n%s\n' "$NEED_CUDA" "$cuda" | sort -V | head -n 1)" != "$NEED_CUDA" ]; then
  echo "the card's driver runs CUDA ${cuda:-?}, below $NEED_CUDA: create the Pod again with allowedCudaVersions ≥ $NEED_CUDA" >&2
  exit 1
fi
echo "driver CUDA $cuda (≥ $NEED_CUDA)"

probe=$(mktemp -d)
printf '#include <Python.h>\nint main(void){return 0;}\n' > "$probe/t.c"
include=$("$W/venv-tools/bin/python" -c "import sysconfig; print(sysconfig.get_paths()['include'])")
if ! gcc "$probe/t.c" -I"$include" -o "$probe/t" 2>"$probe/err" || ! "$probe/t"; then
  echo "the C compiler does not compile here — installing it" >&2
  cat "$probe/err" >&2
  apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq build-essential libisl23 >/dev/null
  gcc "$probe/t.c" -I"$include" -o "$probe/t" && "$probe/t"
fi
rm -rf "$probe"

if [ "$RUNTIME" = "escha" ]; then
  ok=$("$W/venv-escha/bin/python" -c "import torch, escha, sglang; print(torch.cuda.is_available(), hasattr(torch.ops.escha, 'escham_decode_gemv'), bool(sglang.__version__))")
  echo "escha check: $ok · version $("$W/venv-escha/bin/python" -c 'import escha; print(escha.__version__)')"
  [ "$ok" = "True True True" ] || { echo "the runtime does not run on this card" >&2; exit 1; }
fi
