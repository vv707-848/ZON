# الإصدارات

القاعدة ([`rules.md`](rules.md) — القاعدة 2): آخر إصدار مستقر تدعمه كل مكتباتنا الأساسية، وتمر عليه الاختبارات كلها.
آخر جرد: **2 نوفمبر 2026**. بعده يفتح Dependabot طلبات التحديث أسبوعياً، ولا يُدمج طلب والفحص الآلي أحمر.

## الباك إند ولوحة الإدارة

| المكوّن | المستخدم | آخر مستقر | ملاحظة |
|---|---|---|---|
| PHP | 8.5 | 8.5.12 | `composer.json`: ‏`^8.5`. الفحص الآلي والإنتاج على 8.5 |
| Laravel | 13.40.0 | 13.40.0 | من 12. التغييرات أدناه |
| Symfony (عبر Laravel) | 8.1 | — | — |
| Filament / Livewire | 5.11.0 / 4.4.8 | نفسها | — |
| Sanctum / Reverb | 4.3.3 / 1.12.0 | نفسها | — |
| spatie/laravel-permission | 8.3.2 | نفسه | — |
| spatie/laravel-activitylog | 5.1.3 | نفسه | من 4. مخطط الجدول الجديد (`attribute_changes` بدل `batch_uuid`) في مهاجرة واحدة |
| brick/math | 1.0.0 | نفسه | كل استدعاءاتنا بنصوص وأعداد صحيحة، وكل قسمة تحدد الخانات (فُحصت سطراً سطراً) |
| Guzzle | 7.16.1 | 8.3.1 | **لا ننتقل لـ 8 الآن:** ‏Reverb 1.12 يطلب `guzzlehttp/psr7 ^2.6`، وGuzzle 8 يطلب `^3.2`. ننتقل حين يدعمه Reverb |
| PHPUnit | 13.4.6 | نفسه | — |
| Larastan / PHPStan | 3.13.2 / 2.3.4 | نفسها | المستوى 5 |
| Pint / Collision | 1.32.1 / 8.9.5 | نفسها | — |

**ما تغيّر مع Laravel 13:**
- **حماية النماذج:** `VerifyCsrfToken` و`ValidateCsrfToken` صارا `PreventRequestForgery` (اللوحة وSanctum).
- **الكاش لا يعيد كائنات PHP** (`serializable_classes => false`)، والجلسة بصيغة JSON. الاثنان افتراض Laravel 13 للأمان: مفتاح تطبيق مسرّب لا يصير ثغرة تنفيذ.
- **خلل حقيقي ظهر ومنعناه:** إعداد التطبيقات (التحديث الإجباري ووضع الصيانة) كان يُخزَّن في الكاش كائنات. على Laravel 13 مع Redis كان سيتعطل في كل طلب من التطبيق. صار يُخزَّن أعمدة خاماً.
  - مخزن الاختبارات صار يسلسل القيم مثل Redis.
  - لذلك فشل اختبار «التحديث الإجباري» على الكود القديم، ونجح بعد الإصلاح.
- **سجل التدقيق:** خصائص «تعديل معلوماتي» صارت `{"fields": [...]}` بدل قائمة بلا مفاتيح، ولها اختبار.

## البيانات والبنية

| المكوّن | المستخدم | آخر مستقر | ملاحظة |
|---|---|---|---|
| MySQL | 9.7 LTS | 9.7 LTS | كانت المعمارية v1.4 على 8.4 LTS. الفحص الآلي على 9.7 |
| Redis | 8 | — | الفحص الآلي. **مفتوح:** المعمارية v1.4 تقول Dragonfly (متوافق مع Redis)، والتجربة عليه في مرحلة النشر قبل اعتماده |
| Qdrant (الخادم / العميل) | 1.20.0 / 1.20.0 | 1.20.0 | بلا تغييرات كاسرة. ‏`enforce_internal_auth` صار افتراضياً مع المفتاح |
| Nginx | 1.30 (الفرع المستقر) | 1.30.6 (14 أكتوبر 2026، [nginx.org](https://nginx.org/2026.html)) | من مستودع nginx.org لا من Ubuntu: نسخة Ubuntu 24.04 هي 1.24، ولا تعرف `http2 on;` (أُضيف في 1.25.1) الذي يستخدمه `deploy/nginx/zon.conf`. تحقّق صياغة الملف بـ `nginx -t` |
| chillerlan/php-qrcode | 5.0.5 | نفسه | رموز QR في الموقع (ترخيص الإعلان، والفاتورة). كان يصل عبر Filament، وصار طلباً صريحاً في `composer.json` كي لا يختفي إن تخلّت عنه Filament |
| sentry/sentry-laravel · sentry/sentry | 4.29.0 · 4.36.0 | نفسها (5 نوفمبر 2026) | تتبّع الأخطاء (`config/sentry.php`). خيار `data_collection` الجديد يحدد ما يُجمع صراحة بدل `send_default_pii` |

## خدمة الوكلاء

| المكوّن | المستخدم | آخر مستقر | ملاحظة |
|---|---|---|---|
| Python | 3.14 | 3.15 | **3.15 لا الآن:** ‏PyYAML 6.0.3 وwatchfiles 1.3.0 بلا حزم جاهزة لها على PyPI (فُحص 2 نوفمبر 2026). كل حزمنا لها حزم 3.14 لـ x86 وARM |
| uv | 0.13.6 | نفسه | `uv.lock` بدل `requirements.lock`: قفل واحد لكل المنصات |
| fastapi · uvicorn | 0.162.0 · 0.54.0 | نفسها | — |
| pydantic · pydantic-settings | 2.14.3 · 2.16.2 | نفسها | — |
| pydantic-ai-slim · mcp | 2.66.0 · 2.3.0 | نفسها (5 نوفمبر 2026) | — |
| qdrant-client · httpx | 1.20.0 · 0.28.1 | نفسها | — |
| sentry-sdk | 2.74.0 | نفسه (5 نوفمبر 2026) | تتبّع الأخطاء (`zon_agents/monitoring.py`): `data_collection`، و`send_default_pii` مهجور فيه |
| pytest · ruff · mypy | 9.2.0 · 0.17.4 · 2.4.1 | نفسها | — |

**عقدة الذكاء (RunPod):** النموذج ملف (`agents/zon_agents/models/<name>.toml`)، وملفه يحدد محركه (`deploy/ai-node/install.sh`):

| المحرك | الإصدار | ملاحظة |
|---|---|---|
| vLLM | 0.33.0 | الرسمي، آخر مستقر (5 نوفمبر 2026). عجلته تجلب torch المطابقة له |
| SGLang | 0.5.23 | الرسمي، آخر مستقر (5 نوفمبر 2026) |
| وقت تشغيل Escha | من مزوّده | لنسخة Escha-W2 وحدها: نسخة المزوّد من SGLang مربوطة بـ torch 2.9، فلا تُرقّى إلا معه |

وPython 3.12 على الـ Pod لكل البيئات، وخادم التضمين في بيئته المستقلة (torch 2.9 · FlagEmbedding 1.4.2).

## التطبيق

| المكوّن | المستخدم | آخر مستقر | ملاحظة |
|---|---|---|---|
| Flutter / Dart | 3.47.9 / 3.13.7 | نفسها (29 أكتوبر 2026) | مثبّت في `.github/workflows/app.yml` |
| الحزم المباشرة | — | — | كلها آخر إصدار (`flutter pub outdated`) |
| حزم غير مباشرة | — | — | ثلاث حزم مثبّتة بالـ SDK أو بإضافة: `cross_file` و`material_color_utilities` و`test_api` |

`pubspec.lock` محفوظ في المستودع: التطبيق يُبنى بالحزم نفسها في كل مرة.

## الفحص الآلي

الإصدارات في ملفات `.github/workflows/`، وDependabot يرفعها لآخر إصدار. أول طلب له رفع `actions/checkout` إلى 7 و`actions/cache` إلى 6، ودُمج بعد نجاح الفحص.

## التحديث

- **إصدار فرعي أو تصحيح:** طلب Dependabot الأسبوعي. يُدمج إذا الفحص الآلي أخضر.
- **إصدار رئيسي:**
  1. يُقرأ دليل الترقية الرسمي.
  2. يُعدَّل الكود.
  3. يُضاف اختبار لكل سلوك تغيّر.
  4. يُحدَّث هذا الملف.
- **Flutter:** رقم الإصدار في `app.yml`، ثم `flutter pub upgrade`، ثم هذا الملف.

## المصادر

- [دليل ترقية Laravel 13](https://laravel.com/docs/13.x/upgrade)
- [ترقية activitylog من 4 إلى 5](https://github.com/spatie/laravel-activitylog/blob/main/UPGRADING.md)
- [سجل brick/math](https://github.com/brick/math/blob/main/CHANGELOG.md)
- [إصدارات Qdrant](https://github.com/qdrant/qdrant/releases)
- [MySQL 9.7 LTS](https://blogs.oracle.com/mysql/mysql-9-7-0-lts-is-now-available-expanded-community-capabilities-and-dynamic-data-masking-for-enterprise)
- [Dependabot مع uv](https://docs.astral.sh/uv/guides/integration/dependabot/)
- إصدارات الحزم وحزمها لكل منصة: PyPI وPackagist وpub.dev، كما تطبعها تقارير «Outdated» في الفحص الآلي.
