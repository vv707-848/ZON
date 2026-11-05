# النشر — ما يعمل أين وبأي ترتيب

المرجع: وثيقة المعمارية v1.4، الأقسام ٦ و٧ و١٣ و١٧.

## العُقد الثلاث

| العقدة | ما عليها |
|---|---|
| **التطبيق — جدة** (Oracle · ‏10.10.0.10) | Nginx · ‏PHP-FPM + Laravel (‏`/var/www/zon/backend`) · العمّال وReverb وخدمة الوكلاء عبر Supervisor · ‏Dragonfly · ‏MinIO |
| **البيانات — جدة** (Oracle · ‏10.10.0.20) | MySQL 9.7 LTS · ‏Qdrant 1.20 — بلا منفذ عام |
| **الذكاء — RunPod** (A40 · Pod ثابت) | محرك النموذج حسب ملفه (vLLM أو SGLang أو Escha) · خادم التضمين bge-m3 — بلا منفذ وارد، ونفق SSH صادر منها إلى عقدة التطبيق |

## الملفات

| الملف | يُنسخ إلى | العقدة |
|---|---|---|
| `nginx/zon.conf` | `/etc/nginx/sites-available/zon.conf`. يتطلب Nginx 1.30 من مستودع nginx.org (نسخة Ubuntu 1.24 لا تعرف `http2 on;`)، وشهادتين: `api.zon.sa`، و`zon.sa` مع `www.zon.sa` | التطبيق |
| `php/zon.ini` | `/etc/php/8.5/fpm/conf.d/99-zon.ini` و`/etc/php/8.5/cli/conf.d/99-zon.ini`، ثم `systemctl restart php8.5-fpm` | التطبيق |
| `supervisor/zon.conf` | `/etc/supervisor/conf.d/zon.conf` | التطبيق |
| `cron/zon` | `/etc/cron.d/zon` | التطبيق |
| `logrotate/zon` | `/etc/logrotate.d/zon` | التطبيق |
| `ssh/sshd_config.zon` | ثلاثة أجزاء في `/etc/ssh/sshd_config` بالترتيب المكتوب داخله | التطبيق |
| `mysql/zon.cnf` | `/etc/mysql/mysql.conf.d/zon.cnf` | البيانات |
| `mysql/users.sql` | يُنفَّذ مرة واحدة | البيانات |
| `qdrant/config.yaml` | `/etc/qdrant/config.yaml` | البيانات |
| `backup/zon-backup.sh` · `backup/cron` · `backup/backup.cnf.example` | `/usr/local/bin/` · `/etc/cron.d/zon-backup` · `/etc/zon/backup.cnf` | البيانات |
| `ai-node/install.sh` | يُشغَّل مرة من طرفية الـ Pod | الذكاء |
| `ai-node/entrypoint.sh` · `ai-node/serve.py` · `ai-node/embed/server.py` | `/workspace/entrypoint.sh` · `/workspace/serve.py` · `/workspace/embed/server.py` | الذكاء |
| `agents/zon_agents/models/*.toml` (ملفات النماذج) | `/workspace/profiles/` | الذكاء |

## إعدادات الـ Pod

ثلاثة متغيرات، وغياب أي منها يوقف الإقلاع برسالة صريحة:

| المتغير | القيمة |
|---|---|
| `APP_NODE_IP` | عنوان عقدة التطبيق (النفق) |
| `LLM_API_KEY` | مفتاح المحرك — القيمة نفسها في `agents/.env` |
| `ZON_PROFILE` | ملف النموذج الذي تخدمه البطاقة — الاسم نفسه في `LLM_PROFILE` بخدمة الوكلاء |

تبديل النموذج: تثبيت ملفه بالخطوة ② من `install.sh`، ثم تغيير `ZON_PROFILE` و`LLM_PROFILE` معاً، ثم إعادة تشغيل الـ Pod وخدمة الوكلاء.

## ترتيب الإقلاع

لا تنتقل إلى الخطوة التالية قبل أن ينجح تحقق الخطوة الحالية. الإقلاع بترتيب مختلف يجعل طبقة الوكلاء تفشل بصمت، فتظهر النتائج بالمسار البديل ويختفي العطل.

| # | الخطوة | التحقق قبل التالية |
|---|---|---|
| 1 | MySQL + Qdrant | الاتصال ينجح من عقدة التطبيق فقط |
| 2 | أمر بدء الـ Pod: `bash /workspace/entrypoint.sh` | يفتح النفق، ثم يرفع التضمين، ثم النموذج |
| 3 | النفق | `ss -ltn` على عقدة التطبيق يُظهر `127.0.0.1:30000` و`127.0.0.1:18080` |
| 4 | خادم التضمين bge-m3 | يرجع 3 متجهات لنص تجريبي عبر `127.0.0.1:18080` |
| 5 | محرك النموذج (`ZON_PROFILE`) | `curl /v1/models` بالمفتاح على `127.0.0.1:30000` يعرض `zon-ai` |
| 6 | طبقة الوكلاء | `GET http://127.0.0.1:8100/agents/health` |
| 7 | Laravel + عمّال الطوابير | طابور الفهرسة يستهلك المهام، و`php artisan zon:doctor` بلا عطل |
| 8 | Nginx | الشهادة صالحة، وطلب تجريبي ناجح |

## تحقق أمني قبل أي إطلاق

1. في إعدادات الـ Pod: قائمتا Expose HTTP Ports وExpose TCP Ports فارغتان، ونافذة Connect لا تعرض أي خدمة.
2. داخل الـ Pod: `ss -ltnp` يُظهر 30000 و8080 على `127.0.0.1` فقط.
3. من جهاز خارجي:
   ```bash
   curl -s -o /dev/null -w '%{http_code}' https://<POD_ID>-30000.proxy.runpod.net/v1/models
   ```
   المقبول أي رمز غير 200. والتحقق نفسه للمنفذ 8080.
4. على عقدة التطبيق: `ss -ltn` يُظهر 30000 و18080 على `127.0.0.1` فقط، لا على `0.0.0.0`.

ثلاث مخالفات تُبطل الأمان كله:

- تشغيل خادم النموذج على `0.0.0.0`.
- منح `ai_reader` صلاحية على الجداول الأصلية.
- مناداة طبقة الوكلاء من أي موضع في الكود دون المرور بصنف الإخفاء.

## السجلات ورقم التتبّع

رقم التتبع يظهر للمستخدم مع كل خطأ، ويصل في ترويسة `X-Request-Id` لكل رد. الرقم نفسه موجود في المواضع التالية:

| الموضع | المكان |
|---|---|
| سطر nginx | `trace=…` في `/var/log/nginx/zon-api.log` |
| سجلات Laravel | `storage/logs` |
| سطر خدمة الوكلاء | `request_id` في `/var/log/zon/agents.log` |
| لوحة الإدارة | صفحة «تتبّع طلب» |
