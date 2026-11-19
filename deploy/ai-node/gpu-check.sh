#!/usr/bin/env bash
# /workspace/gpu-check.sh — on the card, before serving (prepare.sh calls it at every start; seconds). What an
# install on a Pod without a card cannot check, and what a container image can lack:
#   ① a C compiler that really compiles: Triton builds a shim during CUDA-graph capture, and on slim images a
#      stripped libisl breaks cc1 while `gcc --version` still answers — the failure shows ~40 s into the start, as a
#      gcc CalledProcessError in cuda_graph_runner.py (escha-runtime-qwen3dense card)
#   ② the card's driver: it must run CUDA 12.8 or later (the environments are torch cu128, and the Escha runtime asks
#      for it). RunPod places a Pod on hosts of several drivers: create it with allowedCudaVersions ≥ 12.8
#   ③ the provider's check: True True True, and the runtime's version (1.2.1 or later)
#   ④ torch 2.11.0 in the runtime's environment, put back here if the install's dependencies moved it
#   ⑤ SGLang's kernels load: sglang-kernel 0.4.4 for CUDA 12, put in place here if the install brought another
set -euo pipefail
PROFILE=${ZON_PROFILE:?}
W=${ZON_WORKSPACE:-/workspace}                       # another folder for the tests only (_tools/tests/test_ai_node.py)
RUNTIME=$(sed -n 's/^runtime = "\(.*\)"/\1/p' "$W/profiles/$PROFILE.toml")
NEED_CUDA=12.8
ESCHA_TORCH=2.11.0 ESCHA_TORCHVISION=0.26.0         # ④
ESCHA_KERNEL=0.4.4                                   # ⑤

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
  # ④ torch 2.11.0, the one upstream SGLang 0.5.15.post1 (the runtime's) is paired with. The runtime's INSTALL.md
  # says 2.9.x, but its own code says it runs on a stable ABI and asks for sglang-kernel>=0.4.4, which links torch
  # symbols 2.9 lacks; and its dependencies moved torch to 2.10.0 (issue #119). Put back once, on the volume, with the
  # torchvision built for it
  torch_now=$("$W/venv-escha/bin/python" -c "import torch; print(torch.__version__)")
  case "$torch_now" in
    "$ESCHA_TORCH"+*|"$ESCHA_TORCH") ;;
    *) echo "torch $torch_now in the runtime's environment — installing $ESCHA_TORCH" >&2
       "$W/venv-escha/bin/pip" install -q "torch==$ESCHA_TORCH" "torchvision==$ESCHA_TORCHVISION" \
         --index-url https://download.pytorch.org/whl/cu128
       "$W/venv-escha/bin/pip" freeze > "$W/venv-escha.lock" ;;
  esac
  ok=$("$W/venv-escha/bin/python" -c "import torch, escha, sglang; print(torch.cuda.is_available(), hasattr(torch.ops.escha, 'escham_decode_gemv'), bool(sglang.__version__))")
  echo "escha check: $ok · version $("$W/venv-escha/bin/python" -c 'import escha; print(escha.__version__)')"
  [ "$ok" = "True True True" ] || { echo "the runtime does not run on this card" >&2; exit 1; }
  # ⑤ SGLang's kernels: 0.4.4, the floor the runtime's SGLang asks for and upstream's pair for torch 2.11, as the CUDA-12
  # build. PyPI's wheels are CUDA 13 builds (libnvrtc.so.13), and pip takes the newest (0.4.6.post1, built for torch
  # 2.13). Checked on the A40: torch 2.11.0+cu128, escha True True True, sgl_kernel 0.4.4+cu129 runs (issue #119).
  # It replaces the other once, on the volume; still failing, the engine is not started
  if ! "$W/venv-escha/bin/python" -c "import sgl_kernel" 2>"$W/sgl-kernel-import.err"; then
    kernel=$("$W/venv-escha/bin/python" -c "import importlib.metadata as m; print(m.version('sglang-kernel'))")
    echo "sglang-kernel $kernel does not load here ($(grep -m1 -o 'undefined symbol.*\|lib[a-z]*\.so\.[0-9]*: cannot open[^;]*' "$W/sgl-kernel-import.err" || tail -n 1 "$W/sgl-kernel-import.err")) — installing $ESCHA_KERNEL for CUDA 12" >&2
    "$W/venv-escha/bin/pip" install -q --no-deps --force-reinstall "sglang-kernel==$ESCHA_KERNEL" \
      --index-url https://sgl-project.github.io/whl/cu129/
    "$W/venv-escha/bin/python" -c "import sgl_kernel" || { echo "sglang-kernel still does not load on this card" >&2; exit 1; }
    "$W/venv-escha/bin/pip" freeze > "$W/venv-escha.lock"
  fi
  echo "sglang-kernel $("$W/venv-escha/bin/python" -c "import importlib.metadata as m; print(m.version('sglang-kernel'))") loads"
fi
