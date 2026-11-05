# نموذج البيانات

قاعدة واحدة: MySQL 9.7 LTS باسم `zone` (الإنتاج) على عقدة البيانات. الجداول تنشئها المهاجرات `backend/database/migrations/2026_11_02_0001xx–0006xx` بالترتيب:
- 000100: الحسابات.
- 000200: العروض والمشاريع.
- 000300: الطلبات والفوترة.
- 000400: التواصل والمكتب.
- 000500: الذكاء والإحصاءات.
- 000600: مشاهد التقارير.

## الاتفاقات

| البند | القاعدة |
|---|---|
| المال المدفوع | هللات صحيحة في أعمدة `*_halalas` (الباقات، المدفوعات، الفواتير، العمولات)، والحساب بـ `App\Support\Money`. لا عشري في مال يُدفع. |
| سعر العقار | `decimal(14,2)` بالريال (`listings.price` · `project_units.price` · الميزانيات): قيمة وصفية لا عملية دفع. |
| الجوال | E.164 (`+9665XXXXXXXX`) في القاعدة، و`05XXXXXXXX` في الواجهة (`App\Support\Phone`). |
| الحالات | نصوص قصيرة تقابل Enum في `app/Enums` (مثال: `listings.status` ← `ListingStatus`). القيم الجديدة تضاف في الـ Enum أولاً. |
| الربط المتعدد | أسماء الأنواع في `morph_map` (`AppServiceProvider::MORPH_MAP`): `listing`، `project`، `request`، `customer`… لا أسماء أصناف PHP في القاعدة. |
| الفهرسة | `index_version` في كل ما يُفهرس: يرتفع مع كل إشارة في `index_events`، فيتجاهل الوكيل أي إشارة أقدم. |
| الحذف | `listings` و`projects` حذف ناعم (`deleted_at`). الحساب يُخفى ثم يُمحى بعد المهلة (`deletion_requests` ← `PurgeAccount`)، ومعه ملخصات ملاحظاته وأسئلته للشات. |
| الرخصة الإعلانية | تاريخ انتهائها من الهيئة إلزامي (`LicenceCheck::expiry`: بدونه خطأ ربط، لا تاريخ مخترع)، وهي سارية طوال آخر يوم بتوقيت الرياض. ما يراه العموم منشور برخصة سارية (`Listing::live`)، فيخرج العرض لحظة انتهائها، و`ExpireLicences` يسجّله «منتهي الترخيص» (`licence_expired`) |
| الإخفاء الآلي | `auto_hidden_at` في `listings` و`projects` و`requests`: أخفته البلاغات وحدها. وحده ما يُعاد عند رفض البلاغ، وأي قرار من موظف يمحو العلامة. |
| التتبع | `trace_id` في `ai_requests` و`index_events` و`chat_messages` و`exec_reports` و`activity_log.properties`. |

## الحسابات والوصول

| الجدول | النموذج | ما يحفظه |
|---|---|---|
| `customers` | `Customer` | مستخدمو التطبيق بأدوارهم الأربعة: الحالة، فال، نفاذ، `notification_prefs`، بصمات التسجيل المجزّأة، `is_tester` (حساب مراجعة معزول: يعبر الصيانة، ولا يصل شيء منه للمستخدمين؛ يُصنع من اللوحة فقط)، `store_token` (يرفقه التطبيق بمشترياته من المتجر) |
| `customer_devices` | `CustomerDevice` | أجهزة الحساب ورموز Push وآخر ظهور |
| `personal_access_tokens` | Sanctum | رموز الدخول (90 يوماً، والمنتهي يُحذف يومياً 03:15) |
| `buyer_preferences` | `BuyerPreference` | رغبات الباحث؛ `share_in_reports` يحدد دخولها في تقارير السوق |
| `deletion_requests` | `DeletionRequest` | طلب الحذف ومهلته و`hidden_items` لإرجاع المحتوى عند الإلغاء |
| `legacy_claims` | `LegacyClaim` | طلبات استعادة بيانات الإصدار السابق وقرار الموظف |
| `users` | `User` | موظفو اللوحة، وأسرار المصادقة الثنائية |
| `roles` · `permissions` · `model_has_*` · `role_has_permissions` | spatie | أدوار الموظفين وصلاحياتهم (`App\Support\Permissions\Permission`) |

## العروض والمشاريع

| الجدول | النموذج | ما يحفظه |
|---|---|---|
| `listings` | `Listing` | العرض: الحقول حسب النوع، `desc_raw` (نص المعلن) · `desc_suggested` (الوكيل) · `desc_clean` (المعتمد) و`text_review`، التمييز والعاجل والإيقاف، `image_hash` لكشف التكرار |
| `listing_history` | `ListingHistory` | كل تغيير حالة أو حقل مع فاعله (`customer:ID` · `staff:ID` · `system`) |
| `ad_licenses` | `AdLicense` | الترخيص الإعلاني لعرض أو وحدة، ومصدره، وتاريخ التحقق، وتاريخ انتهائه من الهيئة، وآخر تنبيه أُرسل قبله (`reminded_days`). كل تجديد صف جديد، والأحدث هو الرخصة |
| `projects` | `Project` | مشروع المطوّر ووصفه بالمراحل الثلاث نفسها |
| `project_units` | `ProjectUnit` | نماذج الوحدات: المتاح والسعر والوصف |
| `marketing_contracts` | `MarketingContract` | عقد التسويق الذي يفعّل المطوّر، ونسبة العمولة |
| `media` | `Media` | الصور على MinIO لأي كيان، والغلاف، و`phash`. الصورة تُحفظ معالَجة (انظر «الصور» أدناه) في `path`، ومقاساتها الأخرى بجانبها، وأسماؤها في `sizes` |
| `owner_cases` | `OwnerCase` | مسار المالك: الصك ← العقد (نفاذ) ← الرسوم ← الترخيص ← العرض، ونسبة السعي |

### الصور

كل صورة تُعاد رسمها عند الرفع (`App\Support\Photos\Photos`)، ولا تُحفظ كما خرجت من الجوال:

- **معتدلة:** يُطبَّق اتجاه الكاميرا (EXIF Orientation) على البكسلات.
- **بلا بيانات وصفية:** الملف الجديد لا يحمل شيئاً من كتل الأصل. فلا يصل موقع الجوال (GPS) ولا طرازه ولا تاريخ التصوير إلى صفحة أو محرك بحث.
- **ثلاثة مقاسات JPEG** بجانب بعضها: `01J….jpg` و`01J…-large.jpg` و`01J…-card.jpg`. والنسخ تشترك في الملفات نفسها؛ لا يُحذف ملف ما دام سجل آخر يشير إليه (`Media::deleteWithFiles`).

| المقاس | أطول ضلع · الجودة | الاستخدام | المقيس بـ GD |
|---|---|---|---|
| main | 2048 · 82 | عارض الصور (`url`) | ≈ 390 KB لصورة 12 ميغابكسل |
| large | 1280 · 80 | معرض الموقع ومعاينة الروابط (`large_url`). واتساب يشترط صورة أقل من 600 KB وعرضها 300 بكسل فأكثر | ≈ 180 KB |
| card | 640 · 80 | البطاقات في القوائم (`card_url`، و`cover_url` في العروض والمشاريع والمخزون) | ≈ 63 KB، ونحو 50 KB لصور التطبيق النموذجية |

- معالجة صورة 12 ميغابكسل تستغرق نحو 0.8 ثانية، وتزيد الذاكرة 64 MB.
- الصورة الأكبر من `MEDIA_MAX_MEGAPIXELS` (36) تُرفض قبل فكّها برسالة للمستخدم، لأن فكّها يأخذ العرض × الارتفاع × 4 بايت.
- حدود PHP في `deploy/php/zon.ini`، ويفحصها `zon:doctor` («رفع الصور ومعالجتها»). وحد PHP الافتراضي للرفع 2 MB، فيرفض أغلب صور الجوال بدونه.

## الطلبات والفوترة

| الجدول | النموذج | ما يحفظه |
|---|---|---|
| `requests` | `SearchRequest` | طلبات البحث: الأحياء والأنواع قوائم JSON، الميزانية، العاجل |
| `packages` | `Package` | الباقات: السعر بالهللة، الأرصدة، الحدود، سقوف الذكاء اليومية، الميزات |
| `subscriptions` | `Subscription` | اشتراك أو منحة بمدة، ومصدره (دفع · منح · …) |
| `payment_transactions` | `Payment` | عمليات الدفع: `public_id` (ULID) للتطبيق، الغرض والهدف، المزوّد ومرجعه، الحالة. للمتجرين (`apple` · `google`): المرجع عملية واحدة، و`store_chain` الشراء الذي تتبعه (تجديداته واسترداده) |
| `invoices` · `invoice_counters` | `Invoice` | الفواتير بترقيم متصل لكل سنة (`ZON-YYYY-NNNNNN`) وQR بصيغة TLV |
| `boost_usages` | `BoostUsage` | كل تمييز أو عاجل: من الرصيد أو الدفع أو منحة، ومدته |
| `commission_dues` | `CommissionDue` | المستحق لزون: سعي المالك، عمولة عقد التسويق، حصة الوسيط بلا اشتراك |

## التواصل والإشراف

| الجدول | النموذج | ما يحفظه |
|---|---|---|
| `conversations` · `conversation_participants` · `messages` | `Conversation` · `ConversationParticipant` · `Message` | المحادثات حول عرض أو مشروع أو طلب، وآخر قراءة لكل طرف |
| `notifications` | Laravel | إشعارات التطبيق (`AppNotice`) |
| `favorites` · `follows` · `ratings` · `user_blocks` | `Favorite` · `Follow` · `Rating` · `UserBlock` | المفضلة، المتابعة، التقييم بالنجوم، الحظر |
| `content_reports` | `ContentReport` | البلاغات وقرار الموظف |
| `support_tickets` | `SupportTicket` | المراسلة مع «فريق زون»: شكوى · اقتراح · استفسار · رسالة من الفريق، برقم قيد، وما تخص (عرض)، والحالة (دور من)، والإجراء المتخذ عند الإغلاق، وآخر ما قرأه كل طرف |
| `support_messages` | `SupportMessage` | رسائل المراسلة من الطرفين؛ الموظف يظهر للمستخدم «فريق زون» |
| `announcements` | `Announcement` | الإشعارات العامة وجمهورها وعدد مستلميها |

## المكتب (خاص بصاحبه)

| الجدول | النموذج | ما يحفظه |
|---|---|---|
| `crm_clients` · `client_notes` | `CrmClient` · `ClientNote` | عملاء الوسيط أو المطوّر وحاجاتهم وملاحظاتهم |
| `property_owners` · `owner_notes` | `PropertyOwner` · `OwnerNote` | ملاك العقارات لدى الوسيط وملاحظاتهم |
| `inventory_items` | `InventoryItem` | المخزون الخاص؛ `published_listing_id` بعد نشره إعلاناً |
| `tasks` | `Task` | المهام والتذكير |
| `note_summaries` | `NoteSummary` | ملخص الذكاء لملاحظات عميل أو مالك أو المهام، مع `source_hash` لإعادة الاستخدام |

محتوى هذا القسم لا يظهر في اللوحة إلا بصلاحية `ViewCrmContent`، وكل اطلاع يُسجَّل.

## الذكاء والإحصاءات

| الجدول | النموذج | ما يحفظه |
|---|---|---|
| `ai_requests` | `AiRequest` | كل نداء ذكاء: الوكيل، الحالة، الرموز، الزمن، تجزئة المدخل، `trace_id` — بلا نص |
| `index_events` | `IndexEvent` | صندوق الفهرسة الصادر: الإشارة وإصدارها والحمولة وتجزئتها، الإرسال والإقرار والمحاولات |
| `chat_messages` | `ChatMessage` | أسئلة الشات وإجاباته، والنية، والحارس، ووثائق المعرفة المستشهد بها |
| `kb_documents` | `KbDocument` | مكتبة المعرفة: المصدر، المراجِع، تاريخ المراجعة القادمة (المتأخرة تخرج من الفهرس) |
| `search_terms` | `SearchTerm` | قاموس المرادفات المحلية لتوسيع الاستعلام |
| `search_misses` | `SearchMiss` | بحث بلا نتيجة — أول إشارة لطلب غير ملبّى |
| `search_feedback` | `SearchFeedback` | «مطابق/غير مطابق» وسببه المقنَّع |
| `training_corpus` | `TrainingSample` | نصوص مقنَّعة عند المصدر لتدريب نموذج محلي لاحقاً |
| `daily_metrics` · `daily_area_stats` | `DailyMetric` · `DailyAreaStat` | لقطات يومية للوحة الرئيسية ولتقارير السوق |
| `exec_reports` | `ExecReport` | أرشيف الوكيل الأعلى: السؤال، القالب، المعاملات، الصفوف، التحليل، الحالة |
| `activity_log` | spatie | سجل التدقيق لكل إجراء موظف واطلاع حساس، مع `trace_id` |
| `settings` | `SettingValue` | ما غيّرته اللوحة من القيم التجارية؛ الافتراضي في `App\Support\Settings\Setting` |
| `cities` · `neighborhoods` | `City` · `Neighborhood` | المرجع الجغرافي |
| `app_configs` · `legal_pages` | `AppConfig` · `LegalPage` | الإصدار الأدنى والصيانة لكل منصة، ونصوص السياسات بأرقام نسخها |

## مشاهد التقارير `ai_v_*`

13 مشهداً يقرؤها حساب `ai_reader` وحده (`deploy/mysql/ai_reader.sql`)، ولا يرى أي جدول:

- **المشاهد:** `ai_v_listings` · `ai_v_listing_history` · `ai_v_requests` · `ai_v_projects` · `ai_v_project_units` · `ai_v_customers` · `ai_v_subscriptions` · `ai_v_payments` · `ai_v_search_misses` · `ai_v_search_feedback` · `ai_v_ai_requests` · `ai_v_daily_area_stats` · `ai_v_preferences` · `ai_v_crm_clients` · `ai_v_crm_notes`.
- **ما لا تحمله:** لا اسم، ولا جوال، ولا بريد، ولا نص حر لشخص. سبب «غير مطابق» مثلاً يظهر في المشهد بوجوده فقط (`has_reason`)، ونصه للموظفين في اللوحة.
- **الرغبات:** `ai_v_preferences` تضم الرغبات التي وافق أصحابها على مشاركتها فقط.
- **إضافة مشهد:** تتطلب ثلاث خطوات معاً:
  1. سطراً في المهاجرة 000600.
  2. سطر `GRANT` في `ai_reader.sql`.
  3. قالباً في `app/SuperAgent/Templates`.

## جداول Laravel التشغيلية

- `failed_jobs`: المهام التي فشلت بعد محاولاتها، تُنظَّف أسبوعياً بعد 7 أيام.
- `cache` · `cache_locks` · `jobs` · `job_batches` · `sessions` · `password_reset_tokens` · `migrations`: من هيكل Laravel. في الإنتاج الطوابير والذاكرة المؤقتة والجلسات على Redis، فتبقى هذه فارغة (عدا `migrations`).
