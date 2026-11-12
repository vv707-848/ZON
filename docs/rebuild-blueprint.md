# زون — مخطط التنفيذ المعتمد (الباك إند · لوحة الإدارة · الذكاء)

سجلّ القرارات التي بُني عليها التنفيذ الأول قبل ضياع ملفاته في 1 نوفمبر 2026، ويُعاد البناء منه. **المرجع الحاكم يبقى `zone-architecture-full-v1_4.html`**؛ وما هنا تفصيل التنفيذ فوقه. ما لم أتأكد منه حرفياً مُعلَّم بـ «يُطابَق مع v1.4».

---

## 0. الأطراف الثلاثة والعُقد

| الطرف | أين يعمل | يكلّم |
|---|---|---|
| التطبيق (Flutter) | الجوال | `https://api.zon.sa/api/v1` (REST) · `wss://api.zon.sa/app/{key}` (Reverb) · روابط الصور الموقّعة `https://media.zon.sa` |
| الباك إند + لوحة الإدارة (Laravel · Filament `/admin`) | عقدة التطبيق — جدة | MySQL وQdrant على عقدة البيانات (10.10.0.20) · Dragonfly · MinIO · Reverb · خدمة الوكلاء 127.0.0.1:8100 · الجهات الخارجية |
| الذكاء: خدمة الوكلاء (Python) | عقدة التطبيق 127.0.0.1:8100 | محرك النموذج (vLLM أو SGLang، حسب ملف النموذج) `127.0.0.1:30000/v1` وbge-m3 `127.0.0.1:18080` عبر نفق SSH تفتحه عقدة RunPod صادراً · Qdrant |

- Laravel وحده يكلّم خدمة الوكلاء، وكل نداء يمر بـ `AiGateway` (إخفاء · حدود · مهلة · بديل).
- كل ما يحمل هوية يبقى في جدة؛ العابر للنموذج حمولة مُخفاة الهوية فقط.

## 1. المكدس

- **الباك إند:** Laravel 13 · PHP 8.5 · MySQL 9.7 LTS (الإصدارات الحالية وسببها في [`versions.md`](versions.md)) · Dragonfly متوافق Redis (كاش، عدّادات، طوابير) · MinIO (قرص s3) · Reverb · Sanctum 4.
- **لوحة الإدارة:** Filament 5 · Livewire 4 · spatie/laravel-permission · spatie/laravel-activitylog 5.
- **أدوات الكود:** brick/math للمبالغ والنطاقات · Larastan مستوى 6 (`parseModelCastsMethod: true`)، والاختبارات معها · Pint بإعداد laravel.
- **خدمة الوكلاء:** Python 3.14 · FastAPI · pydantic-ai-slim · mcp (‏MCPServer · streamable HTTP · stateless · JSON) · qdrant-client · httpx — بإدارة uv.
  - أدوات الفحص: ruff · mypy · pytest.

## 2. تخطيط المستودع

```
backend/app/
  Actions/{Accounts,Admin,Billing,Crm,Listings,Moderation,Owner,Projects,Requests,Social}   منطق كل فعل في صنف
  Ai/            AgentsClient · AiGateway · Masking/AiMasking · Quota/AiQuota · Retrieval/* · Assistants/* · Capture/*
  Console/Commands   zon:create-superadmin · zon:doctor · zon:reindex
  Enums/         كل قيمة مخزّنة = قيمة الـ API؛ label() عربي؛ trait Concerns (values, options)
  Exceptions/ApiException + Enums/ErrorCode
  Filament/      NavigationGroup · Concerns/AuthorizesByPermission · Support/{Staff,Filters,Decision,KeyValues} · Pages · Resources · Widgets
  Http/          Controllers/Api/V1 · Middleware · Requests/Api (ApiRequest) · Resources
  Indexing/      IndexSync · IndexPayloads
  Integrations/  Drivers · IntegrationException · Sms · Rega · Nafath · Payments · Push · Invoicing · ImageHash
  Jobs/ (+ Scheduled/)   Models/   Notifications/{AppNotice,Notices}
  Services/Billing/Entitlements (scoped لكل طلب)
  SuperAgent/    SuperAgent · Catalog · Template · MarketTemplate · Param · ReportRunner · NumberGuard · DailyCap · Archive · Proposal · NotesDigest · Templates/*
  Support/       Trace · Money · Actor · Ownership · ArabicText · Settings/{Setting,Settings} · Permissions/Permission · Health/*
agents/zon_agents/  main.py · config.py · pipelines/ · tools/ · llm.py · embeddings.py · store.py · indexing.py · schemas/ · prompts/*.md
docs/contracts/agents-api.md     العقد بين Laravel وخدمة الوكلاء
deploy/                          nginx · supervisor · cron · ssh · mysql · qdrant · backup · ai-node
```

## 3. اتفاقيات مشتركة

### مغلّف الرد

- **النجاح:** `{success:true, data, meta?}`، ويُحذف `meta` إذا كان فارغاً.
- **الخطأ:** `{success:false, error:{code, message, details, trace_id}}`.

### رموز الأخطاء (ErrorCode)

| HTTP | الرموز |
|---|---|
| 401 | `UNAUTHENTICATED` |
| 402 | `PAYMENT_REQUIRED` |
| 403 | `ROLE_FORBIDDEN` · `FAL_REQUIRED` · `NAFATH_REQUIRED` · `USER_BLOCKED` |
| 404 | `NOT_FOUND` |
| 409 | `INVALID_TRANSITION` · `ALREADY_ACTIVE` · `PROJECT_NO_UNITS` |
| 422 | `VALIDATION_FAILED` (التفاصيل في `details.fields`) |
| 426 | `UPDATE_REQUIRED` |
| 428 | `PREFERENCE_REQUIRED` |
| 429 | `QUOTA_EXCEEDED` |
| 502 | `INTEGRATION_FAILED` |
| 503 | `MAINTENANCE_MODE` |

- `USER_BLOCKED` يُرد برسالة عامة لا تكشف الحظر.
- `INTEGRATION_FAILED`: اسم الجهة في السجل فقط، لا في الرد.
- `AI_UNAVAILABLE` داخلي، لا يصل للمستخدم أبداً.

### التتبّع (App\Support\Trace)

- **الثوابت:**
  - `KEY='trace_id'` و `HEADER='X-Request-Id'`.
  - `PATTERN='/^[A-Za-z0-9._-]{8,64}$/'`: يُعتمد معرّف التطبيق إن طابقه، وإلا يُولَّد ULID.
- **الإسناد:** وسيط `AssignTraceId` عام (prepend)، ويعيد `X-Request-Id` في كل رد. وكل خطأ يحمل `error.trace_id`.
- **أين ينتقل الرقم نفسه:**
  - خدمة الوكلاء: ترويسة `X-Request-Id`، والرقم نفسه في كل محاولة.
  - الجداول: `ai_requests.trace_id` · `index_events.trace_id` (يبقى مع إعادة الإرسال).
  - سجل التدقيق: `activity_log.properties.trace_id` عبر `Activity::creating` في AppServiceProvider.
  - Laravel Context، فيصل إلى كل سطر سجل وكل مهمة في الطابور.
  - سطر سجل nginx: `$sent_http_x_request_id`.
- **من لوحة الإدارة:** صفحة «تتبّع طلب»، وفلتر «رقم التتبع» في سجل نداءات الذكاء ومراقبة الفهرسة وسجل التدقيق.

### المصادقة

- `web` = موظفو اللوحة (users).
- `api` = Sanctum على مزوّد `customers`.
- المتحكمات تقرأ `$request->user('api')`، والاختبارات تستعمل `Sanctum::actingAs($c, ['*'], 'api')`.

### التحقق والقيم

- **ApiRequest:** خصائص `$numeric` و`$phones` و`$ranges`. أزواج min/max تُفحص في `after()` بـ BigDecimal، و`record()` يعيد السجل المعدّل.
- **رسائل التحقق:** عربية في `lang/ar/validation.php`، ولا مجلد `lang/en`.
- **المبالغ:** بالهللة (int) عبر `Money::sar · withoutRate · minus · percent`.
  - كل سعر معروض شامل.
  - الضريبة تُفصل عند الدفع فقط وما دامت المنصة مسجّلة ضريبياً (إعداد).
  - رسوم الترخيص الإعلاني تمرير للهيئة بلا ضريبة.

### الإعدادات

- القيم التجارية في قاعدة البيانات لا في الكود: الباقات، وجدول settings عبر enum `Setting` بقيمة افتراضية وعنوان ومجموعة. تُعدَّل من «إعدادات المنصة».
- أمثلة: `ChatBlockedTerms` · `ChatMaxChars` · `ReviewAutoPublishHours` · `PreferenceActiveDays` · `MarketReportMinRequests`.

### الصرامة

- خارج الإنتاج: `preventLazyLoading` و`preventSilentlyDiscardingAttributes`.
- `Relation::enforceMorphMap`: ‏customer, user, listing, project, project_unit, inventory_item, request, package, subscription, payment, owner_case, ad_license, marketing_contract, commission_due, kb_document, content_report, city, neighborhood, search_term, app_config, setting, announcement, index_event, deletion_request, invoice, boost_usage, exec_report, role, permission.

### حدود المعدّل

| الحد | القيمة | المفتاح |
|---|---|---|
| api | 120/دقيقة | الحساب أو العنوان |
| login | 5/دقيقة | — |
| otp | 3/10 دقائق · 10/ساعة | الرقم · العنوان |
| messages | 30/دقيقة | — |
| reports | 20/ساعة | — |

## 4. البيانات — الجداول كما بُنيت (يُطابَق مع v1.4 حرفياً)

**الحسابات**
- customers: الدور seeker · broker · owner · developer، وحالة الحساب، و`anonymized_at`، وحالة فال ونفاذ.
- users: الموظفون؛ superadmin، والمصادقة الثنائية TOTP ورموز الاسترداد.
- deletion_requests · user_blocks.

**العروض**
- listings:
  - الحالة ونية الإعلان `listing_intent`.
  - `text_review` (Pending · Ready · Approved · Edited · Rejected · Auto).
  - `desc_raw` · `desc_suggested` · `desc_clean` · `text_reviewed_at`.
  - `index_version` · `image_hash` · lat/lng · `paused_until`.
  - `soft deletes`.
- listing_history · media (polymorphic).

**المشاريع**
- projects (`developer_id`).
- project_units:
  - `model_name` · `available_count` · `text_review`.
  - السعر الابتدائي محسوب ولا يُخزَّن.

**الطلبات والوسيط**
- requests: عادي أو عاجل، و`neighborhoods` و`property_types` بصيغة JSON، و`budget_min` و`budget_max`.
- buyer_preferences.
- crm_clients · client_notes · property owners وملاحظاتهم · tasks (`remind_at` · `reminded_at`).
- inventory_items (`published_listing_id`).

**المال**
- packages · subscriptions (`source`، و`renewal_reminded_at`، وفهرس على `ends_at`).
- payment_transactions (`public_id` · `purpose` · `status`، ومنها `unapplied`) · invoices · invoice_counters.
- boost_usages (`via` · `active_until` · `ended_notified_at`).

**المالك والهيئة**
- owner_cases · ad_licenses · marketing_contracts · commission_dues.

**المعرفة والذكاء**
- kb_documents: title · category · lang · version · effective_date · source · review_owner · next_review · body · is_active.
- search_terms · search_misses · search_feedback.
- ai_requests: لا نص شخصي، بصمة المدخل المُخفى فقط، و`trace_id`.
- note_summaries · chat_messages · training capture (مُخفى).

**الفهرسة والإحصاء والتقارير**
- index_events: `entity` · `entity_id` · `action` · `version` · `payload` · `payload_hash` · `sent_at` · `acked_at` · `attempts` · `last_error` · `trace_id`.
- daily_area_stats · daily_metrics · exec_reports · مشاهد `ai_v_*`.

**التواصل**
- conversations · participants (`hidden_at`) · messages · favorites · follows · notifications.

**الإدارة**
- content_reports · app_configs · settings · announcements · cities · neighborhoods.

## 5. تدفقات المجال

### دورة حياة العرض

1. يُنشأ العرض مسودة، ثم تعمل مهمة `CleanListingText` على طابور ai.
2. يصير `pending_review` بحالة نص `Ready`، فيقرّر المُعلن: اعتماد، أو تعديل (يُلتقط مُخفى للتعلّم)، أو رفض ← يُنشر.
3. **المهل:**
   - تذكير بعد 24 ساعة.
   - بعد 72 ساعة يُنشر بنصّه الأصلي (`Auto`)، ويبقى زر المراجعة متاحاً؛ القرار المتأخر يغيّر النص المفهرس فقط.
4. **بعد النشر:**
   - published ⇄ paused حتى `paused_until`، ويعود آلياً.
   - يصير غير نشط بعد الخمول.
   - sold نهائي، وhidden قرار إشراف.
- كل انتقال في معاملة واحدة عبر `ChangeListingStatus`: الحالة + `listing_history` + `index_version` + إشارة فهرسة.

### بوابات النشر

- الدور يسمح بالنشر.
- الوسيط برخصة فال سارية.
- نفاذ مرة واحدة.
- يتسع العرض في الباقة (`Entitlements`).
- ترخيص الهيئة عند الإنشاء:
  - للمالك: `OwnerCaseFlow::licenceFor`.
  - المشروع الجاهز: ترخيص لكل وحدة.

### المشاريع

- يُنشئ المشرف حساب المطوّر، ويُفعَّل بعقد التسويق.
- مراجعتان: نص المشروع ثم نصوص النماذج.
- النشر بلا نموذج ← `PROJECT_NO_UNITS`.
- الوحدة تُفهرس فقط بنص محسوم و`available_count > 0`.

### الطلبات

- الطلب عادي (مجاني) أو عاجل (مدفوع).
- `NotifyAreaBrokers` عند النشر.
- وصول الوسيط لطلبات المنطقة حسب `AreaRequestsAccess` (none · preview · full).
- «إضافة إلى العملاء» تتطلب وصولاً كاملاً.

### الرغبات

- الباحث بلا رغبة ← 428.
- `NotifyWishMatches` مرتين يومياً (9 و18).

### الفوترة

1. checkout: السعر من الخادم، ويُعاد استعمال الدفعة المعلّقة خلال 30 دقيقة.
2. يدفع التطبيق بـ SDK المزوّد.
3. التأكيد بقراءة المزوّد `fetch()`:
   - اختلاف المبلغ ← failed.
   - غياب الهدف ← unapplied.
4. الفاتورة بمهمة `IssueInvoice`، ورقمها من `invoice_counters` بقفل صف.

- **فترات الباقة نفسها تتتابع:** الدفع مقدماً أو المنح يبدأ من نهاية آخر فترة لها (ولو لم تبدأ بعد)، بقفل صف الحساب، فلا تُشترى مدتان في وقت واحد (`Subscription::nextStart`).
- **التمييز** (`ApplyBoost`): من الرصيد أو بالدفع أو بالمنح؛ تمييز ساري ← `ALREADY_ACTIVE`.
- **الاسترداد** من اللوحة أو من ميسّر أو من المتجر يوقف ما اشترته الدفعة وحدها: تمييز قديم انتهى لا يطفئ تمييزاً أحدث على العرض نفسه (`EndPurchase`). و**العمولة** مستحقة عند البيع.

### مسار المالك

عقد عبر الهيئة ونفاذ ← رسوم ← ترخيص عند إنشاء العرض ← نشط. والمتابعة آلية كل 10 دقائق.

### الإشراف

- **البلاغات:** بلاغ واحد لكل مُبلِّغ وهدف، و3 مُبلِّغين مختلفين ← إخفاء آلي.
- **`HandleReport`:** رفض أو إخفاء أو حظر أو إيقاف، في معاملة.
- **الحظر** في طبقة الاستعلام:
  - المحظور لا يعلم.
  - رسائله تُرفض برسالة عامة.
  - ملف الحاظر يرد 404 للمحظور.
- **حسابات المراجعة (`is_tester`) في عزل** (`Sandbox`):
  - `Blocks::hiddenFor($viewer)` يضم حسابات المراجعة لكل مشاهد ليس منها (والزائر)، فكل استعلام يحترم الحظر يحترم العزل. `Blocks::hides($viewer, $owner)` للعنصر الواحد، و`Blocks::between` للحظر وحده (رفض الرسائل).
  - إشعارات عن محتوى غيره (المتابعون، وسطاء المنطقة، تطابق الرغبات): `Sandbox::audience` يقصرها على حسابات المراجعة إن كان صاحب المحتوى منها.
  - محادثته مع حساب حقيقي: صف الطرف الآخر مخفي من إنشائه (`hidden_at`)، ولا بث ولا إشعار. نجومه ومتابعاته لا تُحسب إلا لحسابات المراجعة. بلاغه لا يُحسب في الإخفاء الآلي. مشاهدته لا تُعد.
  - لا تُسجَّل له عينة تدريب ولا «بحث بلا نتيجة» ولا «مطابق/غير مطابق». الأرقام اليومية ومشاهد الوكيل الأعلى تستبعده (`2026_11_03_000100`).
  - يصنعه المدير العام وحده (`MakeReviewAccount`)، ولا يُعلَّم حساب حقيقي.

### حذف الحساب

- مهلة 30 يوماً، والعروض تُخفى فوراً.
- محادثات الأطراف الأخرى تُخفى بـ `hidden_at = requested_at` (بالثانية).
- الدخول خلال المهلة يلغي الطلب ويعيد ما أُخفي لحظتها فقط.
- لا يُنشر شيء خلال المهلة: كل نشر (آلي، أو من موظف، أو استعادة من الإشراف) يصير «مخفي» ويُسجَّل في الطلب، فيعود عند الإلغاء.
- بعد المهلة يُجرَّد الحساب من الهوية، ويبقى سجل عروضه ومشاريعه وطلباته بلا أرقام ولا بريد ولا روابط ولا اسمه.

### التواصل

- المفضلة، والمتابعة، والملف العام (للمُعلنين فقط؛ الباحث مخفي).
- محادثة واحدة لكل زوج وموضوع، بقفل صفّي الحسابين.
- حدث `MessageSent` عبر Reverb، وجرس الإشعارات.

### CRM

- عملاء وملاحظات مصنّفة، وملاك وملاحظاتهم.
- مهام بتذكير.
- مخزون لا يُفهرس أبداً؛ «نشر كإعلان» يمر بمسار النشر العادي بنسخ الصور، وبقفل لكل عنصر.

## 6. الذكاء داخل Laravel

### الاستدعاء

- **`AgentsClient`:** يستعمله `AiGateway` وحده (اختبار معماري).
  - الترويستان: `X-Internal-Key` و`X-Request-Id`.
  - المهلة 20 ث بلا إعادة بعدها، وخطأ الاتصال السريع يُعاد مرة واحدة.
- **مراحل `AiGateway`:**
  1. الإخفاء (`AiMasking`).
  2. النداء.
  3. تصفية المعرّفات على قائمة المرشحين، وأخذ المعرّف والدرجة وحدهما من كل نتيجة.
  4. كاش 15 دقيقة على بصمة الطلب المُخفى.
  5. الخصم من الرصيد عند `ok` فقط.
  6. صف في `ai_requests`.
  7. المسار البديل عند الحاجة.
- **النتيجة سجلٌّ ودرجته:** التطبيق لا يعرض شرحاً تحت النتيجة، فالنموذج لا يكتب في الترتيب نصاً.
- **`meta.ai`:** `{status: ok|cached|degraded|fallback|timeout|rejected|skipped}`.

### الحدود (`EnforceAiQuota`)

- **الطبقات الثلاث:** الحساب، والجهاز ×1.5، والعنوان ×3. تُحجز ذرّياً بـ Lua على اتصال Redis `quota`، ويُحرَّر الحجز عند الفشل أو الكاش.
- **الترويسات:** `X-AI-Limit` · `X-AI-Remaining` · `X-AI-Resets-At`.
- **402 و429** يحملان عروض الترقية `{package, name, price, daily}`.
- السقوف من الباقة.

### الاسترجاع

- **`Need`:** المدينة والحي والنوع والنية؛ الميزانية تُرتّب ولا تستبعد؛ الغرف؛ نص حر ≤ 1000.
- **`QueryExpander`:** مرادفات `search_terms`، بكاش `zon.search_terms` وحد 20.
- **`ListingCandidates`:**
  - المنشور فقط، بمطابقة المدينة والحي والنية.
  - يستبعد عروض الطالب ومن حظرهم.
  - الترتيب `ABS(price - target)` ثم الأحدث، وLIMIT 300.
  - التوسيع (أنواع أخرى، سعر ≤ الحد الأعلى) فقط حين يُعطى حد أعلى.
- **`UnitCandidates`:** للبيع فقط، من مشاريع منشورة، بالنوع، ومتاحة، وبنص محسوم.
- **`RequestCandidates`:** للوسيط (JSON contains)، والعاجل أولاً.
- **`ProjectAudience`:** للمطور؛ الباحثون برغباتهم، والوسطاء بعدد عملائهم فقط.

### المساعدات

- **`MatchingAssistant`:** ثلاث قوائم (matched · broaden · projects) + `suggest_request`. بلا مرشحين ← تسجيل بحث فاشل بلا نداء.
- **`SemanticSearch`:** العروض والطلبات.
- **`DeveloperMatching`:** الباحثون، بتحقق `unit_id` (المخترع يصير null)، والوسطاء.
- **`ChatAssistant`:**
  1. قائمة المنع.
  2. كشف اللغة (ar · en · ur).
  3. مصنّف نية بالقواعد، بالأولوية: packages > search_request > matching > knowledge > semantic_search. خارج النطاق ← رد ثابت بلا نداء.
  4. نداء بسياق مغلق.
  5. `ChatGuard`: لا أرقام تواصل ولا روابط؛ لا مبلغ لم تعطه المنصة؛ «مغطى» يتطلب مصادر.
  6. رد الباقات حتمي حين يتعطل الذكاء.
  7. الحفظ في `chat_messages`.
- **`NoteSummarizer`** (client · owner · tasks):
  - بصمة sha1 للملاحظات المُخفاة؛ إن لم تتغير يُعاد الملخص المحفوظ مجاناً.
  - آخر 60 ملاحظة ضمن 18,000 حرف.
  - 502 حين لا يصلح رد الذكاء.
- **`SearchFeedbackRecorder`:** السبب إلزامي عند «غير مطابق».

### مسارات `/api/v1/ai` (داخل مجموعة الرغبة الإلزامية)

| المسار | الصلاحية والحد |
|---|---|
| `GET quota` | — |
| `POST search` | `ai.quota:search` |
| `POST search/requests` | broker، وحد search؛ 402 بلا ميزة البحث في الطلبات |
| `POST matching` | seeker و developer، وحد matching |
| `POST matching/projects/{project}/searchers` | developer، وحد matching |
| `GET matching/projects/{project}/brokers` | developer |
| `POST chat` | `ai.quota:chat` |
| `GET chat` | — |
| `POST summaries` | `ai.quota:summary` |
| `POST feedback` | — |

### الفهرسة (Outbox)

- **`IndexSync`:**
  - تسجّل الإشارة داخل معاملة التغيير.
  - التكرار يُمنع ببصمة الحمولة، والنسخة = `index_version + 1`.
  - `forcing()` لـ `zon:reindex --force`.
- **`PushIndexEvent`** على طابور `index` بعامل واحد مرتّب:
  - توقيع HMAC: `X-Timestamp` + `X-Signature: sha256=…`.
  - مهلة 5 ث اتصال و10 ث رد.
  - 3 محاولات (10 ث · 60 ث · 300 ث)، وخطأ 4xx لا يُعاد.
- **`RetryPendingIndexEvents`** كل 15 دقيقة: المعلّق منذ ≥ 5 دقائق، الذي لم يُرسل أو أُرسل قبل ≥ 15 دقيقة، دفعات 500.

## 7. خدمة الوكلاء (Python)

### المسارات

| المسار | المصادقة |
|---|---|
| `POST /agents/{agent}` | `X-Internal-Key` |
| `POST /index/{entity}` | HMAC، وفرق توقيت ≤ 300 ث |
| `POST /media/phash` | `X-Internal-Key` |
| `GET /agents/health` | بلا |
| `/mcp/` | `X-Internal-Key` |

- `/index/{entity}`: النسخة ≤ المحفوظة تُهمل وترد 200 `ignored_stale`.
- `/agents/health` يرد `{status, llm, embed, qdrant}` بكاش 5 ث.

### الوكلاء الثلاثة عشر

search_listings · search_requests · matching · match_searchers · chat · summarize · clean_listing · clean_project · clean_units · report_select · report_explore · report_write · summarize_batch.

### الإعدادات (`.env`)

- `HOST=127.0.0.1` · `PORT=8100`.
- `LLM_BASE_URL=http://127.0.0.1:30000/v1` · `LLM_MODEL=zon-ai`.
- `LLM_MAX_CONCURRENCY=8` · `REQUEST_BUDGET_SECONDS=15`.
- `EMBED_URL=http://127.0.0.1:18080`.
- `QDRANT_URL` · `QDRANT_API_KEY`.
- `COLBERT_ENABLED` · `TURBO_BITS=bits4` · `KNOWLEDGE_TOP_K=5` · `KNOWLEDGE_MIN_SCORE=0.45`.

### Qdrant

- المجموعات: `listings` · `project_units` · `requests` · `knowledge`.
- **المتجه الكثيف:** 1024 cosine، مكمَّم TurboQuant bits4 في الذاكرة.
- **المتجه المتناثر**، و**ColBERT:** MaxSim، `m=0`، على القرص، int8، لكل المجموعات عدا knowledge.
- الأوامر: `zon-agents bootstrap [--recreate]` · `zon-agents serve`.

### معاملات التوليد

| الاستعمال | temp | top_p | top_k | max_tokens |
|---|---|---|---|---|
| وكلاء JSON | 0.3 | 0.8 | 20 | 700 |
| التلخيص | 0.4 | 0.8 | 20 | 600 |
| تقارير الوكيل الأعلى | 0.4 | 0.8 | 20 | 900 |
| الشات | 0.7 | 0.8 | 20 | 500 |

- الشات وحده بـ `presence_penalty 1.5`.
- لا `enable_thinking`.
- إعادة واحدة بحرارة 0.1.

### مقاطع المعرفة

- 400–600 توكن.
- يحمل كل مقطع: doc_id · title · category · lang · version · effective_date · source · review_owner · next_review.
- الاسترجاع يستبعد ما فات موعد مراجعته.

## 8. لوحة الإدارة (Filament 5)

### الدخول والحماية

- المسار `/admin`.
- التسجيل الإلزامي في تطبيق المصادقة قبل أي شاشة، مع رموز استرداد.
- `ADMIN_ALLOWED_IPS` (وسيط `RestrictAdminIps`): وسيط دائم (`isPersistent`)، فيُطبَّق على كل تحديث Livewire للشاشة، لا على فتحها الأول فقط. واختبار يرسل تحديثاً بالجلسة نفسها من عنوان آخر فيُرفض.

### الأدوار والصلاحيات

- الدور الأولي: «مشرف» (`Permission::supervisor()`): البلاغات، والشكاوى والمراسلات، ومراسلة المستخدمين باسم «فريق زون»، وتحويل العرض بين «نشط» و«غير نشط» بسبب، وإيقاف الحسابات وفك إيقافها.
- صلاحيات للمدير العام وحده لا يحملها أي دور (`Permission::superadminOnly()`): محتوى المستخدمين الخاص (المحادثات وإدارة العملاء)، والمال والأسعار، وإعدادات المنصة، وسجل التدقيق.
- الصلاحيات (`Permission` enum): dashboard.view · customers.view/manage/suspend/message · developers.create/activate · listings.view/moderate/activate · requests.view/moderate · reports.handle · packages.manage · payments.view/manage · knowledge.manage · search_terms.manage · ai.view · index.manage · health.view · app_config.manage · settings.manage · reference.manage · announcements.send · audit.view.
- خارج أي دور، للـ superadmin وحده: الوكيل الأعلى، وتقارير السوق، ومختبر النماذج، وحسابات الموظفين، والأدوار.

### الأقسام والشاشات

- **أقسام القائمة:** الرئيسية · الحسابات · المحتوى · الإشراف · المالية · المعرفة والمرجع · التشغيل · الإدارة.
- **الموارد:**
  - Activities (سجل التدقيق) · Announcements · AppConfigs · BoostUsages · Cities وأحياؤها · CommissionDues.
  - Customers: إيقاف وتفعيل، وحسابات المراجعة (إنشاء وكلمة سر جديدة، للمدير العام)، وحالة فال ونفاذ.
  - DeletionRequests · Developers (إنشاء وتفعيل بعقد) · IndexEvents (إعادة إرسال) · Invoices · KbDocuments · Listings · MarketingContracts · Packages.
  - Payments (استرداد) · Projects · Reports (البلاغات) · Roles · SearchRequests · SearchTerms · Subscriptions (منح).
  - Users (الموظفون؛ للـ superadmin) · AiRequests.
- **الصفحات:**
  - Dashboard، بودجات: AccountsOverview · ContentOverview · FinanceOverview · FollowUpOverview · ActivityChart · SignupsChart · RevenueChart · DailyMetricsChart · AiToday · AiCallsChart.
  - AiMonitor (7/30 يوماً): AiUsageByDay · TopSearchMisses · MismatchReasons.
  - ManageSettings · HealthMonitor · TraceLookup · صفحة الوكيل الأعلى (superadmin).

### الأفعال والمهام المساندة

- AuditLog · ModerateAccount · ActivateDeveloperAccount · RetryIndexEvents · UpdateSettings · HandleReport.
- ComputeDailyMetrics (00:15) · SendAnnouncement (طابور notify) · `zon:create-superadmin`.

## 9. الوكيل الأعلى

- **اتصال القراءة:** `ai_reader` (`REPORT_DB_*`) بصلاحية SELECT على مشاهد `ai_v_*` فقط (`deploy/mysql/ai_reader.sql`). ممنوع أي صلاحية على الجداول الأصلية.
- **القوالب:**
  - **15 تشغيلياً:** TopDemandByNeighborhood · TopPropertyTypes · SupplyVsDemand · ListingFunnel · UserGrowth · BrokerActivity · MatchingPerformance · SubscriptionsSummary · PriceTrend · TimeToSell · SearchNoResults · UserFunnel · RevenueSummary · DeveloperProjectPerf · BrokerClients (عملاء الوسطاء وملاحظاتهم، أعداداً).
  - **7 للسوق:** DemandSupplyGap · RealBudgetIndex · UnmetFeatures · RejectionReasons · ClientDemand (طلب عملاء الوسطاء لكل حي ونوع) · RegionDemandSupply (الطلب لكل عرض في كل منطقة إدارية) · CityDemandSupply (مدن منطقة).
  - تقارير السوق تستبعد من رفض المشاركة، ويُرفع حدها الأدنى للطلبات ولا يُخفض. والحد لكل صف: صف المنطقة يجمع كل مدنها، فمدينة تحت الحد في جدول المدن داخلة في مجموع منطقتها.
- **خريطة السوق** (أعلى «تقارير السوق»): المناطق الـ13 ملوّنة بالطلب لكل عرض من RegionDemandSupply، والنقر على منطقة يعرض مدنها من CityDemandSupply.
  - تقرأ القالبين مباشرة على المشاهد: بلا نموذج، ولا أرشيف، ولا سقف يومي.
  - المنطقة خاصية مخفية في `cities.region` (رمز ISO 3166-2)، لا تصل التطبيق.
  - الحدود من Natural Earth (ملكية عامة)، مبسّطة بـ `_tools/saudi_regions.py` في `backend/resources/geo/saudi-regions.json`.
  - **الحي** معامل اختياري (`Param::neighborhood`) في PriceTrend وTimeToSell وClientDemand. يُتحقق منه هنا بالمرجع، ولا تُرسل قائمة الأحياء كلها للنموذج.
- **دفتر العملاء:** `ai_v_crm_clients` و`ai_v_crm_notes` أعداد وأماكن وأنواع وميزانيات، بلا اسم ولا جوال ولا وصف ولا كلمة من ملاحظة، وبلا حسابات المراجعة.
- **قراءة ملاحظات العملاء** (`NotesDigest`، للمدير العام وحده): المدينة والحي والنوع والتصنيف والمدة. كل ملاحظة مُخفاة الاسم والأرقام. لا تُقرأ أقل من 20 ملاحظة. `summarize_batch` بدفعات 500 ثم تجميع. يعود ملخص وموضوعات فقط. القراءة في سجل التدقيق (المرشحات والعدد، لا الكلمات)، وتُحفظ في الأرشيف ضمن السقف اليومي.
- **الأنماط:**
  - **سؤال:** رسالة من سؤال إلى ثلاثة. `report_select` يختار تقريراً لكل سؤال، أو لا شيء حين لا يجيبه تقرير.
  - **استكشاف:** `report_explore` يختار تقريراً بعد تقرير مما وجده السابق، أربعة على الأكثر، ويتوقف حين تتضح الصورة. وما يكتبه المدير يوجّهه، ولو تركه فارغاً نظر في المنصة كلها.
  - **يدوي:** المدير يختار القالب ومعاملاته بلا نداء ذكاء. و«تقارير السوق» يدوية بتحليل.
- **التدفق:**
  1. الأرشفة أولاً «قيد الإعداد» (`Archive`)، ضمن السقف اليومي، وتقرير واحد قيد الإعداد لكل مدير.
  2. المهمة `RunExecReport` (طابور `ai`، حتى 300 ثانية): اللوحة لا تنتظر الذكاء، والصفحة تتحدّث كل ثانيتين حتى يكتمل. سبب ذلك: nginx يقطع طلب اللوحة بعد 30 ثانية، والسؤال وحده نداءان قد يبلغ كل منهما 20 ثانية.
  3. الاختيار: `report_select` أو `report_explore`.
  4. التحقق من المعاملات (`Param`): اسم أو رقم خارج المدى يُرفض ولا يُعدَّل.
  5. SQL على المشاهد (`ReportRunner`)، لكل تقرير جزؤه في `exec_reports.parts`.
  6. `report_write`: تحليل واحد لكل الأجزاء، وتوصيات قد يحمل كل منها فعلاً مقترحاً (`Proposal`).
  7. `NumberGuard`: كل رقم في النص من صفوف الأجزاء أو معاملاتها أو عدد صفوفها، صغيراً كان أو كبيراً.
- **الأفعال المقترحة** (`Proposal`): إشعار عام لدور في مدينة · مراجعة العروض · مراجعة الطلبات · مراجعة الحسابات · مراجعة الباقات · مراقبة الذكاء. كل فعل زر يفتح شاشته في اللوحة على المرشح أو النموذج المناسب، ولا يظهر لمن لا يملك فتحها. الوكيل يقرأ ولا يعدّل شيئاً.
- **حدود:** سقف 30 تقريراً يومياً (الاستكشاف تقرير واحد). تقرير بقي «قيد الإعداد» أكثر من 6 دقائق يُعرض متعذراً.

## 10. المهام المجدولة (`routes/console.php` — بتوقيت الرياض · `onOneServer` · كلها idempotent)

| المجموعة | المهمة | التوقيت |
|---|---|---|
| الصحة | SchedulerHeartbeat (`zon.heartbeat`) | كل دقيقة |
| دورة العروض | RemindPendingReviews | كل ساعة |
| | AutoPublishPendingReviews (والوحدات Ready في مشاريع منشورة) | :05 |
| | ResumePausedListings | كل 15 د |
| | InactivateStaleListings | 03:30 |
| | EndBoosts | :10 |
| الفهرس | RetryPendingIndexEvents | كل 15 د |
| | ExpireKnowledgeReviews | 00:30 |
| المستخدمون | SendTaskReminders | كل 5 د |
| | NotifyWishMatches | 9 و18 |
| | RemindSubscriptionRenewals | 10:00 |
| الهيئة | SyncOwnerContracts | كل 10 د |
| | RecheckBrokerLicenses | 04:00 |
| الإحصاء | ComputeDailyMetrics | 00:15 |
| | ComputeDailyAreaStats (‏JSON_TABLE + وسيط بدوال النافذة) | 01:00 |
| الخصوصية | PurgeDueAccounts | 02:30 |
| | PruneOrphanMedia | 03:00 |
| | sanctum:prune-expired | 03:15 |
| | queue:prune-failed | أسبوعياً |
| | activitylog:clean | شهرياً |

### الطوابير

| الطابور | العمّال | ملاحظة |
|---|---|---|
| index | عامل واحد | بالترتيب |
| ai | عاملان | — |
| notify · default · media | معاً | عمّال عامون |

## 11. التكاملات (`config/services.php` · `App\Integrations\Drivers`)

| الخدمة | السائق | الحي |
|---|---|---|
| sms | fake | taqnyat |
| rega | fake | عند منح الهيئة الواجهة |
| nafath | fake | بعد التعاقد |
| payments | fake | moyasar |
| push | fake | fcm |
| invoicing | local | ZATCA المرحلة الأولى |
| image_hash | gd | agents |

- **قاعدة الإنتاج:** المحاكاة مرفوضة في الإنتاج إلا لخدمة مسمّاة في `PRODUCTION_ALLOWS_FAKE` (مثل `rega,nafath`). القيمة فارغة في `.env.example`: السماح قرار المالك قبل الإطلاق (`docs/integrations.md`).
- **الانتقال للحي:** صنف سائق + سطر في `IntegrationServiceProvider` + قيمة في `.env`.
- **السائقات الحية مختبرة على ردود بشكل توثيق المزوّد:**
  - Taqnyat: Bearer، والرقم بلا `+`.
  - Moyasar: Basic بالمفتاح السري، و`secret_token` في الـ webhook يُقارن بزمن ثابت.
  - FCM HTTP v1: JWT بحساب الخدمة، وتُعاد الرموز `UNREGISTERED`.

## 12. التشغيل

- **`php artisan zon:doctor [--json]`:** ينتهي بـ 1 عند أي عطل. يفحص:
  - app: المفاتيح وAPP_DEBUG والعناوين المسموحة.
  - database · migrations · redis.
  - scheduler: النبضة، وأقصى عمرها 180 ث.
  - queues: عتبات لكل طابور (index 500 · ai 100 · notify 1000 · media 200 · default 500).
  - failed_jobs: آخر 24 ساعة.
  - agents: `/agents/health`.
  - index: الفاشل، والمعلّق أكثر من 30 دقيقة.
  - report_db · storage (كتابة وحذف) · websockets.
  - integrations: المحاكاة في الإنتاج.
- **في اللوحة:** صفحة «صحة النظام» للفحوص نفسها، وصفحة «تتبّع طلب» بالرقم.
- **`php artisan zon:reindex [entity] [--force]`:** إعادة تعبئة الفهرس بعد إعادة بناء Qdrant.
- **برامج Supervisor:** zon-worker-index · zon-worker-ai · zon-worker-general · zon-reverb · zon-agents.
- **Nginx:**
  - حد 60 طلباً في الدقيقة لكل عنوان (`burst=20`) قبل PHP.
  - Reverb على `/app/`.
  - `media.zon.sa` يمرّر إلى MinIO بترويسة `Host 127.0.0.1:9000` لأن الروابط موقّعة عليها (`temporary_url` في قرص minio).

## 13. الاختبارات

- **قاعدة الاختبار:** MySQL باسم `zon_test`، مع `RefreshDatabase` والبذرة، وRedis DB 15 لعدّادات الذكاء.
- **`Http::preventStrayRequests()`:** لا يصل أي اختبار لخدمة حقيقية.
- **الحجم قبل الضياع:**
  - PHP: نحو 290 اختباراً، وPHPStan نظيف، وPint نظيف.
  - Python: 140 اختباراً، وruff وmypy نظيفان.

## 14. قاعدة الحفظ

- نسخة مضغوطة تُرسل لصاحب المنصة بعد اكتمال كل جزء.
- يُرفع كل جزء إلى مستودع GitHub خاص متى رُبط.
- لا يبقى عمل غير مسلَّم في بيئة العمل السحابية وحدها.
