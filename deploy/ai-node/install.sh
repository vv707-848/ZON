#!/usr/bin/env bash
# AI node (RunPod) — ONE-TIME install on the persistent disk /workspace (architecture §7). Run line by line from the
# Pod's terminal; nothing is installed at boot. The model is a profile (agents/zon_agents/models/<name>.toml): step ②
# installs its engine and downloads its weights, and is repeated for every profile tried (the model lab).
set -eo pipefail   # no -u: the venv activate script reads unset variables

# من طرفية الـ Pod — كل ما يُثبَّت يُكتب على /workspace فلا يُعاد مع الإقلاع
# ويُسجَّل في دفتر التشغيل رقم الإصدار (commit) لكل تنزيل من HuggingFace
# إن ظهر خطأ ensurepip: apt-get update && apt-get install -y python3.12-venv
# صورة RunPod تفعّل hf_transfer على مستوى النظام وهو غير مثبّت داخل هذه البيئات — يُطفأ للتنزيل
export HF_HUB_ENABLE_HF_TRANSFER=0

# ① المشترك: ملفات المستودع، وأداة التنزيل، وخادم التضمين ببيئته المستقلة حتى لا تمس حزمه بيئة النموذج
# ينسخ من المستودع: deploy/ai-node/{entrypoint.sh,serve.py,embed/server.py} وملفات agents/zon_agents/models/*.toml
mkdir -p /workspace/embed /workspace/profiles /workspace/models /workspace/keys /workspace/cache/triton
python3.12 -m venv /workspace/venv-tools
/workspace/venv-tools/bin/pip install -U pip "huggingface_hub[cli]"
python3.12 -m venv /workspace/venv-embed
/workspace/venv-embed/bin/pip install "torch==2.9.*" --index-url https://download.pytorch.org/whl/cu128
/workspace/venv-embed/bin/pip install FlagEmbedding==1.4.2 fastapi uvicorn
/workspace/venv-embed/bin/pip freeze > /workspace/venv-embed.lock
/workspace/venv-tools/bin/hf download BAAI/bge-m3 --local-dir /workspace/models/bge-m3 --exclude "onnx/*"

# ② ملف نموذج واحد: محركه ثم أوزانه — يُكرَّر لكل ملف يُجرَّب، والمحرك يُثبَّت مرة لكل نوع
PROFILE=qwen3.6-35b-a3b-escha-w2
RUNTIME=$(sed -n 's/^runtime = "\(.*\)"/\1/p' "/workspace/profiles/$PROFILE.toml")
echo "$PROFILE → $RUNTIME"

# المحرك مرة لكل نوع: vllm وsglang الرسميان، أو وقت تشغيل Escha
case "$RUNTIME" in
  vllm)
    # عجلة vLLM تجلب torch المطابقة لها
    python3.12 -m venv /workspace/venv-vllm
    /workspace/venv-vllm/bin/pip install -U pip "vllm==0.33.0"
    /workspace/venv-vllm/bin/pip freeze > /workspace/venv-vllm.lock
    ;;
  sglang)
    python3.12 -m venv /workspace/venv-sglang
    /workspace/venv-sglang/bin/pip install -U pip "sglang==0.5.23"
    /workspace/venv-sglang/bin/pip freeze > /workspace/venv-sglang.lock
    ;;
  escha)
    # torch أولاً وبالإصدار 2.9.x: نواة escha مربوطة بـ libtorch 2.9، وأي torch أحدث يُسقط الاستيراد
    python3.12 -m venv /workspace/venv-escha
    /workspace/venv-escha/bin/pip install -U pip wheel
    /workspace/venv-escha/bin/pip install "torch==2.9.*" --index-url https://download.pytorch.org/whl/cu128
    /workspace/venv-tools/bin/hf download EschaLabs/escha-runtime-qwen3moe --include "sglang/*" --local-dir /workspace/escha
    /workspace/venv-escha/bin/pip install /workspace/escha/sglang/escha-*.whl
    # أقل من 5.8 يعمل النموذج بإعدادات خاطئة: كلام مرتّب ومعنى فاسد وتحذير واحد في السجل
    /workspace/venv-escha/bin/pip install "transformers>=5.8"
    # تحقق المزوّد — الناتج المقبول الوحيد: True True True
    /workspace/venv-escha/bin/python -c "import torch, escha, sglang; print(torch.cuda.is_available(), hasattr(torch.ops.escha, 'escham_moe_linear'), bool(sglang.__version__))"
    /workspace/venv-escha/bin/pip freeze > /workspace/venv-escha.lock
    ;;
esac

# الأوزان: مجلد لكل مستودع، فملفّان بالأوزان نفسها (محرّكان) يتشاركان تنزيلاً واحداً
read -r REPO DIR < <(python3 /workspace/serve.py "$PROFILE" --weights)
/workspace/venv-tools/bin/hf download "$REPO" --local-dir "$DIR"
# أمر التشغيل كما سيُنفَّذ — بلا المفتاح
python3 /workspace/serve.py "$PROFILE" --print

# ③ مفتاح النفق — يُولَّد مرة، ويُضاف مفتاحه العام في عقدة التطبيق (القسم ١٣)
ssh-keygen -t ed25519 -N "" -f /workspace/keys/tunnel_ed25519 -C zon-ai-node
# APP_NODE_IP مضبوط في إعدادات الـ Pod · والبصمة تُطابَق مع بصمة الخادم الفعلية قبل الاعتماد
ssh-keyscan -t ed25519 "$APP_NODE_IP" > /workspace/keys/known_hosts
