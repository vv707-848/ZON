# منصة زون العقارية

[![Platform](https://github.com/vv707-848/ZON/actions/workflows/platform.yml/badge.svg)](https://github.com/vv707-848/ZON/actions/workflows/platform.yml)
[![App](https://github.com/vv707-848/ZON/actions/workflows/app.yml/badge.svg)](https://github.com/vv707-848/ZON/actions/workflows/app.yml)
[![Stack](https://github.com/vv707-848/ZON/actions/workflows/stack.yml/badge.svg)](https://github.com/vv707-848/ZON/actions/workflows/stack.yml)

المرجعان الحاكمان:
- تطبيق Flutter **3.1.2** (المعتمد): كل شاشة فيه لها واجهتها هنا.
- وثيقة المعمارية **v1.4**.

قبل أي عمل: [`docs/rules.md`](docs/rules.md) — قواعد العمل، والفحص الآلي الذي يمر عليه كل رفع.

## التشغيل بأمر واحد

```bash
docker compose up                       # أول مرة: يبني ويثبّت ويرحّل قاعدة البيانات (دقائق)
docker compose exec web php artisan zon:create-superadmin you@example.com   # حساب اللوحة، مرة واحدة
```

| الرابط | ما فيه |
|---|---|
| `http://localhost:8000` | الموقع، وواجهة التطبيق `/api/v1`، ولوحة الإدارة `/admin` |
| `http://localhost:8100/agents/health` | خدمة الوكلاء |

- كل جهة خارجية محاكاة صريحة: لا رسالة ولا دفع ولا متجر يخرج من الجهاز، ورمز التحقق 204815.
- النموذج والتضمين على عقدة الذكاء (RunPod)، لا هنا: بدونها تقول خدمة الوكلاء ذلك في فحصها، ويأخذ Laravel مساره البديل كما في الإنتاج.
- للتطوير فقط. الإنتاج على عُقده (`deploy/README.md`). والأمر نفسه يُفحص آلياً (`.github/workflows/stack.yml`).

| المجلد | الطرف | المرجع |
|---|---|---|
| `app/` | تطبيق الجوال (Flutter 3.1.2، إصدار المتجر 1.2.1): الواجهات كاملة، ويعمل اليوم بمحاكاة محلية حتى يُربط بالخادم | `app/README.md` · `app/docs/` |
| `backend/` | Laravel 13: واجهة التطبيق `/api/v1` + لوحة الإدارة `/admin` (Filament 5) + الوكيل الأعلى | `backend/README.md` |
| `agents/` | خدمة الوكلاء (Python · FastAPI · PydanticAI · Qdrant): 13 وكيلاً، الفهرسة، MCP | `agents/README.md` |
| `deploy/` | ملفات العقد الثلاث وترتيب الإقلاع والتحقق الأمني | `deploy/README.md` |
| `docs/` | الوثائق أدناه، وعقد Laravel ↔ الوكلاء وعيّناته | — |
| `_tools/` | `api_doc.py`: يولّد `docs/api.md` من جدول المسارات ورموز الأخطاء · `readiness.py`: أرقام `docs/handover.md` · `lab_cases.py`: يولّد أسئلة مختبر النماذج · `site-audit/`: فحص الموقع (Lighthouse والأجهزة اللوحية) | رأس الملف |

## الوثائق

| الملف | متى تقرؤه |
|---|---|
| `docs/rules.md` | قبل كل شيء: قواعد العمل والفحص الآلي |
| `docs/handover.md` | حالة التسليم: جاهزية كل جزء بمعايير قبول مقاسة، والمتبقي، والاعتماد على جهات خارجية |
| `docs/versions.md` | إصدار كل أداة ومكتبة، وآخر إصدار مستقر، والقرار |
| `docs/three-parties.md` | كيف يترابط التطبيق والباك إند واللوحة والذكاء، ومن يملك الحقيقة، وما تتحكم به اللوحة |
| `docs/api.md` | لمطوّر التطبيق: كل مسار وصلاحيته ورموز الأخطاء (مولَّد من المسارات الفعلية) |
| `docs/app-coverage.md` | كل شاشة في التطبيق ← واجهتها ← شاشتها في اللوحة |
| `docs/panel-matrix.md` | «تشوف وتعدّل»: من يفتح كل شاشة في اللوحة ومن يعدّل فيها (مولَّد من الكود ومختبَر) |
| `docs/website.md` | الموقع `zon.sa`: صفحاته وما يُفهرس منها، والويب مقابل التطبيق، وفحصه الآلي |
| `docs/data-model.md` | الجداول والاتفاقات (المال، الجوال، الفهرسة، الحذف، التتبع) |
| `docs/integrations.md` | الجهات الخارجية: المحاكاة الحالية وخطوات التحويل للحي، **وقرار ما قبل الإطلاق** |
| `docs/operations.md` | التشغيل: الأوامر، الجدولة، الإصدار، **تتبّع طلب**، ومن العرَض إلى موضع الإصلاح |
| `docs/contracts/agents-api.md` | العقد بين Laravel وخدمة الوكلاء |
| `docs/model-lab.md` | مختبر النماذج: الأسئلة الذهبية، وجلسة القياس على RunPod، وكيف يُختار النموذج بالأرقام |
| `docs/rebuild-blueprint.md` | المخطط الذي بُني عليه كل ما سبق |

## الحالة عند التسليم

**[`docs/handover.md`](docs/handover.md)**: جاهزية كل جزء، محسوبة من متطلبات ومعايير قبول ومقاسة من الكود:
- الباك إند والموقع واللوحة.
- تطبيق Flutter وربطه.
- خدمة الذكاء.

وفيه المنجز والمتبقي، وما لم يُقس، وما ينتظر حسابات وتعاقدات خارجية.

**الفحص الآلي مع كل رفع:**
- **الباك إند:** PHPStan وPint، وأرضيات تغطية: 86%.
- **خدمة الوكلاء:** Ruff وmypy، والعقد مع Laravel، والأرضية 83%.
- **التطبيق:** `flutter analyze`، والاختبارات مع المحاكاة وبدونها، والأرضية 71%.
- **المنصة بأمر واحد:** `stack.yml`.

عدد الاختبارات والتغطية في ملخص كل تشغيل.
