#!/usr/bin/env bash
# AI node (RunPod, A40) — ONE-TIME install on the persistent disk /workspace (architecture §7). Run line by line
# from the Pod's terminal; nothing is installed at boot. Then copy entrypoint.sh and embed/server.py to /workspace.
set -eo pipefail   # no -u: the venv activate script reads unset variables

# من طرفية الـ Pod — كل ما يُثبَّت يُكتب على /workspace فلا يُعاد مع الإقلاع
# ويُسجَّل في دفتر التشغيل رقم الإصدار (commit) لكل تنزيل من HuggingFace
# إن ظهر خطأ ensurepip: apt-get update && apt-get install -y python3.12-venv
# صورة RunPod تفعّل hf_transfer على مستوى النظام وهو غير مثبّت داخل هذه البيئات — يُطفأ للتنزيل
export HF_HUB_ENABLE_HF_TRANSFER=0
python3.12 -m venv /workspace/venv
source /workspace/venv/bin/activate
pip install -U pip wheel "huggingface_hub[cli]"
# torch أولاً وبالإصدار 2.9.x — نواة escha مربوطة بـ libtorch 2.9، وأي torch أحدث يُسقط الاستيراد بخطأ undefined symbol
pip install "torch==2.9.*" --index-url https://download.pytorch.org/whl/cu128
hf download EschaLabs/escha-runtime-qwen3moe --include "sglang/*" --local-dir /workspace/escha
pip install /workspace/escha/sglang/escha-*.whl
# أقل من 5.8 يعمل النموذج بإعدادات خاطئة: كلام مرتّب ومعنى فاسد وتحذير واحد في السجل
pip install "transformers>=5.8"
hf download EschaLabs/Qwen3.6-35B-A3B-Escha-W2 --local-dir /workspace/models/Qwen3.6-35B-A3B-Escha-W2
hf download BAAI/bge-m3 --local-dir /workspace/models/bge-m3 --exclude "onnx/*"
# تحقق المزوّد — الناتج المقبول الوحيد: True True True
python -c "import torch, escha, sglang; print(torch.cuda.is_available(), hasattr(torch.ops.escha, 'escham_moe_linear'), bool(sglang.__version__))"
# تجميد الإصدارات كما اجتازت التحقق — أي إعادة تثبيت تكون من هذا الملف وحده
pip freeze > /workspace/venv.lock
deactivate

# بيئة التضمين مستقلة — حتى لا تمس حزمها بيئة النموذج
python3.12 -m venv /workspace/venv-embed
/workspace/venv-embed/bin/pip install "torch==2.9.*" --index-url https://download.pytorch.org/whl/cu128
/workspace/venv-embed/bin/pip install FlagEmbedding==1.4.2 fastapi uvicorn
/workspace/venv-embed/bin/pip freeze > /workspace/venv-embed.lock

# مفتاح النفق — يُولَّد مرة، ويُضاف مفتاحه العام في عقدة التطبيق (القسم ١٣)
mkdir -p /workspace/keys /workspace/embed /workspace/cache/triton
ssh-keygen -t ed25519 -N "" -f /workspace/keys/tunnel_ed25519 -C zon-ai-node
# APP_NODE_IP مضبوط في إعدادات الـ Pod · والبصمة تُطابَق مع بصمة الخادم الفعلية قبل الاعتماد
ssh-keyscan -t ed25519 "$APP_NODE_IP" > /workspace/keys/known_hosts
