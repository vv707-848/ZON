#!/usr/bin/env bash
# AI node (RunPod) — ONE-TIME install on the persistent disk /workspace (architecture §7). Run line by line from the
# Pod's terminal, or whole (lab-pod.sh does, with PROFILE set); nothing is installed at boot. The model is a profile
# (agents/zon_agents/models/<name>.toml): step ② installs its engine and downloads its weights, and is repeated for
# every profile tried (the model lab).
set -eo pipefail   # no -u: the venv activate script reads unset variables

# من طرفية الـ Pod — كل ما يُثبَّت يُكتب على /workspace فلا يُعاد مع الإقلاع
# ويُسجَّل في دفتر التشغيل رقم الإصدار (commit) لكل تنزيل من HuggingFace
# إن ظهر خطأ ensurepip: apt-get update && apt-get install -y python3.12-venv
# صورة RunPod تفعّل hf_transfer على مستوى النظام وهو غير مثبّت داخل هذه البيئات — يُطفأ للتنزيل
export HF_HUB_ENABLE_HF_TRANSFER=0

# Python 3.12 لكل البيئات (شرط وقت تشغيل Escha) من uv، محفوظاً على القرص الدائم دائماً لا من الصورة: البيئات
# مبنية عليه، فتعمل على أي Pod يركب القرص — ومنها Pod بلا بطاقة يُثبَّت عليه كل شيء بكلفة أقل، ثم Pod البطاقة.
# وفيه ترويسات Python التي يترجم بها Triton
export UV_PYTHON_INSTALL_DIR=/workspace/python
python3 -m pip install -q uv
python3 -m uv python install 3.12
PY=$(python3 -m uv python find 3.12)

# ① المشترك: ملفات المستودع، وأداة التنزيل، وخادم التضمين ببيئته المستقلة حتى لا تمس حزمه بيئة النموذج
# ينسخ من المستودع: deploy/ai-node/{entrypoint.sh,serve.py,embed/server.py} وملفات agents/zon_agents/models/*.toml
mkdir -p /workspace/embed /workspace/profiles /workspace/models /workspace/keys /workspace/cache/triton
"$PY" -m venv /workspace/venv-tools
/workspace/venv-tools/bin/pip install -U pip "huggingface_hub[cli]" uv
"$PY" -m venv /workspace/venv-embed
/workspace/venv-embed/bin/pip install "torch==2.9.*" --index-url https://download.pytorch.org/whl/cu128
/workspace/venv-embed/bin/pip install FlagEmbedding==1.4.2 fastapi uvicorn
/workspace/venv-embed/bin/pip freeze > /workspace/venv-embed.lock
/workspace/venv-tools/bin/hf download BAAI/bge-m3 --local-dir /workspace/models/bge-m3 --exclude "onnx/*"

# ② ملف نموذج واحد: محركه ثم أوزانه — يُكرَّر لكل ملف يُجرَّب، والمحرك يُثبَّت مرة لكل نوع
PROFILE=${PROFILE:-qwen3.8-27b-escha-w2}
RUNTIME=$(sed -n 's/^runtime = "\(.*\)"/\1/p' "/workspace/profiles/$PROFILE.toml")
echo "$PROFILE → $RUNTIME"

# المحرك مرة لكل نوع: vllm وsglang الرسميان، أو وقت تشغيل Escha
case "$RUNTIME" in
  vllm)
    # عجلة vLLM تجلب torch المطابقة لها
    "$PY" -m venv /workspace/venv-vllm
    /workspace/venv-vllm/bin/pip install -U pip "vllm==0.33.0"
    /workspace/venv-vllm/bin/pip freeze > /workspace/venv-vllm.lock
    ;;
  sglang)
    "$PY" -m venv /workspace/venv-sglang
    /workspace/venv-sglang/bin/pip install -U pip "sglang==0.5.23"
    /workspace/venv-sglang/bin/pip freeze > /workspace/venv-sglang.lock
    ;;
  escha)
    # torch أولاً: 2.11.0 مع torchvision المبني له، وهو ما تقرنه SGLang 0.5.15.post1 (نسخة العجلة) بـ sglang-kernel 0.4.4.
    # «torch 2.9.x» في INSTALL.md قديم: كود العجلة نفسه يقول إن وقت التشغيل بواجهة ABI مستقرة، ويطلب نواة 0.4.4 فما فوق،
    # وكلها مربوطة بـ torch أحدث من 2.9 (القضية #119، gpu-check.sh ④ و⑤)
    "$PY" -m venv /workspace/venv-escha
    /workspace/venv-escha/bin/pip install -U pip wheel
    /workspace/venv-escha/bin/pip install "torch==2.11.0" "torchvision==0.26.0" --index-url https://download.pytorch.org/whl/cu128
    # وقت تشغيل النماذج الكثيفة (Qwen3.8-27B). لا يُثبَّت معه sglang من PyPI: العجلة تحمل نسختها منه
    /workspace/venv-tools/bin/hf download EschaLabs/escha-runtime-qwen3dense --include "sglang/*" --local-dir /workspace/escha
    /workspace/venv-escha/bin/pip install /workspace/escha/sglang/escha-*.whl
    # تبعيات العجلة قد تحرّك torch (أخذت 2.10.0 مرة)، فيُثبَّت ثانية
    /workspace/venv-escha/bin/pip install "torch==2.11.0" "torchvision==0.26.0" --index-url https://download.pytorch.org/whl/cu128
    # النواة 0.4.4 لـ CUDA 12: عجلات PyPI منها لـ CUDA 13 (libnvrtc.so.13) فلا تعمل على torch cu128
    /workspace/venv-escha/bin/pip install --no-deps --force-reinstall "sglang-kernel==0.4.4" --index-url https://sgl-project.github.io/whl/cu129/
    # أقل من 5.8 يعمل النموذج بإعدادات خاطئة: كلام مرتّب ومعنى فاسد وتحذير واحد في السجل
    /workspace/venv-escha/bin/pip install "transformers>=5.8"
    # تحقق المزوّد (True True True) يحتاج البطاقة: يجريه prepare.sh على Pod البطاقة قبل الخدمة (gpu_check)
    # serve.py يسلّم السكربت هذه المتغيرات: إن لم يقرأ أحدها خُدم النموذج باسم أو منفذ أو مفتاح غير المطلوب، فيتوقف التثبيت هنا
    for name in MODEL SERVED_NAME HOST PORT API_KEY; do
      grep -q "\b$name\b" /workspace/escha/sglang/serve.sh || { echo "serve.sh does not read $name" >&2; exit 1; }
    done
    /workspace/venv-escha/bin/pip freeze > /workspace/venv-escha.lock
    ;;
esac

# الأوزان: مجلد لكل مستودع، فملفّان بالأوزان نفسها (محرّكان) يتشاركان تنزيلاً واحداً
read -r REPO DIR < <(python3 /workspace/serve.py "$PROFILE" --weights)
/workspace/venv-tools/bin/hf download "$REPO" --local-dir "$DIR"
# أمر التشغيل كما سيُنفَّذ — بلا المفتاح
python3 /workspace/serve.py "$PROFILE" --print

# ③ مفتاح النفق — لـ Pod الإنتاج وحده (Pod القياس بلا APP_NODE_IP يتخطاه). يُولَّد مرة، ويُضاف مفتاحه العام في
# عقدة التطبيق (القسم ١٣)
if [ -n "${APP_NODE_IP:-}" ]; then
  [ -f /workspace/keys/tunnel_ed25519 ] || ssh-keygen -t ed25519 -N "" -f /workspace/keys/tunnel_ed25519 -C zon-ai-node
  # البصمة تُطابَق مع بصمة الخادم الفعلية قبل الاعتماد
  ssh-keyscan -t ed25519 "$APP_NODE_IP" > /workspace/keys/known_hosts
fi
