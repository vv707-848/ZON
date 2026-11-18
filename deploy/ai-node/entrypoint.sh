#!/usr/bin/env bash
# /workspace/entrypoint.sh — أمر إقلاع الـ Pod. البيئة كلها مثبّتة مسبقاً على القرص الدائم.
# قاعدة: لا تعليق في سطر ينتهي بعلامة الاستمرار — التعليق في سطر مستقل.
set -euo pipefail

# متغيّران يُضبطان في إعدادات الـ Pod — غيابهما يوقف الإقلاع برسالة صريحة
# ZON_PROFILE: ملف النموذج الذي تخدمه البطاقة — هو نفسه LLM_PROFILE في خدمة الوكلاء
: "${APP_NODE_IP:?}" "${LLM_API_KEY:?}" "${ZON_PROFILE:?}"

# مفتاح النفق وبصمة عقدة التطبيق: من القرص (install.sh، الخطوة ③)، أو من أسرار الـ Pod حين لا طرفية
# TUNNEL_KEY_B64: المفتاح الخاص بترميز base64 (سطر واحد) · APP_NODE_HOSTKEY: «ssh-ed25519 AAAA…» من عقدة التطبيق نفسها
if [ -n "${TUNNEL_KEY_B64:-}" ] && [ ! -f /workspace/keys/tunnel_ed25519 ]; then
  mkdir -p /workspace/keys
  ( umask 077; printf '%s' "$TUNNEL_KEY_B64" | base64 -d > /workspace/keys/tunnel_ed25519 )
fi
if [ -n "${APP_NODE_HOSTKEY:-}" ]; then
  printf '%s %s\n' "$APP_NODE_IP" "$APP_NODE_HOSTKEY" > /workspace/keys/known_hosts
fi

# بلا مفتاح على القرص ولا سرّ: يولّده الـ Pod على قرصه. ومع GH_TOKEN وLAB_ISSUE ينشر نصفه العام وحده (ليس سراً)
# في القضية مرة واحدة، فتضيفه عقدة التطبيق لمستخدم النفق (docs/deploy-runbook.md 2.6). الخاص لا يغادر القرص
mkdir -p /workspace/keys
[ -f /workspace/keys/tunnel_ed25519 ] || ssh-keygen -q -t ed25519 -N "" -f /workspace/keys/tunnel_ed25519 -C zon-ai-node
if [ -n "${GH_TOKEN:-}" ] && [ -n "${LAB_ISSUE:-}" ] && [ -f /workspace/keys/tunnel_ed25519.pub ] && [ ! -f /workspace/keys/.public-posted ]; then
  python3 - <<'PY' && touch /workspace/keys/.public-posted || echo "the public key was not posted — the tunnel waits for it" >&2
import json, os, urllib.request
public = open("/workspace/keys/tunnel_ed25519.pub", encoding="utf-8").read().strip()
request = urllib.request.Request(
    f"https://api.github.com/repos/vv707-848/ZON/issues/{os.environ['LAB_ISSUE']}/comments",
    data=json.dumps({"body": f"**مفتاح النفق العام لعقدة الذكاء** (`{os.environ.get('RUNPOD_POD_ID', '')}`):\n```\n{public}\n```"}).encode(),
    method="POST", headers={"Authorization": f"Bearer {os.environ['GH_TOKEN']}", "Accept": "application/vnd.github+json"})
print("posted:", urllib.request.urlopen(request, timeout=30).status)
PY
fi

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
