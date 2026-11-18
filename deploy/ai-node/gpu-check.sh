#!/usr/bin/env bash
# /workspace/gpu-check.sh — on the card, before serving (prepare.sh calls it at every start; seconds). What an
# install on a Pod without a card cannot check, and what a container image can lack:
#   ① a C compiler that really compiles: Triton builds a shim during CUDA-graph capture, and on slim images a
#      stripped libisl breaks cc1 while `gcc --version` still answers — the failure shows ~40 s into the start, as a
#      gcc CalledProcessError in cuda_graph_runner.py (escha-runtime-qwen3dense card)
#   ② the provider's check: True True True, and the runtime's version (1.2.1 or later)
set -euo pipefail
PROFILE=${ZON_PROFILE:?}
RUNTIME=$(sed -n 's/^runtime = "\(.*\)"/\1/p' "/workspace/profiles/$PROFILE.toml")

nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader

probe=$(mktemp -d)
printf '#include <Python.h>\nint main(void){return 0;}\n' > "$probe/t.c"
include=$(/workspace/venv-tools/bin/python -c "import sysconfig; print(sysconfig.get_paths()['include'])")
if ! gcc "$probe/t.c" -I"$include" -o "$probe/t" 2>"$probe/err" || ! "$probe/t"; then
  echo "the C compiler does not compile here — installing it" >&2
  cat "$probe/err" >&2
  apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq build-essential libisl23 >/dev/null
  gcc "$probe/t.c" -I"$include" -o "$probe/t" && "$probe/t"
fi
rm -rf "$probe"

if [ "$RUNTIME" = "escha" ]; then
  ok=$(/workspace/venv-escha/bin/python -c "import torch, escha, sglang; print(torch.cuda.is_available(), hasattr(torch.ops.escha, 'escham_decode_gemv'), bool(sglang.__version__))")
  echo "escha check: $ok · version $(/workspace/venv-escha/bin/python -c 'import escha; print(escha.__version__)')"
  [ "$ok" = "True True True" ] || { echo "the runtime does not run on this card" >&2; exit 1; }
fi
