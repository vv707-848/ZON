# دليل الرفع: عقدة الذكاء (RunPod) وسيرفرا جدة (Oracle)

هذا ما يمشي عليه Claude حرفياً عند الرفع، بالترتيب. لا تُتخطى خطوة قبل أن ينجح تحققها.

- ما تعمل كل عقدة: [`deploy/README.md`](../deploy/README.md).
- ما بعد الإقلاع: [`operations.md`](operations.md).
- القياس: [`model-lab.md`](model-lab.md).
- الإصدار: 18 نوفمبر 2026، ومعه خطة الإطلاق v2.2 (المحطتان 0 و2).

## 0. قواعد لا تُكسر

1. **لا سرّ في محادثة ولا ملف ولا commit ولا سجل.** المستودع عام.
   - **أين تُحفظ الأسرار:**
     - أسرار RunPod.
     - أسرار GitHub (`vv707-848/ZON` ← Settings ← Secrets).
     - ملفات `/etc/zon/*` و`.env` على العقد، بصلاحية 600.
   - **إن ظهر سرّ في محادثة:** يُعدّ مكشوفاً ويُبدَّل فوراً.
2. **ما يحتاج دخولاً باسم صاحب المشروع أو دفعاً يفعله هو:**
   - فتح الحسابات وشحن الرصيد.
   - إنشاء الأسرار.
   - ربط الموصلات.
   - Claude يقول له بالضبط ماذا يضع وأين، ولا يطلب القيمة.
3. **قنوات التنفيذ:**

   | العقدة | كيف يصلها Claude |
   |---|---|
   | RunPod | موصل RunPod (MCP). ينشئ الـ Pod ويوقفه ولا يفتح طرفيته. لذلك لكل Pod أمر بدء يكفي وحده، والنتائج تصل تعليقاً في قضية على GitHub |
   | Oracle | بيئة Claude لا تصل إلى عناوين Oracle، فشبكتها لا تسمح إلا بـ GitHub وسجلات الحزم. التنفيذ عبر workflow في المستودع، اسمه `ops.yml`، يُكتب في بداية المحطة 2 ويُراجع قبل أول استعمال. يتصل بالعقدة بمفتاح SSH من سر GitHub `ORACLE_SSH_KEY`، ولا يشغّل إلا سكربتاً مدموجاً في `deploy/provision/`، أبداً أمراً حراً. سجله يقرؤه Claude |
   | البديل إن تعطل `ops.yml` | صاحب المشروع يلصق في Cloud Shell بلوحة Oracle أمراً واحداً يجلب السكربت المدموج نفسه ويشغّله |

4. **كل أمر هنا له تحقق.** أمر بلا تحقق ناجح لا يُعدّ منفذاً.
5. **لا بيانات وهمية في الإنتاج.**
   - لا `DemoSeeder` (يعمل في `local` وحده).
   - لا حساب اختبار يظهر للناس.
   - لا محاكاة مسموحة يوم الإطلاق العام، إلا بقرار مكتوب من صاحب المشروع (`docs/integrations.md`).

## 1. ما يجهزه صاحب المشروع قبل كل جزء

| متى | ماذا | أين يُحفظ |
|---|---|---|
| المحطة 0 | سرّ `LLM_API_KEY` | أسرار RunPod. القيمة: نص عشوائي من 64 خانة يولّده مدير كلمات السر |
| المحطة 0 | سرّ `GH_TOKEN` | أسرار RunPod. رمز GitHub دقيق الصلاحية: **Issues: Read and write** على `vv707-848/ZON` وحده، وينتهي بعد 30 يوماً |
| المحطة 0 | رصيد RunPod | 10$، والشحن التلقائي **مطفأ** |
| المحطة 0 | ربط موصل RunPod بالمحادثة | — |
| المحطة 2 | حساب Oracle Cloud | **المنطقة الرئيسية `me-jeddah-1`**، فهي تُختار عند فتح الحساب ولا تتغير. إعفاء A1 المجاني محسوب عليها |
| المحطة 2 | مفتاح SSH للإدارة | المفتاح العام يوضع عند إنشاء السيرفرين. والخاص في سر GitHub `ORACLE_SSH_KEY`، ومعه `ORACLE_APP_IP` (العنوان العام لعقدة التطبيق) |
| المحطة 2 | النطاق `zon.sa` وإدارة DNS | — |
| المحطة 2 | مساحة النسخ خارج Oracle | حساب تخزين متوافق مع S3، وتُختار مزوّدته قبل المحطة 2. مفتاحه في `/etc/zon/offsite.env` على عقدة البيانات، يضعه `ops.yml` من سر GitHub `OFFSITE_RCLONE_CONF` |
| المحطة 2 | Sentry | ثلاثة مشاريع: `zon-backend` و`zon-agents` و`zon-app`. الـ DSN أسرار GitHub |

## 2. عقدة الذكاء: القياس (المحطة 0)

المرجع: [`model-lab.md`](model-lab.md) «جلسة بلا طرفية».

### 2.1 التحضير

1. Claude يفتح قضية «قياس عقدة الذكاء — 18 نوفمبر» في المستودع، ورقمها هو `LAB_ISSUE`.
2. `list-gpu-types`: سعر A40 في Secure Cloud اليوم يُكتب في القضية قبل أي إنشاء. المعتمد في الميزانية 0.59$/س.
3. `list-data-centers`: مركز فيه **A40 متاحة في Secure Cloud وفيه Pods معالج (CPU)**.
   - الأوروبي أولاً، لقربه من جدة. زمن النفق يُقاس ولا يُفترض.
   - القرص الشبكي مربوط بمركزه، فلا يُنشأ قبل اختيار المركز.
4. `create-network-volume`:
   - **150GB** في ذلك المركز.
   - **ما ينزل عليه نحو 90GB:**
     - W2 ‏10.2GB.
     - المرجع `qwen3.8-27b-fp8`، وأوزانه تنزل BF16 نحو 56GB (`Qwen/Qwen3.8-27B`) وتُكمَّم FP8 عند الإقلاع لا قبله.
     - bge-m3 ‏2.3GB.
     - البيئات الأربع نحو 22GB، وذاكرة Triton.
   - 100GB لا تترك هامشاً لتنزيل متقطع ولا لملف ثالث.
   - إن كان المركز مناسباً للإنتاج صار هو قرص الإنتاج بعد حذف المرجع.
   - الكلفة 0.07$ لكل GB شهرياً، بالساعة.

### 2.2 التثبيت على Pod بلا بطاقة

1. **إنشاء الـ Pod** (`create-pod`):
   - معالج على القرص نفسه مركّباً في `/workspace`.
   - الصورة: RunPod PyTorch (CUDA 12.8).
   - قرص الحاوية 20GB، والمنافذ فارغة.
   - المتغير `ZON_NO_GPU=1`.
2. **أمر البدء:**
   <!-- cpu-pod-start -->
   ```bash
   bash -c 'curl -fsSL --retry 3 https://raw.githubusercontent.com/vv707-848/ZON/main/deploy/ai-node/prepare.sh -o /workspace/prepare.sh && for p in qwen3.8-27b-escha-w2 qwen3.8-27b-fp8; do ZON_PROFILE=$p ZON_NO_GPU=1 bash /workspace/prepare.sh; done; runpodctl pod stop "$RUNPOD_POD_ID" || runpodctl stop pod "$RUNPOD_POD_ID"; sleep infinity'
   ```
   بين علامتي تنصيص مفردتين: لا يُفك `$p` ولا `$RUNPOD_POD_ID` قبل أن يصلا إلى bash، سواء مرّر RunPod الأمر عبر shell أو لا. والإيقاف بصيغة runpodctl الحالية ثم القديمة، ثم `sleep infinity`: إن تعذر الإيقاف لا ينتهي الأمر فيعيده RunPod (_tools/tests/test_ai_node.py يشغّله بالطريقتين).
3. **المتابعة:** `get-pod` حتى `EXITED`، والمتوقع 45–90 دقيقة لتنزيل نحو 70GB وتثبيت البيئات على القرص الشبكي.
4. **بعده** `delete-pod`، والقرص يبقى.
5. **التحقق:** هذا الـ Pod لا ينشر شيئاً. التحقق في الخطوة التالية: `prepare.sh` يجد `.installed-<profile>` فلا يعيد التثبيت. وإن لم يجده يثبّت على البطاقة، وهو أغلى لكنه صحيح.

### 2.3 القياس على A40

1. **إنشاء الـ Pod** (`create-pod`):
   - A40 × 1، و`cloud: SECURE`، على القرص نفسه.
   - **`allowedCudaVersions`: ‏12.8 فما فوق** (مثل `["12.8","12.9","13.0"]`، بما تقبله الأداة).
     - بيئاتنا torch cu128، ووقت تشغيل Escha يشترط تعريفاً يشغّل CUDA 12.8.
     - وأجهزة A40 في RunPod بتعريفات مختلفة.
     - بغيره قد يقع الـ Pod على جهاز أقدم: `gpu-check.sh` يرفضه برسالة، والـ Pod ينشرها في القضية ويتوقف.
   - قرص الحاوية 20GB، والمنافذ فارغة.
   - **المتغيرات:**
     ```
     LLM_API_KEY    = {{ RUNPOD_SECRET_LLM_API_KEY }}
     GH_TOKEN       = {{ RUNPOD_SECRET_GH_TOKEN }}
     ZON_PROFILE    = qwen3.8-27b-escha-w2
     GPU_NAME       = A40
     ZON_REF        = <commit main الحالي: الأربعون خانة كاملة>
     LAB_ISSUE      = <الرقم>
     STOP_WHEN_DONE = 1
     ```
   - أسماء معاملات `create-pod` تُقرأ من مخطط الأداة عند الربط، ولا تُفترض.
2. **أمر البدء:**
   ```bash
   bash -c "curl -fsSL https://raw.githubusercontent.com/vv707-848/ZON/<commit>/deploy/ai-node/lab-pod.sh -o /workspace/lab-pod.sh && bash /workspace/lab-pod.sh"
   ```
3. **ما يجري تلقائياً:**
   1. فحص البطاقة: ترجمة ملف C حقيقي، ثم `True True True`.
   2. تشغيل المحرك، ثم فحص عربي واحد.
   3. تحمية لا تُحسب: حالتان من كل وكيل، ثمانٍ معاً. أول الطلبات بعد الإقلاع تبني نوى المحرك، وكانت تنتهي مهلتها.
   4. `lab run` بكل الحالات (209).
   5. `lab load`: أحجام الإنتاج، 8 طلبات معاً.
   6. تعليق بالجدولين في القضية، وقبلهما سطر الفحص وملخص التحمية.
   7. إيقاف الـ Pod.
   - المتوقع 40–70 دقيقة.
   - **وإن فشلت أي خطوة:** تعليق بالخطوة التي توقف عندها وآخر 80 سطراً من سجله، ثم الإيقاف نفسه.
   - **وإن تعذر الإيقاف:** يبقى منتظراً ولا يعيد القياس، ويقول ذلك في القضية، فيُوقف من الموصل.
4. **إن لم يصل تعليق بعد 90 دقيقة:**
   1. `get-pod`.
   2. إن كان يعمل: `stop-pod`.
   3. إن احتاج فحصاً: تشغيله من جديد بـ `STOP_WHEN_DONE=0`، فيبقى بعد القياس منتظراً ولا يعيده.
5. **الجولة الثانية:** المرجع على القرص نفسه بـ `ZON_PROFILE=qwen3.8-27b-fp8` وأمر البدء نفسه.
6. **بعد الجولتين:** `delete-pod`، وتقريرا JSON يبقيان في `/workspace/reports/`.

### 2.4 بوابة العبور: يُقبل الملف إن اجتاز كل الحدود

| الحد | القيمة |
|---|---|
| `failed` في كل وكيل | 0 |
| `inject_top3` | 0 |
| `zero_top3` في البحث والمطابقة | ≤ 5% |
| `invented` في التنقية | ≥ 95% |
| `guard` في التقارير | ≥ 90% |
| p95 للبحث والمطابقة | ≤ 6 ثوانٍ |
| `lab load` | فشل 0، وp95 ≤ 15 ثانية بثمانية معاً |
| `stability` | ≥ 80% |
| كل وكيل ترتيب | فوق `floor` |
| سجل الإقلاع | `max_total_num_tokens` ≥ 262,144، وسطر `<think>` غائب |

- **إن فشل الزمن وحده:** يُجرَّب بالترتيب، ولكل تجربة جولة قياس:
  1. ما يكتبه النموذج: `SEARCH_WRITES` و`MATCHING_WRITES` و`SEARCHERS_WRITES` في `ranking.py` (5 الآن). الوقت فيما يكتب لا فيما يقرأ: نتيجة البحث نحو 16 توكناً.
  2. ما يقرؤه، ولكل تخفيض سقفه في المحاكاة (`zon-agents lab sim`) قبل البطاقة: قراءة 5 في البحث تنزل بسقف الإجابة الصحيحة إلى 0.70.
  3. `ESCHA_ROUTE=blackwell`.
  4. L40S. قرارها تجاري: +1,369 ريالاً شهرياً.
- **وإن فشلت الدقة:** لا يُقبل الملف. يُكتب ما فشل وأين في القضية، ويُعرض على صاحب المشروع قبل أي تجربة ثانية.
- **وبعد القرار:** التقريران يُرفعان في اللوحة ← «مختبر النماذج»، فيقرأ `zon:doctor` أن الملف مقاس. يُنزَّلان من القرص بإعادة Pod صغير، أو تُعاد الجولة على Pod الإنتاج بعد ربطه (2.6).

### 2.4.1 ما علّمته جلسة 19 نوفمبر (القضية #119)

- **المركز:** لم يكن مركز فيه A40 وPod معالج معاً. فأُنشئ القرص في مونتريال (`CA-MTL-1`)، وثبّت `prepare.sh` كل ملف على البطاقة عند أول تشغيل، وكلّف ذلك نحو 30 دقيقة من البطاقة.
- **الطبقة:** في مونتريال لا يوجد إلا `HIGH_PERFORMANCE`. سعرها 0.0292$ في الساعة لـ 150GB، أي نحو 21$ شهرياً (0.14$ للـ GB)، ضعف العادية.
- **التعريف:** مضيفات A40 كانت كلها على CUDA 13.2، وقيد `allowedCudaVersions` من 12.8 فما فوق يقبلها.
- **`runpodctl` داخل الـ Pod قديم:** يرفض `pod stop`، والصيغة القديمة `stop pod` أوقفته في كل جولة (`lab-pod.sh` يجرب الصيغتين).
- **أول تشغيل على جهاز جديد بطيء:** البيئات على القرص الشبكي آلاف الملفات الصغيرة، فاستغرق المحرك من 6 إلى 16 دقيقة قبل أن يبدأ التحميل. يُرفع انتظار المحرك بـ `ZON_ENGINE_TRIES` (720 = ساعة) دون تغيير كود. وفي الإنتاج يُحسب هذا في زمن أي إعادة تشغيل.
- **`DELETE_WHEN_DONE=1`** (#126): يحذف الـ Pod نفسه بعد نشر الجداول، والقرص يبقى. ويسبق القياسَ فحصٌ عربي واحد: إن جاء الرد فارغاً أو فيه `<think>` أو بلا عربية، نُشر ولم يبدأ القياس.
- **`vm.overcommit_memory`** لا يُضبط داخل حاوية RunPod (للقراءة فقط). `gpu-check.sh` يسجّله ولا يعدّه فشلاً، كما يقول سكربت المزوّد.
- **مسار الأوامر والسجلات في RunPod** (`exec-pod`، `stream-pod-logs`) يرد أحياناً بـ 502 أو 403 في دقائق الـ Pod الأولى. حالة الـ Pod تُقرأ بـ `get-pod`، والنتيجة تأتي في القضية.

### 2.5 الكلفة المتوقعة

| البند | التقدير |
|---|---|
| Pod المعالج | أقل من 0.10$ |
| A40 لجولتين | نحو ساعتين × 0.59$ ≈ 1.2$ |
| القرص (150GB × 0.07$ العادية، أو 0.14$ حيث لا يوجد إلا HIGH_PERFORMANCE) | نحو 10.5$ أو 21$ شهرياً، بالساعة |
| **مجموع الجلسة** | أقل من 2$ إن حُذف القرص بعدها، ويبقى إن صار قرص الإنتاج |

### 2.6 Pod الإنتاج (المحطة 2، بعد أن تعمل عقدة التطبيق)

1. **مفتاح النفق يولّده الـ Pod نفسه على قرصه**، ولا يغادر الخاص القرص أبداً (`entrypoint.sh`).
   - مع `GH_TOKEN` و`LAB_ISSUE` ينشر **نصفه العام وحده** تعليقاً في القضية مرة واحدة. العام ليس سراً.
   - النفق يعيد المحاولة كل 5 ثوانٍ حتى تقبله عقدة التطبيق.
2. **`APP_NODE_HOSTKEY`:** سطر `/etc/ssh/ssh_host_ed25519_key.pub` من عقدة التطبيق، يقرؤه `ops.yml` ويكتبه في القضية.
   - عام لا سري، ويُلصق كما هو في متغيرات الـ Pod.
   - إلزامي: بلا بصمة مثبتة يرفض النفق الاتصال (`StrictHostKeyChecking=yes`).
3. **`create-pod`:**
   - المواصفات نفسها، والقرص قرص الإنتاج.
   - المتغيرات:
     ```
     APP_NODE_IP      = <العنوان العام لعقدة التطبيق>
     LLM_API_KEY      = {{ RUNPOD_SECRET_LLM_API_KEY }}
     ZON_PROFILE      = <الملف المقبول>
     ZON_REF          = <commit الملف المقبول>
     APP_NODE_HOSTKEY = ssh-ed25519 AAAA…
     GH_TOKEN         = {{ RUNPOD_SECRET_GH_TOKEN }}
     LAB_ISSUE        = <قضية الرفع>
     ```
   - أمر البدء:
     ```bash
     bash -c "curl -fsSL https://raw.githubusercontent.com/vv707-848/ZON/<commit>/deploy/ai-node/prepare.sh -o /workspace/prepare.sh && bash /workspace/prepare.sh && exec bash /workspace/entrypoint.sh"
     ```
4. **إضافة المفتاح العام (`ops.yml`):** يقرأ سطر المفتاح من القضية ويثبّته بقيوده:
   ```bash
   printf 'restrict,port-forwarding,permitlisten="127.0.0.1:30000",permitlisten="127.0.0.1:18080" %s\n' "$PUBLIC_KEY" \
     | install -m 600 -o zon-tunnel -g zon-tunnel /dev/stdin /home/zon-tunnel/.ssh/authorized_keys
   ```
   - السطر يجب أن يبدأ بـ `ssh-ed25519 ` وينتهي بـ ` zon-ai-node`، وإلا لا يُثبَّت.
   - التعليق يجب أن يكون من حساب صاحب المستودع، وإلا لا يُثبَّت.
5. **التحقق من عقدة التطبيق (`ops.yml`)** بالترتيب، وكل خطوة شرط لما بعدها:
   1. `ss -ltn | grep -E '127.0.0.1:(30000|18080)'`: سطران، ولا `0.0.0.0`.
   2. ```bash
      curl -sf -X POST 127.0.0.1:18080/embed -H 'Content-Type: application/json' -d '{"texts":["شقة في النرجس"]}'
      ```
      يرجع المتجهات الثلاثة.
   3. `/v1/models`:
      - بالمفتاح: ```curl -sf -H "Authorization: Bearer $LLM_API_KEY" 127.0.0.1:30000/v1/models``` يعرض `zon-ai`.
      - وبلا المفتاح يُرفض.
   4. من خارج RunPod: `https://<POD_ID>-30000.proxy.runpod.net/v1/models` يرد بغير 200، والتحقق نفسه للمنفذ 8080.
6. **سعر الإنتاج:** A40 ‏730 ساعة × 0.59$ ≈ 1,615 ريالاً شهرياً.
   - يُتحقق منه عند الإنشاء ويُكتب في القضية.
   - إن تغيّر أكثر من 10% يُبلَّغ صاحب المشروع قبل الإنشاء.

## 3. شبكة Oracle (المحطة 2)

العناوين ثابتة في الملفات:
- `10.10.0.10` في `mysql/users.sql` و`ai_reader.sql`.
- `10.10.0.20` في `mysql/zon.cnf` و`.env` وملف compose.

فالشبكة تُبنى عليها بالضبط:

| المورد | القيمة |
|---|---|
| VCN | `zon-vcn` · ‏`10.10.0.0/24` · المنطقة `me-jeddah-1` |
| شبكة عامة | `zon-public` · ‏`10.10.0.0/28` · بوابة إنترنت · فيها عقدة التطبيق **`10.10.0.10`** |
| شبكة خاصة | `zon-private` · ‏`10.10.0.16/28` · بوابة NAT للخروج فقط (التحديثات وصور Docker) · فيها عقدة البيانات **`10.10.0.20`** بلا عنوان عام |
| قواعد عقدة التطبيق (NSG `zon-app`) | **الوارد:** ‏TCP 443 و80 من `0.0.0.0/0`، وTCP 22 من `0.0.0.0/0` (للنفق من RunPod الذي لا عنوان ثابتاً له، بالمفتاح وحده، وfail2ban). **الصادر:** الكل |
| قواعد عقدة البيانات (NSG `zon-data`) | **الوارد:** ‏TCP 3306 و6333 و22 من `10.10.0.10/32` فقط. **الصادر:** الكل عبر NAT |

- **العقدتان:**
  - `VM.Standard.A1.Flex`: ‏4 OCPU و24GB.
  - الصورة: Canonical Ubuntu 24.04 aarch64.
  - قرص إقلاع 50GB.
  - العنوان الخاص يُكتب يدوياً عند الإنشاء: `.10` و`.20`.
  - إن ردت Oracle «Out of host capacity»: يُعاد الطلب بعد ساعات. في جدة نطاق إتاحة واحد، ولا يُغيَّر الشكل دون قرار صاحب المشروع، لأن البديل AMD أغلى بنحو 252 ريالاً للسيرفر.
- **الأقراص (Block Volume، أداء Balanced، Paravirtualized):**
  - 350GB لعقدة التطبيق في `/srv/zon` (الصور).
  - 300GB لعقدة البيانات في `/data`.
  - تُركَّب بـ UUID في `/etc/fstab` مع `_netdev,nofail`.
- **الدخول إلى عقدة البيانات:** عبر عقدة التطبيق فقط: `ssh -J ubuntu@<app> ubuntu@10.10.0.20`.

## 4. عقدة البيانات (10.10.0.20)

يشغّلها `ops.yml` بسكربت `deploy/provision/data-node.sh`، وهذا محتواه خطوة خطوة:

1. **النظام:**
   ```bash
   apt-get update && apt-get -y upgrade
   timedatectl set-timezone UTC
   ```
   الخادم بالتوقيت العالمي، وLaravel يحسب الرياض بنفسه.
2. **Docker** من مستودعه الرسمي (arm64): ‏`docker-ce` و`docker-compose-plugin`، ثم `systemctl enable --now docker`.
3. **القرص والمجلدات:**
   ```bash
   mkfs.ext4 /dev/oracleoci/oraclevdb   # مرة واحدة فقط، بعد التأكد أنه فارغ: blkid لا يعرض شيئاً
   echo "UUID=$(blkid -s UUID -o value /dev/oracleoci/oraclevdb) /data ext4 defaults,_netdev,nofail 0 2" >> /etc/fstab
   mount -a
   install -d -m 700 /data/mysql /data/qdrant /etc/zon
   install -d -m 750 /var/backups/zon/mysql
   ```
4. **المستودع:** `git clone https://github.com/vv707-848/ZON /opt/zon`، ثم `git -C /opt/zon checkout <commit>`.
5. **الأسرار:** `ops.yml` يكتبها من أسرار GitHub عبر stdin، بصلاحية 600 ومالكها root:
   - `/etc/zon/mysql-root`: كلمة مرور root.
   - `/etc/zon/qdrant.env`: ‏`QDRANT__SERVICE__API_KEY=…`، والقيمة نفسها في `QDRANT_API_KEY` بخدمة الوكلاء.
   - `/etc/zon/backup.cnf`: ‏`[client]` و`user=zon_backup` و`password=…`.
   - `/etc/zon/backup.env`:
     ```
     QDRANT_API_KEY=…
     QDRANT_SNAPSHOTS=/data/qdrant/snapshots
     ```
   - `/etc/zon/offsite.env`: إعداد rclone للنسخة الخارجية.
6. **التشغيل:**
   ```bash
   docker compose -f /opt/zon/deploy/data-node/compose.yaml up -d
   ```
   **التحقق:**
   - `docker ps` يعرض `zon-mysql (healthy)` و`zon-qdrant`.
   - `ss -ltn` يعرض `10.10.0.20:3306` و`10.10.0.20:6333`، ولا `0.0.0.0:3306`.
7. **قاعدة البيانات وحساباتها:** كلمات السر تُستبدل من الأسرار في الأنبوب نفسه ولا تُكتب في ملف:
   ```bash
   sed -e "0,/\*\*\*/s//$ZON_DB_PASSWORD/" -e "0,/\*\*\*/s//$ZON_BACKUP_PASSWORD/" /opt/zon/deploy/mysql/users.sql \
     | docker exec -i zon-mysql sh -c 'mysql -uroot -p"$(cat /run/secrets/mysql_root)"'
   ```
   **التحقق:** `SELECT user, host FROM mysql.user` يعرض `zon@10.10.0.10` و`zon_backup@localhost`، ولا مستخدم بـ `%`.
8. **النسخ الاحتياطي:**
   ```bash
   install -m 755 /opt/zon/deploy/backup/zon-backup.sh /usr/local/bin/
   install -m 644 /opt/zon/deploy/backup/cron /etc/cron.d/zon-backup
   ```
   - ثم سطر النسخة الخارجية في `/etc/cron.d/zon-backup`، الساعة 04:00:
     ```
     rclone sync /var/backups/zon remote:zon-backups
     ```
     ومعه نسخة Qdrant من `/data/qdrant/snapshots`.
   - **التحقق:**
     1. تشغيل `/usr/local/bin/zon-backup.sh` يدوياً.
     2. ملف `zone-<اليوم>.sql.gz` أكبر من صفر.
     3. ظهوره في المساحة الخارجية.
9. **حساب الوكيل الأعلى** (بعد ترحيلات Laravel في 5.7، لأنه يحتاج المشاهد):
   ```bash
   sed "s/\*\*\*/$REPORT_DB_PASSWORD/" deploy/mysql/ai_reader.sql | docker exec -i zon-mysql …
   ```
   **التحقق:** `SHOW GRANTS FOR 'ai_reader'@'10.10.0.10'` لا يعرض إلا `SELECT` على `ai_v_*`.

## 5. عقدة التطبيق (10.10.0.10)

بسكربت `deploy/provision/app-node.sh`.

### 5.1 النظام والحزم

- الإصدارات من [`versions.md`](versions.md). وكل حزمة لها نسخة arm64 رسمية، إلا MinIO (5.3).

| الحزمة | المصدر |
|---|---|
| Nginx 1.30 | مستودع nginx.org «stable» لـ noble، لا نسخة Ubuntu (1.24) |
| PHP 8.5 | مستودع `ppa:ondrej/php`: ‏`php8.5-fpm` و`-cli` و`-bcmath` و`-gd` و`-intl` و`-mbstring` و`-mysql` و`-redis` و`-zip` و`-curl` و`-xml`. ‏exif وpcntl ضمن الأساس، ويُتحقق بـ `php -m` |
| Composer 2 | المثبّت الرسمي، مع التحقق من بصمته |
| Redis 8 | مستودع packages.redis.io. **لا Dragonfly عند الإطلاق:** الفحص الآلي على Redis 8، وDragonfly لم يُجرَّب (`versions.md`) |
| Python 3.14 | من uv، لخدمة الوكلاء |
| أخرى | ‏`supervisor` و`certbot` و`fail2ban` و`git` و`unzip` من Ubuntu |

- **التحقق:**
  - `nginx -v`: 1.30.x.
  - `php -v`: 8.5.x.
  - `php -m`: الإضافات كلها.
  - `redis-cli ping`: ‏PONG، وRedis على `127.0.0.1` فقط بكلمة سر (`requirepass`).

### 5.2 المستخدمون والمجلدات

```bash
useradd -r -m -s /usr/sbin/nologin zon-agents
useradd -m -s /usr/sbin/nologin zon-tunnel && install -d -m 700 -o zon-tunnel -g zon-tunnel /home/zon-tunnel/.ssh
install -d -o www-data -g www-data /var/www/zon /var/log/zon
```
القرص 350GB على `/srv/zon` كما في 4.3.

### 5.3 الصور: قرار قبل التنفيذ

مستودع MinIO المجاني **أُرشف في 25 أبريل 2026**، ولا نسخ جاهزة جديدة ولا تحديثات أمنية له ([github.com/minio/minio](https://github.com/minio/minio)). والملف الحالي يفترضه (`MEDIA_DISK=minio`، و`media.zon.sa` ← ‏`127.0.0.1:9000`).

| الخيار | ما يلزم | الأثر |
|---|---|---|
| **أ. Oracle Object Storage في جدة (المقترح)** | ثلاثة تعديلات في الكود: منطقة القرص من `.env` (اليوم ثابتة `us-east-1`)، ونقطته S3-compatible، وnginx `media.zon.sa` يمرر بترويسة Host لنقطة Oracle. ثم تجربة الرفع والروابط الموقّعة | خدمة مُدارة ومحدَّثة، والبيانات تبقى في المملكة |
| ب. آخر نسخة MinIO المجانية | لا تعديل | يعمل على `127.0.0.1` خلف nginx للقراءة فقط، لكنه بلا تحديث أمني إلى الأبد |

**القرار لصاحب المشروع.** حتى يُقرر لا تُرفع صورة حقيقية.

### 5.4 SSH والنفق

[`deploy/ssh/sshd_config.zon`](../deploy/ssh/sshd_config.zon) بأجزائه الأربعة وترتيبها.

1. **قبل أي تعديل:** تبقى جلسة الإدارة الحالية مفتوحة حتى ينجح دخول جديد.
2. **بعد التعديل:** `sshd -t && systemctl reload ssh`.
3. **التحقق:** `sshd -T | grep -E 'passwordauthentication|allowusers'`.

### 5.5 الكود والإعدادات

```bash
git clone https://github.com/vv707-848/ZON /var/www/zon && git -C /var/www/zon checkout <commit>
cd /var/www/zon/backend && composer install --no-dev --optimize-autoloader
cd /var/www/zon/agents && uv sync --locked --no-dev
```

- **`backend/.env`:** من `.env.example`، والقيم من أسرار GitHub عبر `ops.yml`.
  - `APP_KEY` ‏(`php artisan key:generate --show`).
  - ‏`RATE_SALT` و`DB_PASSWORD` و`REPORT_DB_PASSWORD` و`REDIS_PASSWORD`.
  - مفتاحا الصور.
  - ‏`REVERB_*` الثلاثة.
  - ‏`AGENTS_INTERNAL_KEY` و`INDEX_SHARED_SECRET` (‏`openssl rand -hex 32` لكل منهما).
  - ‏`ADMIN_ALLOWED_IPS` (عنوان صاحب المشروع، أو فارغ بقراره).
  - التكاملات تبقى بسائق `fake` حتى المحطة 4.
  - **`PRODUCTION_ALLOWS_FAKE`:** الجهات المسماة فقط، بقرار مكتوب.
- **`agents/.env`:** من `.env.example`. ‏`AGENTS_INTERNAL_KEY` و`INDEX_SHARED_SECRET` بالقيم نفسها، و`LLM_API_KEY` بقيمة الـ Pod، و`QDRANT_API_KEY`، و`LLM_PROFILE` الملف المقبول.
- **ملفات المتجرين وFirebase:** في `/etc/zon/` بصلاحية 640 ومجموعة `www-data`، في المحطة 4.
- **التحقق:** `grep -c '=$' .env` لا يعرض إلا ما يُترك فارغاً عمداً، وكل فراغ مكتوب سببه في القضية.

### 5.6 الإعدادات المنسوخة

| الملف | المكان |
|---|---|
| `php/zon.ini` | `conf.d` في fpm وcli |
| `supervisor/zon.conf` | `/etc/supervisor/conf.d/` |
| `cron/zon` | `/etc/cron.d/zon` |
| `logrotate/zon` | `/etc/logrotate.d/zon` |

ثم `systemctl restart php8.5-fpm`. وتبقى برامج Supervisor موقوفة حتى 5.7.

### 5.7 القاعدة والفهرس

1. **الترحيلات:**
   ```bash
   php artisan migrate --force
   ```
   **التحقق:** `php artisan migrate:status` بلا Pending.
2. **البيانات المرجعية:**
   ```bash
   php artisan db:seed --force
   ```
   المدن والباقات والأدوار ومكتبة المعرفة الأولى. البيانات التجريبية لا تُبذر خارج `local`.
3. **حساب الوكيل الأعلى:** الخطوة 4.9 على عقدة البيانات.
4. **الفهرس:**
   ```bash
   sudo -u zon-agents /var/www/zon/agents/.venv/bin/zon-agents bootstrap
   ```
   ينشئ مجموعات Qdrant، والتكرار آمن.
5. **المدير العام:**
   ```bash
   php artisan zon:create-superadmin <بريد صاحب المشروع>
   ```
   - كلمة السر يكتبها هو في الطرفية، وهذه الخطوة وحدها تحتاج جلسة يفتحها بنفسه أو Cloud Shell.
   - المصادقة الثنائية عند أول دخول.
6. **تحسين التطبيق:**
   ```bash
   php artisan optimize && php artisan filament:optimize
   ```
7. **تشغيل البرامج:**
   ```bash
   supervisorctl reread && supervisorctl update
   ```
   **التحقق:** `supervisorctl status` يعرض الخمسة RUNNING.

### 5.8 DNS والشهادات وNginx

1. **سجلات A للعنوان العام لعقدة التطبيق:** `zon.sa` و`www.zon.sa` و`api.zon.sa` و`media.zon.sa`.
   **التحقق:** ```dig +short <host>``` يرد بالعنوان.
2. **الشهادات قبل تفعيل `zon.conf`**، فهو يحوّل كل المنفذ 80 إلى https:
   ```bash
   certbot certonly --standalone -d zon.sa -d www.zon.sa
   certbot certonly --standalone -d api.zon.sa
   certbot certonly --standalone -d media.zon.sa
   ```
   والتجديد بـ `--webroot` بعد تشغيل nginx، ويُختبر بـ `certbot renew --dry-run`.
3. **تفعيل الإعداد:**
   ```bash
   ln -s /etc/nginx/sites-available/zon.conf /etc/nginx/sites-enabled/ && nginx -t && systemctl reload nginx
   ```

### 5.9 ترتيب الإقلاع والتحقق النهائي

1. **الجدول الكامل** في [`deploy/README.md`](../deploy/README.md) «ترتيب الإقلاع»، بخطواته الثماني.
2. **`php artisan zon:doctor`:** بلا عطل.
   - التحذير المقبول الوحيد: التكاملات بسائق المحاكاة قبل المحطة 4، كل واحدة مسماة.
3. **«تحقق أمني قبل أي إطلاق»** في الملف نفسه، كاملاً.
4. **من الخارج:**
   - `https://zon.sa`: يفتح بشهادة صالحة.
   - `https://api.zon.sa/api/v1/app/config`: يرد 200.
   - `https://api.zon.sa/admin`: يعرض الدخول، أو يرفض من عنوان خارج `ADMIN_ALLOWED_IPS`.
5. **اختبار استعادة:** أحدث نسخة MySQL تُستعاد في حاوية مؤقتة على عقدة البيانات، ويُطابق عدد صفوف خمسة جداول. ثم تُحذف الحاوية. يتكرر شهرياً.

## 6. الإصدار التالي والتراجع

- **الإصدار:** [`operations.md`](operations.md) «الإصدار الجديد»، حرفياً.
  - `ZON_REF` في Pod الإنتاج لا يتغير إلا بملف قيس.
- **التراجع:**
  1. `git checkout <commit السابق>`.
  2. `composer install`.
  3. `php artisan optimize`.
  4. `queue:restart`.
- **ترحيل بلا `down()` سليم:** لا يُتراجع عنه بالتراجع وحده. يُستعاد من نسخة الليلة بقرار صاحب المشروع، ويُكتب ما ضاع.
