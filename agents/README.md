# خدمة الوكلاء — زون

الطرف الثالث من المنصة: الخدمة الوحيدة التي تستعمل النموذج اللغوي والتضمين والبحث المتجهي.

- يستدعيها Laravel وحده على `127.0.0.1:8100`، وفق العقد [`docs/contracts/agents-api.md`](../docs/contracts/agents-api.md).
- كل ما يصلها مُخفى الهوية مسبقاً.
- لا تسجّل نص أي طلب.

| المسار | الوظيفة | المصادقة |
|---|---|---|
| `POST /agents/{agent}` | الوكلاء الاثنا عشر: بحث، مطابقة، شات، تلخيص، تنقية، الوكيل الأعلى | `X-Internal-Key` |
| `POST /index/{entity}` | إشارات الفهرسة إلى Qdrant | HMAC، وفرق التوقيت ≤ 300 ث |
| `POST /media/phash` | بصمة إدراكية للصورة (اختيارية) | `X-Internal-Key` |
| `GET /agents/health` | حالة النموذج والتضمين وQdrant | بلا |
| `/mcp/` | خادم MCP يكشف الأدوات نفسها | `X-Internal-Key` |

```
zon_agents/
  main.py            مسارات HTTP، وسطر سجل JSON لكل طلب بـ request_id = رقم تتبّع Laravel
  monitoring.py      Sentry (SENTRY_DSN): الخطأ غير المتوقع برقم التتبّع، بلا محتوى الطلب ولا الموجّهات
  pipelines/         مسار كل وكيل: ranking · chat · text (تلخيص وتنقية) · reports (الوكيل الأعلى)
                     الكود يختار الاسترجاع، والنموذج يرتّب ويصوغ فقط
  tools/retrieval.py الاستعلام بمتجهاته الثلاثة، والبحث الهجين داخل المرشحين، ومكتبة المعرفة
  mcp_server.py      الأدوات نفسها عبر MCP — تعريف واحد لا كود ثانٍ
  llm.py             PydanticAI ← محرك البطاقة: إعادة واحدة بحرارة 0.1، وحد التزامن 8، والميزانية
  profiles.py        ملف النموذج النشط (LLM_PROFILE): معاملات كل استخدام وإضافات الطلب
  models/*.toml      ملف لكل نموذج يُجرَّب: أوزانه ومحركه ومعاملاته — البطاقة تخدمه منه، والخدمة تناديه به
  lab/               مختبر النماذج: الأسئلة الذهبية ومقاييسها ومشغّلها (docs/model-lab.md)
  embeddings.py      عميل خادم bge-m3
  store.py           مجموعات Qdrant والاستعلام الهجين
  indexing.py        إشارات الفهرسة ونسخها، وتقطيع مستندات المعرفة
  schemas/           مخططات المدخل والمخرج لكل وكيل (العقد §3–§4)
  prompts/*.md       برومبت عربي لكل وكيل، أوله `version: N` ويُراجَع كالكود
```

## أين تبحث عند العطل

| ما يظهر | السبب المرجّح | المكان |
|---|---|---|
| `degraded: true` في الرد | النموذج لم يجب أو كسر المخطط مرتين | سطر الطلب في السجل: `"llm": "timeout" \| "unavailable" \| "invalid"` |
| 503 من `/agents/*` | التضمين أو Qdrant متوقف (والنموذج لوكلاء النص) | `GET /agents/health` |
| 401 من `/index/*` | المفتاح المشترك مختلف، أو ساعتا الخادمين متباعدتان أكثر من 5 دقائق | `INDEX_SHARED_SECRET` في الطرفين، وNTP |
| `ignored_stale` | نسخة أقدم من المفهرسة — سلوك صحيح | — |
| 500 من `/agents/*` | خطأ في الكود | سطر `unhandled` في السجل، والخطأ نفسه في Sentry (zon-agents) بالوسم `request_id` |

كل سطر سجل يحمل `request_id`، وهو رقم التتبع نفسه في Laravel (`X-Request-Id`):

```bash
grep '"request_id": "<الرقم>"' /var/log/zon/agents.log
```

## التشغيل

الحزم بأداة [uv](https://docs.astral.sh/uv/): `uv.lock` يثبّت كل حزمة بإصدارها لكل المنصات (خادم ARM وأجهزة الفحص x86 معاً). Python المعتمد 3.14 ([`docs/versions.md`](../docs/versions.md)).

```bash
uv sync --locked --no-dev --python 3.14   # .venv من uv.lock حرفياً، بلا أدوات التطوير
cp .env.example .env              # ثم القيم الحقيقية (المفتاحان بنفس قيم backend/.env)
.venv/bin/zon-agents bootstrap    # ينشئ المجموعات الأربع وفهارسها — آمن للتكرار
.venv/bin/zon-agents serve        # عامل واحد على HOST:PORT (supervisor: zon-agents)
```

بصمات الصور اختيارية: `uv sync --locked --no-dev --extra phash` (idealo/imagededup على المعالج). وبدونها يرد `/media/phash` بـ 501، ويبقى Laravel على `IMAGE_HASH_DRIVER=gd`.

تحديث حزمة: غيّر رقمها في `pyproject.toml`، ثم `uv lock`، ثم الاختبارات. Dependabot يفتح هذه الطلبات أسبوعياً.

## Qdrant

`zon-agents bootstrap` ينشئ المجموعات الأربع (`listings` · `project_units` · `requests` · `knowledge`) وفهارس حقول التصفية:

| المتجه | الإعداد |
|---|---|
| كثيف | 1024، ‏cosine، مكمّم TurboQuant ‏`bits4` ومثبّت في الذاكرة |
| متناثر | — |
| ColBERT | ‏MaxSim، ‏`hnsw m=0`، على القرص، int8. في كل المجموعات عدا `knowledge` |

**تبديل ColBERT أو تغيير الإعداد:**

1. `zon-agents bootstrap --recreate`.
2. في الباك إند: `php artisan zon:reindex --force`.

## الاختبارات

```bash
uv sync --locked                  # مع أدوات التطوير (مجموعة dev)
.venv/bin/pytest
.venv/bin/ruff check . && .venv/bin/ruff format --check . && .venv/bin/mypy
```

- النموذج في الاختبارات هو `FunctionModel` من PydanticAI، يجيب بما يكتبه كل اختبار.
- التضمين حتمي من كلمات النص.
- Qdrant في الذاكرة (الوضع المحلي).
- لا اتصال بشبكة.

## مختبر النماذج

يقيس أي ملف نموذج على الأسئلة الذهبية نفسها، بمسار الإنتاج نفسه. التفاصيل في [`docs/model-lab.md`](../docs/model-lab.md).

```bash
.venv/bin/zon-agents lab check                                   # الأسئلة سليمة
LLM_API_KEY=… .venv/bin/zon-agents lab run --profile <name> --gpu A40   # على عقدة الذكاء: تقرير JSON وجدول
```
