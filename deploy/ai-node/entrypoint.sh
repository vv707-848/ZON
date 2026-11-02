#!/usr/bin/env bash
# /workspace/entrypoint.sh — أمر إقلاع الـ Pod. البيئة كلها مثبّتة مسبقاً على القرص الدائم.
# قاعدة: لا تعليق في سطر ينتهي بعلامة الاستمرار — التعليق في سطر مستقل.
set -euo pipefail

# متغيّران يُضبطان في إعدادات الـ Pod — غيابهما يوقف الإقلاع برسالة صريحة
: "${APP_NODE_IP:?}" "${SGLANG_API_KEY:?}"

# ① النفق: البطاقة تفتح اتصالاً صادراً إلى عقدة التطبيق — لا منفذ وارد عليها
# التضمين يصل هناك على 18080 لأن 8080 منفذ Laravel Reverb
mkdir -p /root/.ssh
install -m 600 /workspace/keys/tunnel_ed25519 /root/.ssh/zon_tunnel
( set +e
  while true; do
    ssh -N -i /root/.ssh/zon_tunnel \
      -o ExitOnForwardFailure=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=3 \
      -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/workspace/keys/known_hosts \
      -R 127.0.0.1:30000:127.0.0.1:30000 \
      -R 127.0.0.1:18080:127.0.0.1:8080 \
      "zon-tunnel@${APP_NODE_IP}"
    sleep 5
  done ) &

# ② خادم التضمين أولاً — في بيئته المستقلة، ويُعاد تشغيله تلقائياً إن سقط
( set +e
  while true; do
    /workspace/venv-embed/bin/python /workspace/embed/server.py --host 127.0.0.1 --port 8080
    sleep 3
  done ) &

# انتظار جاهزيته قبل رفع النموذج — يمنع سباق تحميل الذاكرة · والحد 5 دقائق
for _ in $(seq 1 150); do
  curl -sf http://127.0.0.1:8080/health >/dev/null && break
  sleep 2
done
curl -sf http://127.0.0.1:8080/health >/dev/null || { echo "embedding server not ready" >&2; exit 1; }

# ③ النموذج في الواجهة — سكربت المزوّد الرسمي بمتغيرات فقط
export VENV=/workspace/venv
export MODEL=/workspace/models/Qwen3.6-35B-A3B-Escha-W2
export TRITON_CACHE_DIR=/workspace/cache/triton
export SERVED_NAME=zon-ai HOST=127.0.0.1 PORT=30000 API_KEY="$SGLANG_API_KEY"
export CTXLEN=32768 MAXREQ=8 MAXMAMBA=8 RADIX=0
export GRAPHS=1 CUDA_GRAPH_BS="1 2 4 8" THINK=0 INT8=on MEM=0.75
exec bash /workspace/escha/sglang/serve.sh
