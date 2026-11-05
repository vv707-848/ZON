#!/usr/bin/env bash
# /workspace/entrypoint.sh — أمر إقلاع الـ Pod. البيئة كلها مثبّتة مسبقاً على القرص الدائم.
# قاعدة: لا تعليق في سطر ينتهي بعلامة الاستمرار — التعليق في سطر مستقل.
set -euo pipefail

# متغيّران يُضبطان في إعدادات الـ Pod — غيابهما يوقف الإقلاع برسالة صريحة
# ZON_PROFILE: ملف النموذج الذي تخدمه البطاقة — هو نفسه LLM_PROFILE في خدمة الوكلاء
: "${APP_NODE_IP:?}" "${LLM_API_KEY:?}" "${ZON_PROFILE:?}"

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

# ③ النموذج: ملفه يحدد المحرك وخياراته (serve.py)، ويُخدَم دائماً باسم zon-ai على 127.0.0.1:30000
exec python3 /workspace/serve.py "$ZON_PROFILE"
