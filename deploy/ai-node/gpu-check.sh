#!/usr/bin/env bash
# /workspace/gpu-check.sh — on the card, before serving (prepare.sh calls it at every start; seconds). What an
# install on a Pod without a card cannot check, and what a container image can lack:
#   ① a C compiler that really compiles: Triton builds a shim during CUDA-graph capture, and on slim images a
#      stripped libisl breaks cc1 while `gcc --version` still answers — the failure shows ~40 s into the start, as a
#      gcc CalledProcessError in cuda_graph_runner.py (escha-runtime-qwen3dense card)
#   ② the card's driver: it must run CUDA 12.8 or later (the environments are torch cu128, and the Escha runtime asks
#      for it). RunPod places a Pod on hosts of several drivers: create it with allowedCudaVersions ≥ 12.8
#   ③ the provider's check: True True True, and the runtime's version (1.2.1 or later)
#   ④ SGLang's kernels load: a CUDA-12 build of sglang-kernel, put in place here if the install brought a CUDA-13 one
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
  # ④ SGLang's kernels as a CUDA-12 build. The runtime's wheel pulls sglang-kernel from PyPI, whose wheels are CUDA 13
  # builds: on torch cu128 the engine dies on its first import (libnvrtc.so.13, «Could not load any common_ops»,
  # issue #119). The same version from SGLang's cu129 index replaces it once, on the volume
  if ! "$W/venv-escha/bin/python" -c "import sgl_kernel" 2>"$W/sgl-kernel-import.err"; then
    kernel=$("$W/venv-escha/bin/python" -c "import importlib.metadata as m; print(m.version('sglang-kernel'))")
    echo "sglang-kernel $kernel does not load here ($(tail -n 1 "$W/sgl-kernel-import.err")) — installing its CUDA-12 build" >&2
    "$W/venv-escha/bin/pip" install -q --no-deps --force-reinstall "sglang-kernel==${kernel%%+*}" \
      --index-url https://sgl-project.github.io/whl/cu129/
    "$W/venv-escha/bin/python" -c "import sgl_kernel" || { echo "sglang-kernel still does not load on this card" >&2; exit 1; }
    "$W/venv-escha/bin/pip" freeze > "$W/venv-escha.lock"
  fi
  echo "sglang-kernel $("$W/venv-escha/bin/python" -c "import importlib.metadata as m; print(m.version('sglang-kernel'))") loads"
fi
