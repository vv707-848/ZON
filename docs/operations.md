# التشغيل اليومي ودليل الأعطال

ما يعمل أين وترتيب الإقلاع في `deploy/README.md`. هذا الملف لما بعد الإقلاع: الأوامر، الجدولة، تتبّع طلب، ومن العرَض إلى موضع الإصلاح.

## العمليات على عقدة التطبيق

| العملية | الأمر (Supervisor: `deploy/supervisor/zon.conf`) | السجل |
|---|---|---|
| عامل الفهرسة | `queue:work redis --queue=index` (1) | `/var/log/zon/worker-index.log` |
| عامل الذكاء | `queue:work redis --queue=ai --timeout=60` (2) | `/var/log/zon/worker-ai.log` |
| العامل العام | `queue:work redis --queue=notify,default,media` (2): الإشعارات، المهام المجدولة، الصور | `/var/log/zon/worker-general.log` |
| Reverb | `reverb:start --host=127.0.0.1 --port=8080` خلف nginx `/app/` | `/var/log/zon/reverb.log` |
| خدمة الوكلاء | `zon-agents serve` على `127.0.0.1:8100` | `/var/log/zon/agents.log` (سطر JSON لكل طلب) |
| المجدول | cron كل دقيقة: `schedule:run` (`deploy/cron/zon`) | — |
| الفحص الدوري | cron كل 5 دقائق: `zon:doctor --json` | `/var/log/zon/doctor.json`، والفشل إلى syslog بوسم `zon-doctor` |

سجل Laravel اليومي في `backend/storage/logs/laravel-YYYY-MM-DD.log`. كل سطر يحمل `trace_id` تلقائياً.

## الأوامر

| الأمر | متى |
|---|---|
| `php artisan zon:doctor` | أول خطوة عند أي شكوى. يفحص 13 بنداً (الإعدادات، القاعدة، الترحيلات، Redis، المجدول، الطوابير، المهام الفاشلة، الوكلاء، الفهرس، اتصال الوكيل الأعلى، التخزين، Reverb، التكاملات)، ولكل بند فاشل «أين تنظر أولاً». الصفحة نفسها في اللوحة: «صحة النظام» |
| `php artisan zon:reindex [listing\|project_unit\|request\|kb_document] [--force]` | بعد فهرس فارغ أو أُعيد بناؤه (`zon-agents bootstrap --recreate`): مع `--force`. دونه يُرسل ما تغيّر نصه فقط |
| `php artisan zon:create-superadmin <email>` | أول تشغيل: المدير العام (كلمة سر 12+ تُطلب سراً، ثم المصادقة الثنائية عند أول دخول) |
| `php artisan queue:failed` · `queue:retry <id>` | بعد تنبيه «المهام الفاشلة» |
| `php artisan schedule:list` | للتأكد من جدولة مهمة |

## الإصدار الجديد

```bash
cd /var/www/zon/backend
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize          # config · routes · events · views
php artisan filament:assets      # CSS/JS of the panel (composer install runs it too: filament:upgrade)
php artisan filament:optimize
php artisan queue:restart     # العمّال ينهون مهمتهم الحالية ثم يبدؤون بالكود الجديد
sudo supervisorctl restart zon-reverb zon-agents
php artisan zon:doctor
```

لا تُعِد تشغيل العمّال بـ `supervisorctl restart` أثناء العمل. `queue:restart` ينتظر انتهاء المهمة الجارية؛ المهام المجدولة قد تطول حتى 10 دقائق، والإشعار العام حتى 15.

## الجدولة (`backend/routes/console.php`، بتوقيت الرياض، على خادم واحد)

| المهمة | التوقيت | ما تفعله |
|---|---|---|
| `SchedulerHeartbeat` | كل دقيقة | نبض يثبت أن المجدول والعامل يعملان («صحة النظام») |
| `RemindPendingReviews` | كل ساعة | تذكير واحد للمعلن بصياغة جاهزة تنتظر قراره |
| `AutoPublishPendingReviews` | كل ساعة :05 | بلا قرار خلال المهلة: النشر بنص المعلن الأصلي، ويبقى زر المراجعة |
| `ResumePausedListings` | كل 15 دقيقة | استئناف ما انتهت مدة إيقافه |
| `InactivateStaleListings` | يومياً 03:30 | عرض **الوسيط** بلا تحديث طوال المدة المحددة في الإعدادات (أسبوعان) يصير «غير نشط»، وأي تحديث يعيده |
| `EndBoosts` | كل ساعة :10 | إنهاء التمييز والعاجل وإشعار المعلن |
| `RetryPendingIndexEvents` | كل 15 دقيقة | إعادة إشارات الفهرسة غير المقَرّة |
| `ExpireKnowledgeReviews` | يومياً 00:30 | إخراج وثائق المعرفة المتأخرة عن مراجعتها من الفهرس |
| `SendTaskReminders` | كل 5 دقائق | تذكير المهام |
| `NotifyWishMatches` | 09:00 و18:00 | إشعار الباحث بعروض جديدة تطابق رغبته |
| `RemindSubscriptionRenewals` | يومياً 10:00 | تذكير قرب انتهاء الاشتراك |
| `ExpireLicences` | يومياً 00:05 | تسجيل ما فعله منتصف الليل: العرض الذي انتهت رخصته (خرج من العموم لحظتها) يصير «منتهي الترخيص» ويخرج من الفهرس، ويُبلَّغ المعلن. والوحدة كذلك |
| `RemindLicenceExpiry` | يومياً 10:00 | تنبيه المعلن (والمطوّر للوحدة) قبل آخر يوم في الرخصة بأسبوع وبيوم، كل تنبيه مرة واحدة |
| `SyncOwnerContracts` | كل 10 دقائق | متابعة عقود الملاك لدى الهيئة |
| `RecheckBrokerLicenses` | يومياً 04:00 | إعادة فحص رخص فال |
| `ComputeDailyMetrics` | يومياً 00:15 | أرقام الرئيسية في اللوحة |
| `ComputeDailyAreaStats` | يومياً 01:00 | إحصاءات الأحياء لتقارير السوق |
| `PurgeDueAccounts` | يومياً 02:30 | محو الحسابات التي انتهت مهلة حذفها |
| `PruneOrphanMedia` | يومياً 03:00 | حذف صور رُفعت ولم تُربط بشيء منذ يوم (الملف يُحذف إن لم تستعمله نسخة أخرى) |
| `sanctum:prune-expired` · `queue:prune-failed` · `activitylog:clean` | يومياً · أسبوعياً · شهرياً | تنظيف |

مهمة جديدة في `app/Jobs/Scheduled` دون سطرها في `console.php` يُفشلها `tests/Unit/ArchitectureTest.php`.

## تتبّع طلب

رقم واحد يربط الأطراف الثلاثة. يولّده `AssignTraceId` (أو يأخذ `X-Request-Id` من التطبيق). يراه المستخدم في رسالة الخطأ (`error.trace_id`)، ويعود في ترويسة `X-Request-Id` لكل رد.

1. **اللوحة ← «تتبّع طلب»:** الصق الرقم، فتظهر أعداد ما تركه هذا الطلب، وكل عدد يفتح شاشته مصفّاة بالرقم:
   - نداءات الذكاء (`ai_requests`).
   - إشارات الفهرسة (`index_events`).
   - سجل التدقيق (`activity_log`).
2. **سجل Laravel:** `grep <الرقم> storage/logs/laravel-*.log`.
3. **سجل خدمة الوكلاء:** `grep <الرقم> /var/log/zon/agents.log`.
   - الحقل `request_id`، ومعه الوكيل ونسخة الموجّه والزمن والرموز.
   - لا نص مستخدم في هذا السجل.
4. **سطر nginx:** `trace=<الرقم>` في `/var/log/nginx/zon-api.log`.
5. **مهمة في الطابور:** تحمل الرقم نفسه (Laravel Context)، فسجلّها في السجل نفسه وتحت الرقم نفسه.

## من العرَض إلى الموضع

| العرَض | انظر أولاً | ثم | الكود |
|---|---|---|---|
| الذكاء لا يرتّب، النتائج بلا أسباب | «مراقبة الذكاء»: نسبة `fallback`/`timeout` | `agents.log` ← النفق إلى عقدة الذكاء (`deploy/README.md` الخطوات 3–5) | `app/Ai/AiGateway.php` · `AgentsClient.php` |
| عرض منشور لا يظهر في البحث الدلالي | «مراقبة الفهرسة»: إشارة معلّقة أو فاشلة (`last_error`) | «إعادة الإرسال» من الشاشة، أو `zon:reindex listing` | `app/Indexing/IndexSync.php` · `app/Jobs/PushIndexEvent.php` |
| الصياغة لا تصل، والعرض عالق «قيد الصياغة» | `worker-ai.log`، ونداءات `clean_listing` | الطابور `ai` يعمل؟ (`zon:doctor` ← الطوابير) | `app/Jobs/CleanListingText.php` |
| دفع ناجح لم يُفعِّل الباقة | «المدفوعات»: حالة `unapplied` وسببها | مطابقة المبلغ والعملة و`metadata.payment_id` لدى المزوّد | `app/Actions/Billing/ConfirmPayment.php` |
| رمز التحقق لا يصل | «صحة النظام» ← التكاملات (`SMS_DRIVER`) | سجل Laravel برقم التتبع: `INTEGRATION_FAILED` | `app/Integrations/Sms` |
| الإشعار لا يصل للجوال | جرس التطبيق يعمل؟ إذن المشكلة في Push | `PUSH_DRIVER` وملف حساب الخدمة، و`customer_devices` | `app/Notifications/PushChannel.php` · `app/Integrations/Push` |
| الرسائل لا تظهر فوراً | «صحة النظام» ← Reverb | `reverb.log`، ومسار nginx `/app/` | `app/Events/MessageSent.php` · `routes/channels.php` |
| مستخدم مُنع من خدمة | رمز الخطأ في `error.code` (مرجع الواجهة) | الحساب في اللوحة: الحالة، الدور، الباقة، فال، نفاذ | `app/Http/Middleware` · `app/Services/Billing/Entitlements.php` |
| خدمة ذكاء ترد 429 `TOO_MANY_REQUESTS` | محاولات اليوم بلغت 3 أضعاف الحد (`zon.ai.attempts_multiplier`)، ولو فشلت كلها | «سجل نداءات الذكاء» لهذا الحساب: نداءات `rejected` أو `fallback` متكررة | `app/Ai/Quota/AiQuota.php` · `EnforceAiQuota` |
| محتوى مخفي عاد بعد رفض بلاغ | `auto_hidden_at` كان مضبوطاً: أخفته البلاغات لا موظف | سجل التدقيق برقم التتبع | `app/Actions/Moderation/HandleReport.php` |
| موظف لا يرى قسماً | «الأدوار»: صلاحيات دوره | اسم الصلاحية في `Permission` | `app/Support/Permissions/Permission.php` |
| الوكيل الأعلى يفشل | «الوكيل الأعلى» ← الأرشيف: الحالة | «صحة النظام» ← اتصال الوكيل الأعلى، ومنح `ai_reader` | `app/SuperAgent` |
| أرقام اللوحة الرئيسية قديمة | آخر تشغيل لـ `ComputeDailyMetrics` | نبض المجدول في «صحة النظام» | `app/Jobs/Scheduled` |

## الوكيل الأعلى: قاعدة القراءة

- **حساب القراءة:** `ai_reader` يُنشأ مرة على عقدة البيانات بعد الترحيلات، بالأمر `mysql < deploy/mysql/ai_reader.sql`، بعد استبدال كلمة السر.
- **الاتصال:** كلمة السر في `REPORT_DB_PASSWORD`. اتصال `report` في Laravel يقرأ المشاهد فقط، ومهلة كل استعلام 5 ثوانٍ.
- **إضافة تقرير:**
  1. صنف في `app/SuperAgent/Templates` يرث `Template` (أو `MarketTemplate` إن كان عن السوق: لا مجموعة أقل من 5).
  2. اسمه في `Catalog`.
  3. اختبار `test_every_template_runs_on_the_views` يشغّله على المشاهد.
- **الحد:** النموذج يختار اسم القالب ومعاملاته فقط، ولا يكتب SQL أبداً.
- **حارس الأرقام (`NumberGuard`):** يُسقط التحليل إن ذكر رقماً مكتوباً بالأرقام ليس في الصفوف. الرقم المكتوب كلمةً («عشرة») لا يلتقطه، فالجدول نفسه هو المرجع عند الشك.

## عقد Laravel ↔ الوكلاء

كل حمولة يرسلها Laravel إلى خدمة الوكلاء تُفحص بنماذج الوكلاء نفسها:

```bash
cd backend && ZON_CONTRACT_DIR=../docs/contracts/fixtures php artisan test   # يسجّل عينات الحمولات
cd ../agents && .venv/bin/python -m pytest tests/test_backend_contract.py      # يفحصها
```

- يفشل الفحص على حقل ناقص أو بنوع خاطئ، وعلى حقل يرسله Laravel ولا يعرفه الوكلاء.
- النموذج يتجاهل الحقل المجهول بصمت، فيكون هذا الفحص المكان الوحيد الذي يكشف اسماً تغيّر في طرف واحد.
- شغّله بعد أي تعديل على حمولة في أحد الطرفين، واحفظ العينات الجديدة مع التعديل.
