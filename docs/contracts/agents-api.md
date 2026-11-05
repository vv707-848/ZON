# عقد Laravel ↔ خدمة الوكلاء

الطرف الثالث من الأطراف الثلاثة. Laravel وحده يكلّم الخدمة على `127.0.0.1:8100`، وكل نداء يمر بـ `App\Ai\AiGateway`: الإخفاء، والحدود، والمهلة، والمسار البديل، وسجل `ai_requests`.

- كل ما يصل الخدمة مُخفى الهوية، والخدمة لا تسجّل نص أي طلب.
- كل حقل غير معروف في المدخل يُتجاهل، فالإضافة على أحد الطرفين لا تكسر الآخر.

## 1. المسارات

| المسار | المصادقة | الرد |
|---|---|---|
| `POST /agents/{agent}` | `X-Internal-Key` | الشكل في §2 |
| `POST /index/{entity}` | HMAC — §4 | `{"status": "indexed" \| "deleted" \| "ignored_stale"}` |
| `POST /media/phash` | `X-Internal-Key` · جسم الصورة الخام (≤ 20 MB) | `{"phash": "<16 hex>"}` |
| `GET /agents/health` | بلا | `{"status":"ok","llm":bool,"embed":bool,"qdrant":bool,"model":"<ملف النموذج>"}` بكاش 5 ثوانٍ |
| `/mcp/` | `X-Internal-Key` | خادم MCP يكشف الأدوات نفسها (§5) |

- **ترويسة `X-Request-Id`:** رقم تتبّع طلب المستخدم (`App\Support\Trace`). الخدمة تعيده في ردها وتكتبه في سطر سجلها (`request_id`)، فيُتتبَّع الطلب نفسه في سجلات الطرفين وجدول `ai_requests`.
- **مهلة Laravel لكل نداء:** 20 ثانية، ثم المسار البديل مباشرة بلا إعادة. خطأ الاتصال السريع يُعاد مرة واحدة ثم البديل.
- **ميزانية الخدمة:** 15 ثانية للنموذج (`REQUEST_BUDGET_SECONDS`)، وبعدها رد `degraded`. والنداء كله ينتهي قبل مهلة Laravel بثانيتين على الأقل.
- **ساعتا الخادمين:** مضبوطتان بـ NTP، لأن توقيع الفهرسة يقبل فرقاً حتى 300 ثانية فقط.

## 2. شكل رد أي وكيل

```json
{
  "output":   { },
  "degraded": false,
  "usage":    {"tokens_in": 2480, "tokens_out": 212},
  "latency_ms": 2710
}
```

- `degraded: true` تعني أن الترتيب من البحث الهجين بلا نموذج، بدرجاته.
- **أخطاء HTTP:**

| الرمز | المعنى | ما يفعله Laravel |
|---|---|---|
| 401 | مفتاح خاطئ | — |
| 404 | وكيل غير معروف | — |
| 422 | مدخل لا يطابق المخطط | لا إعادة |
| 503 | التضمين أو Qdrant غير متاح | المسار البديل (ترتيب SQL) |

- ما يصل من النموذج يُتحقق منه قبل الرد، وكسر البنية يُعاد مرة واحدة بحرارة 0.1.
- **Laravel يتحقق مرة ثانية:**
  - كل `id` يجب أن يكون من قائمة المرشحين التي أرسلها، مرة واحدة.
  - لا يأخذ من النتيجة إلا `id` و`score` وما يقرؤه هو (`unit_id`)؛ أي حقل آخر يتوقف عنده.

## 3. الوكلاء ومخططاتهم

**قواعد مشتركة:**
- المرشحون يبنيهم Laravel في SQL، والخدمة لا تضيف عنصراً.
- السقف 10 نتائج (`limit` ≤ 10).
- الدرجة بين 0 و1.
- النموذج لا يكتب نصاً في الترتيب: كل نتيجة معرّف ودرجة، لأن التطبيق لا يعرض شرحاً تحت النتيجة. والمخطط الذي يُقيَّد به التوليد ليس فيه حقل نصي.
- لا رقم من النموذج.

### 3.1 الترتيب: `search_listings` · `matching` · `search_requests` · `match_searchers`

**الحاجة المُخفاة (`need`)** — مشتركة بين وكلاء الترتيب:

```json
{"city":"الرياض","neighborhood":"النرجس","property_type":"apartment","purpose":"sale",
 "budget_min":700000,"budget_max":950000,"bedrooms":3,"free_text":"دور أرضي بمدخلين ويقبل البنك"}
```

- `terms`: مرادفات قاموس المصطلحات المحلية للنص الحر (≤ 20)، وتُضاف إلى نص البحث المتناثر.

**مرشح عرض:**

```json
{"id":9012,"type":"apartment","neighborhood":"النرجس","price":880000,"area":150,"bedrooms":3,"bathrooms":2,"age":4,"deed":true,"bank":true,"desc":"…"}
```

**مرشح وحدة:** الحقول نفسها، ومعها `model` و`project_id`.

**مرشح طلب** (لبحث الوسيط في الطلبات):

```json
{"id":77,"types":["apartment"],"neighborhoods":["النرجس"],"budget_min":…,"budget_max":…,"rooms_min":…,"area_min":…,"urgent":true,"desc":"…"}
```

**`search_listings`**
- المدخل: `{need, terms, candidates[عرض], limit}`.
- المخرج: `{"results":[{"id","score"}]}`.

**`search_requests`**
- المدخل: `{need, terms, candidates[طلب], limit}`.
- المخرج: بالشكل نفسه.

**`matching`**
- المدخل: `{need, terms, matched[عرض], broaden[عرض], units[وحدة], limit}`.
- المخرج:
  ```json
  {"matched":[{"id","score"}], "broaden":[{"id","score"}], "units":[{"id","score"}]}
  ```
- قائمتا العروض لا تتقاطعان.
- `limit` يطبَّق على كل قائمة.
- الوحدات يرتّبها البحث الهجين بأوصافها دون النموذج، وLaravel يجمعها في بطاقات مشاريع.

**`match_searchers`** (المطوّر)
- المدخل:
  ```json
  {"project":{"type","neighborhood","context"},
   "units":[{"id","model","type","area","bedrooms","price","desc"}],
   "searchers":[{"id","type","budget_min","budget_max","bedrooms","notes"}],
   "limit":10}
  ```
- المخرج: `{"results":[{"id","score","unit_id"}]}`.
- `unit_id` النموذج الأقرب لكل باحث، أو `null`، وLaravel يُسقط أي `unit_id` ليس من نماذج المشروع.

### 3.2 `chat`

**المدخل:**

```json
{"lang":"ar|en|ur","intent":"knowledge|packages|semantic_search|search_request|matching",
 "message":"…","history":[{"q":"…","a":"…"}],"packages":[{"name","price","period","features":[…]}]}
```

- `intent` من مصنّف القواعد في Laravel. «خارج النطاق» لا يصل الخدمة أصلاً.
- `history`: آخر تبادل واحد فقط.
- `knowledge`: الخدمة تسترجع أعلى 5 مقاطع من `knowledge` (عابر للغات) وتجيب منها وحدها.
- `packages`: أرقام الباقات من Laravel حرفياً، والنموذج يصوغ فقط.

**المخرج:**

```json
{"answer":"…","covered":true,
 "sources":[{"doc_id":12,"title":"تملك غير السعوديين","effective_date":"2026-05-01","quote_ar":"…"}]}
```

- `covered:false` يعني تصريحاً بعدم التغطية وإحالة للجهة الرسمية، بلا تخمين.
- Laravel يمرر الرد على `ChatGuard`: لا تواصل، ولا رابط، ولا مبلغ لم تعطه المنصة، و«مغطى» بلا مصادر يُرفض.

### 3.3 `summarize`

- **المدخل:** `{"scope":"client|owner|tasks","notes":[{"category":"interested","date":"2026-08-01","text":"…"}]}`
  - آخر 60 ملاحظة ضمن 18,000 حرف.
  - النص مُخفى: «العميل» بلا اسم ولا رقم.
- **المخرج:** `{"summary":"…","signals":["كاش","…"],"recommended_action":"…","confidence":"high|medium|low"}`

### 3.4 التنقية: `clean_listing` · `clean_project` · `clean_units`

| الوكيل | المدخل | المخرج |
|---|---|---|
| `clean_listing` | `{"type","city","neighborhood","fields":{…الخانات},"text":"…"}` — `fields` كائن، ويُحذف حين لا خانات | `{"desc_clean":"…","features":["دور أرضي","مدخلان"]}` |
| `clean_project` | `{"type","text":"…"}` | `{"desc_clean":"…"}` |
| `clean_units` | `{"project_type","units":[{"id","model","type","area","bedrooms","bathrooms","halls","text"}]}` | `{"units":[{"id","desc_clean"}]}` — نداء واحد لكل نماذج المشروع |

قواعد التنقية:
- تُحذف أرقام التواصل والروابط والمبالغات.
- لا تُضاف سمة غير مذكورة.
- الأرقام لها خاناتها ولا تُكرَّر في النص.

### 3.5 الوكيل الأعلى

**`report_select`**
- المدخل: `{"question":"…","catalog":[{"report","description","params":{"city":{"type":"string","enum":[…]},"days":{"type":"integer","minimum":1,"maximum":365}}}]}`
- المخرج: `{"report":"top_demand_by_neighborhood","params":{…}}`، بمخطط JSON مقيّد كبقية الوكلاء: اسم التقرير من أسماء الكتالوج وحدها، فلا يحتاج المحرك محلّل أدوات خاصاً بعائلة النموذج.
- الكتالوج يعلن **كل** معاملات القالب.
- Laravel يتحقق من الاسم والمدى قبل أي تنفيذ.

**`report_write`**
- المدخل: `{"question","report","columns":{…},"rows":[…]}`
- المخرج: `{"analysis":"…","recommendations":["…"]}`
- كل رقم في النص من الصفوف، وإلا رفض `NumberGuard` في Laravel.

**`summarize_batch`**

| الوضع | المدخل | المخرج |
|---|---|---|
| map | `{"mode":"map","purpose":"أسباب «غير مطابق»","texts":["…" (≤ 500)]}` | `{"summary":"…","themes":[{"theme":"…","share":"high\|medium\|low"}]}` |
| reduce | `{"mode":"reduce","purpose":"…","summaries":["…"]}` | الشكل نفسه |

## 4. إشارة الفهرسة

```
POST /index/{entity}
X-Timestamp: 1786123456
X-Signature: sha256=<hex( HMAC_SHA256(INDEX_SHARED_SECRET, X-Timestamp + "." + body) )>
X-Request-Id: <trace id of the action that caused the signal>

{"entity":"listing","entity_id":9012,"action":"update","version":17,"occurred_at":"2026-08-22T09:14:22Z",
 "payload":{"city":"الرياض","neighborhood":"النرجس","property_type":"apartment","purpose":"sale","price":880000,
            "area_land":null,"area_built":150,"bedrooms":3,"bathrooms":2,"age":4,"deed":true,"bank":true,
            "desc_clean":"…","status":"published"}}
```

| `entity` | المجموعة | ما يُضمَّن |
|---|---|---|
| `listing` | `listings` | `desc_clean` |
| `project_unit` | `project_units` | `desc_clean` للنموذج. ويُخزَّن `project_context` (وصف المشروع) في حقل منفصل لا يُدمج، ومعه `project_id` و`model` |
| `request` | `requests` | `desc`. والحمولة تحمل `neighborhoods` و`property_types` مصفوفتين، و`urgent` |
| `kb_document` | `knowledge` | `body` مقطّعاً 400–600 توكن بتداخل بسيط |

- يحمل كل مقطع معرفة: `doc_id · title · category · lang · version · effective_date · source · review_owner · next_review`.
- معرّف المقطع ثابت لكل `(doc_id, chunk_no)`.
- نصوص العملاء (`desc_clean` · `project_context` · `desc`) تصل مقنَّعة (`AiMasking` في `IndexPayloads`)، لأن التضمين يجري على عقدة الذكاء: عرضٌ نُشر بنص صاحبه أو عدّله صاحبه قد يحمل رقماً. نصوص المعرفة رسمية من المنصة وتصل كما هي.

**`action`:**
- القيم: `create` · `update` · `delete`.
- `delete` بلا `payload`.
- كل ما ليس منشوراً يرسله Laravel `delete`.

**منع التكرار:**
- الخدمة تحفظ آخر `version` لكل `(entity, entity_id)`.
- نسخة ≤ المحفوظة تُهمل، وترد 200 `{"status":"ignored_stale"}`.

**الردود:**

| الرمز | المعنى |
|---|---|
| 200 | تم |
| 401 | التوقيع خاطئ أو الوقت خارج ±300 ث |
| 422 | الحمولة خاطئة، ولا إعادة |
| 503 | التضمين أو Qdrant غير متاح، وتُعاد المحاولة |

**في Laravel:**
- المهلة 5 ث اتصال و10 ث رد.
- 3 محاولات (10 ث · 60 ث · 300 ث).
- بعدها تبقى الإشارة معلّقة في `index_events` وتُعاد كل 15 دقيقة.
- حد الانتشار 60 ثانية = `acked_at − sent_at`.

## 5. أدوات MCP

`match_to_listings` · `match_to_units` · `match_searchers` · `semantic_search_listings` · `semantic_search_requests` · `search_knowledge` · `summarize_notes` · `clean_listing_text` · `summarize_batch`.

- تعريف واحد لكل أداة، تستعمله مسارات HTTP وخادم MCP معاً.
- `run_report` لا يُكشف عبر MCP: التنفيذ في Laravel على `ai_reader` وحده.

## 6. فحص العقد بالحمولات الفعلية

`docs/contracts/fixtures/` عيّنات حقيقية لما يرسله Laravel (حتى 5 لكل وكيل ولكل كيان فهرسة)، يسجّلها مجموع اختبارات الباك إند:

```bash
cd backend && ZON_CONTRACT_DIR=../docs/contracts/fixtures php artisan test
cd ../agents && .venv/bin/python -m pytest tests/test_backend_contract.py
```

- يُشترط أن تغطي العيّنات الوكلاء الاثني عشر وكيانات الفهرسة الأربعة.
- كل عيّنة يجب أن تقبلها نماذج الوكلاء نفسها (`zon_agents/schemas`).
- وكل مفتاح فيها يجب أن يكون له حقل في النموذج. النماذج تتجاهل المفتاح المجهول بصمت، فاسمٌ تغيّر في طرف واحد لا يظهر إلا هنا.
