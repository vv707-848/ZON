# معمارية تطبيق زون

هذا الملف لمن يستلم الكود: كيف قُسّمت الملفات، ومن يستورد من، وأين تُحفظ البيانات، وكيف تضيف شيئاً جديداً أو تربط خادماً دون أن تكسر شيئاً قائماً.

> النسخة 1.2.0+3 أضافت: الحسابات المنفصلة عن الجلسة (`data/auth`)، الفوترة والأرصدة ومحاكاة الدفع (`data/billing`)، الإشعارات (`data/notifications` و`features/notifications`)، خدمات الهاتف (`core/services`)، والتخزين الخاص بكل حساب. ملخص التعديلات في [`CHANGES_v3.md`](CHANGES_v3.md).
>
> النسخة 1.2.1+4 أصلحت ملاحظات الفحص F01–F10: ترحيل ملكية بيانات 1.1، ربط كل عملية دفع بالحساب الذي بدأها، حفظ وحدات المشاريع بعد التحقق، دوام صور الاستوديو (`core/services/photo_store.dart`)، الطابق القديم وحالة النشر في المحررين، وتوقيت الإملاء. التفاصيل في [`CHANGES_v3_1.md`](CHANGES_v3_1.md).

## الطبقات

```
          ┌──────────────────────────────┐
          │  features/   (الشاشات)        │
          └──────┬───────────────┬───────┘
                 │               │
     ┌───────────▼──────┐   ┌────▼────────────────────────┐
     │ shared/widgets/  │   │ data/  (البيانات)            │
     │ مكوّنات عقارية    │──▶│ نماذج، أنواع، مخزن، حسابات،   │
     └───────────┬──────┘   │ فوترة، إشعارات               │
                 │          └────┬────────────────────────┘
          ┌──────▼───────────────▼───────┐
          │  core/  (أساسات عامة)          │
          │  theme · layout · widgets ·   │
          │  icons · services · utils     │
          └──────────────────────────────┘
```

**قاعدة الاتجاه الواحد:** كل طبقة تستورد مما تحتها فقط.

- `core/` لا يستورد من `data/` ولا `shared/` ولا `features/`. هو أدوات عامة يمكن نقلها لمشروع آخر.
- `data/` لا يعرف شيئاً عن الواجهة (عدا `ChangeNotifier` في المخزن).
- `shared/widgets/` مكوّنات عقارية تستخدمها أكثر من ميزة.
- `features/` الشاشات. ميزة لا تستورد من ميزة أخرى إلا إذا كان المكوّن خاصاً بها فعلاً. إذا احتاجت ميزتان المكوّن نفسه فمكانه `shared/widgets/`.

## المجلدات

### `lib/app/` — جذر التطبيق

| الملف | المحتوى |
|---|---|
| `zon_app.dart` | `MaterialApp`: الثيم، اللغة العربية واتجاه RTL، المسارات، التكبير النسبي `UiScale`، وحد تكبير الخط من إعدادات الهاتف (`kMaxTextScale` = 1.2) |
| `router.dart` | جدول المسارات (`screenFor`)، والمساعد `Nav` (`Nav.go` / `Nav.back`)، و`RedirectTo` |
| `external_links.dart` | فتح الخرائط والروابط والاتصال |
| `server.dart` | `startApp()` قبل `runApp`: هوية الجهاز دائماً، وفي نسخة فيها خادم (`ZON_API_URL`): العميل، والجلسة المحفوظة، والإشعارات، وفحص البوابة عند البدء وعند كل عودة للتطبيق. ومعه فتح صفحة الإشعار المنقور (`openPushTarget`) والعودة لصفحة الدخول حين ينهي الخادم الجلسة |
| `gate_overlay.dart` | `GateOverlay` فوق كل الصفحات: «حدّث زون للمتابعة» (426) و«زون في الصيانة» (503). الصفحات تبقى حية تحتها |

### `lib/core/` — أساسات عامة

| المجلد | المحتوى |
|---|---|
| `theme/` | `AppColors` (الألوان)، `ts()` و`Tx` وسلّم الأحجام والخط (خط النظام: San Francisco على iPhone — `kFontFamily`)، `AppShadows` (الظلال)، `buildAppTheme()`. استوردها كلها بسطر واحد: `core/theme/theme.dart` |
| `layout/` | `AppShell` (إطار كل صفحة مع شريط التبويب العائم، يختفي الشريط عند ظهور الكيبورد)، `UiScale` (التكبير النسبي) |
| `widgets/` | `AppButton` و`ButtonPair` (زرّان متجاوران إن اتسعت التسميتان، وإلا زر في كل سطر)، `showAppDialog`/`showAppNotice`/`showAppFrame` و`AppDialogCard`، `FieldBox`، `FormError` (رسالة خطأ النماذج)، `AsciiDigitsFormatter` (يحوّل الأرقام العربية أثناء الكتابة)، `ZonLogo`، `BackLink`، `AppImage`، `Tap`، `SarText`… كلها عبر `core/widgets/widgets.dart` |
| `icons/` | أيقونات Lucide كمتجهات (`LucideIcon(Lu.heart)`) ومحلّل مسارات SVG |
| `services/` | خدمات الهاتف، وكل منها متغير عام يمكن استبداله في الاختبارات: `dictation.dart` (`DictationService` و`dictation` = `SystemDictation` عبر حزمة speech_to_text)، `photo_picker.dart` (`photoPicker` عبر image_picker، و`pickPhotos` الذي تستخدمه النماذج: يفتح الاستوديو وينسخ كل صورة فورًا إلى مجلد التطبيق)، `photo_store.dart` (`PhotoStore`: مجلد الصور الدائم ومراجع `zon-photo:` — انظر «صور العروض» أدناه) |
| `net/` | **الطريق الوحيد إلى الخادم.** `api.dart` (`kApiUrl` و`Api.client`)، `api_client.dart` (`ApiClient`: ترويسات كل طلب — المنصة والإصدار ورقم الجهاز ورقم تتبّع جديد والتوكن — وقراءة الغلاف `{success, data}` أو `{error}`)، `api_error.dart` (`ApiError` ورسالة الحقل الأولى)، `app_gate.dart` (`AppGate`: التحديث الإجباري والصيانة والنسخة الأحدث، و`compareVersions`)، `app_identity.dart` (`AppIdentity`: المنصة، إصدار المتجر، رقم التثبيت، اسم الجهاز)، `secret_store.dart` (التوكن ورقم التثبيت في keychain/keystore، لا في shared_preferences) |
| `monitoring/` | `Monitoring`: Sentry (مشروع zon-app) بنسخة فيها `SENTRY_DSN` فقط. الخطأ برقم الحساب ورقم تتبّع الطلب، بلا محتوى ولا لقطات شاشة ولا ما كتبه المستخدم |
| `utils/` | تنسيق الأرقام والترتيب، `input.dart` (`toAsciiDigits`، `parseUserNumber`، `normalizeSaudiMobile`)، `clock.dart` (`appNow` — ساعة يمكن تقديمها في الاختبارات)، `dates.dart` (`arDate`، `arTime`، `arDateTime`، `arRelative` بالعربية) |

### `lib/data/` — البيانات

| المسار | المحتوى |
|---|---|
| `models/` | النماذج: `Listing` (ومعه `ownerId` و`premiumUntil` و`urgentUntil`)، `UnitModel` (ومعه `license` و`licenseVerified` لكل وحدة)، `RequestItem` (`urgent` و`ownerId` و`createdAt`)، `LocalAccount`، `Session` (بلا كلمة سر)، `AppNotification`، `Client`، `Owner`، `Task`، `Chat`… لكل نموذج `fromJson`/`toJson` |
| `catalog/` | أنواع العقار (`property_types.dart` — **المصدر الوحيد** للأنواع)، المدن، التسميات وصلاحيات الخدمات `kSvcRoles`، ودوال العروض (`listing_helpers.dart`، ومنها `projectIsReady`) |
| `auth/` | `auth.dart` (`Auth`: باب الشاشات — الخادم إن وُجد، وإلا الجهاز)، `server_auth.dart` (`ServerSession` و`ServerAuth`: الدخول، التسجيل برمز SMS، الخروج، «أجهزتي»، مفاتيح الإشعارات، الاسم والمدينة)، `local_auth.dart` (`LocalAuth`: التسجيل، الدخول، الخروج، تعديل الملف — **محاكاة** حين لا خادم)، `password_hash.dart` (PBKDF2-HMAC-SHA256 بملح، 12000 دورة، مقارنة ثابتة الزمن) |
| `push/` | `push.dart` (`Push` و`PushProvider`: الإذن مرة، ومعرّف التثبيت (FID) إلى الخادم بعد الدخول وعند كل بدء وعند تغيّره)، `firebase_push.dart` (`FirebasePush`: FCM بواجهة FID الجديدة — `register` و`onRegistered` — في نسخة فيها `ZON_PUSH`) |
| `billing/` | `pricing.dart` (**كل الأسعار والنِّسب والأرصدة**: `Pricing`، `Plans`، `kPlanQuotas`)، `billing.dart` (`billing`: الاشتراكات، الرصيد، الخصم، سجل العمليات)، `billing_models.dart` (`BoostKind`، `Subscription`، `LedgerEntry`، `BoostQuote`، `BoostOutcome`)، `payment_simulator.dart` (**محاكاة الدفع** ومفتاح `ZON_SIMULATIONS`)، `owner_cases.dart` (`ownerCases`: خطوات باقة المالك لكل عقار) |
| `notifications/` | `notification_center.dart` (`notifications`: القائمة، العدد غير المقروء، الإضافة بمفتاح يمنع التكرار، القراءة، والإشعارات المحسوبة من البيانات) |
| `seed/seed_data.dart` | البيانات التجريبية التي تظهر مع بيانات المستخدم |
| `store.dart` | `Store`: القراءة والحفظ على الجهاز (عام + خاص بكل حساب)، الملكية (`ownsListing`، `canEditListing`، `myListings`، `myRequests`)، ويُشعر الواجهة عند كل تغيير |

استورد `data/catalog/catalog.dart` في الشاشات؛ يصدّر النماذج والأنواع والمدن والتسميات وتنسيق الأرقام.

### `lib/shared/widgets/` — مكوّنات عقارية مشتركة

| الملف | المحتوى |
|---|---|
| `listing_card.dart` | بطاقة العرض أو المشروع في القوائم (شارتا «مميز» و«عاجل» حسب مدتهما) |
| `ask_card.dart` | بطاقة طلب البحث |
| `barq_card.dart` | بطاقة زون (الهوية) وعدّادات المتابعين |
| `boost.dart` | زر «طلب عاجل» (`UrgentToggle`) وشارة «عاجل» |
| `billing_flow.dart` | تشغيل التمييز أو الطلب العاجل (`runBoost`)، نافذة الدفع (`showPayDialog`) بوسم «دفع تجريبي»، ونصوص الرصيد خارج بطاقة الباقة (`quotaLine`) |
| `dictation_field.dart` | `DictationField`: خانة الوصف والمايك **داخل** إطارها (البحث الدلالي بنوعيه ومساعد المطابقة) |
| `lens.dart` | شريط الفرز والتصفية في الرئيسية والطلبات |
| `match_kit.dart` | عناصر شاشات المطابقة |
| `talk.dart` | عناصر المحادثات |
| `push_banner.dart` | الإشعار الذي يصل والتطبيق مفتوح: بطاقة أعلى الشاشة خمس ثوانٍ، والنقر يفتح صفحته |

### `lib/features/` — الشاشات

| الميزة | الملفات | المسار |
|---|---|---|
| الرئيسية | `home/home_screen.dart` (الجرس وعدد غير المقروء) | `/` |
| الإشعارات | `notifications/notifications_screen.dart` | `/notifications` |
| الطلبات | `requests/requests_screen.dart`، `request_detail_screen.dart` | `/requests`، `/requests/:id` |
| التفاصيل | `details/listing_screen.dart`، `project_screen.dart`، `unit_screen.dart` و`details/widgets/` | `/listings/:id`، `/projects/:id`، `/projects/:id/units/:unitId` |
| الإضافة | `add/` (انظر أدناه) | `/add` (و`/add?type=listing|project|request` لفتح مسار مباشرة) |
| المحادثات | `chats/chats_screen.dart`، `chat_thread_screen.dart`، `barq_chat_screen.dart` | `/chats`، `/chats/:id`، `/chats/barq` |
| حسابي | `account/account_screen.dart`، `service_screen.dart`، `services/` | `/account`، `/account/:service` |
| الدخول والتسجيل | `auth/login_screen.dart` | `/login` |
| الملف العام | `profile/user_profile_screen.dart` | `/users/:name` |
| السياسات | `legal/legal_screen.dart` | `/legal/:slug` |

#### ميزة الإضافة `features/add/`

| الملف | المحتوى |
|---|---|
| `add_screen.dart` | شاشة «ماذا تريد؟» ثم تفتح المسار المناسب (أو المسار المطلوب في `?type=`) |
| `listing_form.dart` | مسار الإعلان: المالك (تمهيد ← الصك ← العقد وموافقته ← رسوم الترخيص ← التفاصيل ← المراجعة ← النشر)، والوسيط (الترخيص ← بيانات المالك ← التفاصيل ← المراجعة). يرفض السعر السالب |
| `project_form.dart` | مسار المشروع للمطوّر: التفاصيل والوحدات ثم مراجعة الأوصاف. في المشروع «الجاهز» حقل ترخيص إعلاني تحت **كل وحدة**؛ «على الخارطة» يخفي الحقول ولا يتحقق منها |
| `request_form.dart` | نموذج طلب البحث: عادي مجاني أو عاجل (من الرصيد أو 50 ريالًا بعد التأكيد)، والتحقق من النطاقات (`rangeError`) |
| `listing_edit_screen.dart` | حارس محرّر العرض: الزائر يُحوَّل للدخول، والحساب غير الناشر يرى «لا يمكنك تعديل هذا العرض» |
| `listing_editor.dart` | محرّر العرض: كل حقول الإضافة + إدارة الصور، وتأكيد تجاهل التعديلات |
| `project_editor.dart` | محرّر المشروع ووحداته وترخيص كل وحدة، وتنبيه البيانات القديمة ذات الترخيص على مستوى المشروع |
| `add_helpers.dart` | دوال بلا واجهة: تحويل النص إلى رقم (`numOr0` بالأرقام العربية)، `rangeError`، `licenceFormatOk`، صياغة الذكاء (`polishCopy`) |
| `widgets/photo_manager.dart` | `PhotoManager`: مصغرات الصور الحالية، الإضافة من الاستوديو، الحذف، اختيار الغلاف، منع حذف آخر صورة، الحد 12 صورة |
| `widgets/unit_licence_field.dart` | `UnitLicenceField`: رقم ترخيص الوحدة وزر «تحقق» وحالته |
| `widgets/` (البقية) | مكوّنات النماذج، وتُستورد كلها عبر `widgets/add_kit.dart` |

#### خدمات «حسابي» `features/account/services/`

كل خدمة في ملف، ويستضيفها `service_screen.dart` (العنوان والتحقق من صلاحية الدور):

| الخدمة | الملف | الصنف |
|---|---|---|
| مساعد المطابقة | `matching_view.dart` | `SeekerMatchingView` / `DeveloperMatchingView` |
| البحث الدلالي | `search_view.dart` | `SemanticSearchView` |
| إدارة العملاء | `clients_view.dart` | `ClientsView` |
| إدارة الملاك | `owners_view.dart` | `OwnersView` |
| المخزون | `inventory_view.dart` | `InventoryView` |
| مهامي | `tasks_view.dart` | `TasksView` (إضافة، تعديل، إنجاز، حذف) |
| طلباتي | `my_requests_view.dart` | `MyRequestsView` (وتحويل الطلب إلى عاجل، وتعديل الطلب) |
| عروضي ومشاريعي | `my_listings_view.dart` | `MyListingsView` (وأزرار «تمييز» و«عاجل» مع سطر الرصيد) |
| الإعدادات | `settings_view.dart` | `SettingsView` |
| الاشتراكات | `plans_view.dart` | `PlansView` (باقة الوسيط، الباحث، المالك) |
| المتابعون | `people_view.dart` | `PeopleView` |

## التخزين: عام وخاص بكل حساب

كل البيانات في `shared_preferences`. المفاتيح:

| المفتاح | النوع | المحتوى |
|---|---|---|
| `zon.accounts.v1` | عام | سجل الحسابات المحلية (`LocalAccount`: اسم المستخدم، الاسم، الجوال، الدور، المدينة، الملح والملخّص — بلا كلمة سر) |
| `zon.session.v1` | عام | الجلسة الحالية (`Session` — معرّف الحساب وبياناته الظاهرة) |
| `zon.extra.listings.v1` · `zon.listing.patch.v1` | عام | العروض المنشورة وتعديلاتها، ومع كل عرض `ownerId` |
| `zon.extra.requests.v1` | عام | الطلبات المنشورة، ومع كل طلب `ownerId` و`createdAt` |
| `zon.tasks.v1@<id>` · `zon.crm.v1@<id>` · `zon.owners.v1@<id>` · `zon.stock.v1@<id>` · `zon.chats.v1@<id>` · `zon.msgs.v1@<id>` · `zon.sem.marks.v1@<id>` · `zon.wish.v1@<id>` · `zon.wish.seen.v1@<id>` · `zon.radar.ping@<id>` · `zon.follows.v1@<id>` | خاص | المهام، العملاء، الملاك، المخزون، المحادثات، علامات البحث، الرغبة وما شوهد منها، المتابعات |
| `zon.billing.v1@<id>` | خاص | الاشتراكات (الخطة، البداية، النهاية، مرجع الدفع) وسجل العمليات (الرصيد المستخدم والمدفوع) |
| `zon.owner.cases.v1@<id>` | خاص | حالات باقة المالك لكل عقار |
| `zon.notifs.v1@<id>` | خاص | الإشعارات المحفوظة وحالة قراءتها |
| `zon.migration.ownership.v1` | عام | علامة ترحيل ملكية بيانات 1.1 (وقته، الحساب، عدد المنسوب وغير المنسوب) — الترحيل لا يتكرر |
| `zon.legacy.claims.v1` | عام | طلبات «استعادة» بيانات الإصدار السابق غير المنسوبة (الحساب، الوقت، المعرّفات) — سجل فقط، لا ينقل شيئًا |

`store.scopedKey('zon.tasks.v1')` يعطي المفتاح الخاص بالحساب الحالي. القراءة والكتابة الخاصة عبر `readAccountJson` / `writeAccountJson`، ومعهما `accountId:` للكتابة في بيانات حساب محدد غير الداخل الآن (نتيجة دفع اكتمل بعد تبديل الحساب). **الترحيل من 1.1:** الجلسة القديمة (وفيها كلمة السر نصًا) تتحول عند أول تشغيل إلى حساب بملخّص (`migratedFrom: '1.1'`)، وتُنقل بياناتها الخاصة إلى مفاتيح الحساب، وتُحذف كلمة السر.

**ملكية العروض والطلبات القديمة (F01)** — `_migrateOwnership` في `store.dart`، مرة واحدة: عرض أو طلب محفوظ بلا `ownerId` يُنسب إلى الحساب المُنشأ من جلسة 1.1 على الجهاز **فقط** إذا طابق اسم كاتبه ودوره معًا اسم تلك الجلسة ودورها (الإصدار 1.1 كان يكتب اسم الجلسة الوحيدة على الجهاز). غير ذلك يُعلَّم `unclaimed: true`: يبقى منشورًا كما هو، ولا يأخذه أي حساب (ولا حساب لاحق بالاسم نفسه، ولا `saveListing`/`saveRequest`)، ويظهر في «الإعدادات» تحت «بيانات من الإصدار السابق» مع «طلب استعادة» الذي يسجّل الطلب في `zon.legacy.claims.v1` فقط. التحقق ونقل الملكية عند وجود خادم.

## الحسابات والدخول

**مع خادم** (`ZON_API_URL`) — `ServerAuth`:
- الدخول: `POST /auth/login` ومعه اسم الجهاز ومنصته وإصداره، فتظهر الجلسة باسمه في «أجهزتي».
- التسجيل: `POST /auth/register` يرسل رمز SMS، و`POST /auth/otp/verify` ينشئ الحساب ويفتح الجلسة. «أعد الإرسال» في خطوة الرمز.
- التوكن في keychain/keystore (`ServerSession`)، والحساب نفسه `Session` كما تقرؤه الشاشات (`srv-<id>`).
- 401 في أي طلب (جلسة أنهيت من جهاز آخر أو من اللوحة، أو انتهت) يُخرج الجهاز ويعيده لصفحة الدخول.
- الخروج يرسل معرّف Push لهذا الجهاز فيحذفه الخادم مع الجلسة. وبلا إنترنت يخرج الجهاز على أي حال.
- رغبة الباحث في التسجيل تبقى على الجهاز إلى أن تُربط الرغبات (`POST /preferences`). وتغيير رقم الجوال (رمز إلى الرقم الجديد) لم يُربط بعد.

**بلا خادم** (النموذج المعتمد) — `LocalAuth`، محاكاة:
- `LocalAuth.signUp` يتحقق من الاسم، الجوال (صيغة سعودية قبل خطوة الرمز)، اسم المستخدم (3–32 من الحروف اللاتينية والأرقام والنقطة والشرطة، غير مستخدم)، كلمة السر (8 أحرف على الأقل)، ثم يحفظ الحساب ويفتح الجلسة.
- `LocalAuth.signIn` يقارن الملخّص؛ الخطأ برسالة واحدة «اسم المستخدم أو كلمة السر غير صحيحة».
- `LocalAuth.signOut` يغلق الجلسة فقط.

## الفوترة والأرصدة (`data/billing`)

القواعد (P02 · R01) مطبّقة في `billing.dart` ومختبرة في `test/billing_quota_test.dart`:

1. الرصيد مرتبط **بالحساب وبفترة الاشتراك المدفوعة**. السنوي لا يتجدد شهريًا، وفتح التطبيق أو الخروج والدخول لا يجدده، والحساب الآخر على الجهاز لا يراه.
2. الخصم **مرة واحدة عند نجاح العملية فقط**: اختيار خيار، أو إلغاء التأكيد، أو فشل الدفع لا يستهلك شيئًا.
3. النقرة الثانية أثناء التنفيذ تُتجاهل (`isBusy`) — لا خصم مزدوج ولا دفعتان. والعرض المميز حاليًا لا يُخصم له مرة أخرى.
4. عند نفاد الرصيد يظهر **السعر قبل التأكيد** مع إمكانية الإلغاء، ولا يتفعّل شيء قبل نجاح الدفع.
5. مدة صلاحية الرصيد السنوي ليست مدة التمييز: كل تمييز يستمر `Pricing.featuredDuration` (7 أيام حاليًا).
6. الطلب العادي مجاني دائمًا ولا يخصم من أي رصيد. الطلب العاجل: من رصيد «الطلبات العاجلة» إن وُجد، وإلا 50 ريالًا بعد التأكيد.
7. **العملية للحساب الذي بدأها (F02/F10):** معرّف الحساب يُحفظ قبل أي انتظار، وتُسجَّل `PaymentRecord` في `zon.billing.v1@<id>` (`pending` ثم `paid` أو `failed`، أو `unapplied` إن حُذف العنصر أثناء الدفع). بعد الانتظار يُعاد التحقق من ملكية العنصر، وتُكتب النتيجة بـ `accountId:` لذلك الحساب فقط، ويُضاف له إشعار إن لم يكن داخلًا. منع النقر المزدوج بمفتاح `<الحساب>:<النوع>:<العنصر>`. نافذة الدفع لا تُغلق بالرجوع أثناء التنفيذ (`PopScope`)، وإن أُغلقت تنتظر الشاشة النتيجة نفسها.

| الأنواع (`BoostKind`) | الرصيد السنوي | بعد النفاد |
|---|---|---|
| `featured` — تمييز العرض «مميز» | 15 | `Pricing.featuredListingPrice` |
| `urgentListing` — تمييز العرض «عاجل» | 10 | `Pricing.urgentListingPrice` |
| `urgentRequest` — الطلب العاجل | 5 | `Pricing.urgentRequestPrice` (50) |

**تفسير الأرصدة الثلاثة:** الـ15 والـ10 لتمييز العروض، والـ5 رصيد مستقل للطلبات العاجلة — هذا هو التفسير المقترح في وثيقة التعديل وينتظر تأكيد صاحب التطبيق (انظر [`OPEN_POINTS.md`](OPEN_POINTS.md)). تغييره في `kPlanQuotas` و`BoostKind` فقط.

### محاكاة الدفع

`PaymentSimulator.charge()` في `payment_simulator.dart` هي **الدالة الوحيدة** التي يمر بها كل «ادفع» في التطبيق، ومراجعها تبدأ بـ `SIM-`. `PaymentSimulator.gate` (`@visibleForTesting`) يُبقي النتيجة معلّقة داخل الاختبارات وأداة الصور فقط. لا اتصال ببنك أو آبل أو جوجل. المفتاح `--dart-define=ZON_SIMULATIONS=false` يوقف المحاكاة (موافقة العقد، الترخيص، الدفع) فتظهر المسارات «بانتظار الخدمة».

**تصنيف الشراء لدى آبل وجوجل** (شراء داخل التطبيق أو دفع خارجي) لم يُحسم في هذه النسخة، ولا يَعِد الكود بأي إعفاء. يُقرَّر عند بناء خدمة الدفع الحقيقية ومراجعتها وفق إرشادات المتجرين (Apple App Review 3.1.1 و3.1.3).

## باقة المالك (`owner_cases.dart`)

لكل عقار حالة مستقلة، والمراحل منفصلة (لا علم واحد يغطيها):

`contract` (أُرسل العقد — بانتظار موافقة المالك عبر نفاذ) ← `fee` (العقد موافق عليه — رسوم الترخيص 50 ريالًا لم تُدفع) ← `licence` ← `listing` (لم يُنشر العرض) ← `active`.

الباقة **مفعّلة** فقط عندما يكون عقد عقار ما موافقًا عليه **و** عرضه منشورًا وما زال قائمًا (`ownerCases.planActive()`). زر «ابدأ نشر عقارك» يفتح مسار الإضافة ولا يفعّل شيئًا. السعي 0.5٪ مرتبط بعقار الحالة وعقدها فقط. إدخال الصك، أو رقم محلي، أو دفعة تجريبية، لا يُعد موافقة عقد؛ وموافقة المحاكاة معلَّمة `approvalSimulated` ورقم الترخيص التجريبي `licenceSimulated`.

## الإشعارات (`data/notifications` و`features/notifications`)

- الجرس في الرئيسية يفتح `/notifications`، ويعرض عدد غير المقروء.
- كل إشعار يحفظ: المعرّف، النوع (`NoteKind`)، العنوان، السطر التوضيحي، الوجهة، الوقت، حالة القراءة، ومفتاحًا يمنع التكرار، و`demo` للصفوف التجريبية.
- المصادر في هذه النسخة: أحداث التطبيق (نفاد رصيد، تفعيل باقة المالك)، وحالات محسوبة من البيانات (عروض مطابقة للرغبة للباحث والمطوّر، طلبات جديدة في مدينة الوسيط من حسابات أخرى، انتهاء تمييز عرض).
- الوجهات: المطابقة ← `/account/matching`، الرسالة ← المحادثة، العرض أو المشروع ← صفحته، الطلب ← تفاصيله، الاشتراك أو الرصيد ← الباقة أو الخدمة. العنصر المحذوف يُظهر «لم يعد هذا العنصر متاحًا» بدل صفحة مكسورة.
- نوع «رسالة جديدة» جاهز للخادم، ولا تنتجه النسخة الحالية (لا رسائل واردة حقيقية)؛ أداة الصور تضيف صفًا تجريبيًا معلَّمًا `demo`.
- **Push** (`data/push`، نسخة فيها خادم و`ZON_PUSH`): الإذن يُطلب مرة بعد الدخول، ومعرّف التثبيت (Firebase Installation ID — رموز التسجيل القديمة مهملة) يذهب للخادم مربوطاً بجلسة الجهاز. النقر على إشعار يفتح وجهته، والإشعار والتطبيق مفتوح بطاقة أعلى الشاشة. الجرس نفسه ما زال محلياً حتى تُربط الإشعارات (`GET /notifications`).
- **مفاتيح الإشعارات** في الإعدادات سبعة: الستة مفعّلة حتى تُطفأ، و«العروض والتسويق» مطفأ حتى يفعّله صاحب الحساب. مع خادم تُحفظ فيه (`PUT /me/notification-preferences`)، ويعود المفتاح لحاله إن لم يُحفظ.

## خدمات الهاتف (`core/services`)

- **الإملاء**: `SystemDictation` يستخدم خدمة التعرّف على الكلام في النظام (حزمة speech_to_text 7.5 بخيارات `SpeechListenOptions`)، ويختار لغة عربية متاحة على الهاتف. لا خدمة ذكاء مدفوعة. ميكروفون لوحة مفاتيح iOS يبقى متاحًا ويتحكم فيه النظام.
  - **التوقيت (F08/F09)** في `DictationField`: كل نداء للخدمة (التحضير، البدء، الإيقاف، الإلغاء) يمر بطابور واحد بالترتيب، فالإلغاء يصل بعد بدءٍ كان في الطريق ويوقفه. النقر أثناء التحضير أو البدء = إلغاء. حقل واحد يملي في كل مرة. بعد «إيقاف» تُقبل الكلمات النهائية للجلسة ما لم يُعدَّل النص يدويًا أو تبدأ جلسة جديدة.
- **الصور**: `pickPhotos` يفتح الاستوديو (`photoPicker`)؛ رفض الإذن أو الإغلاق يعود برسالة أو بلا شيء دون تعطل.

### صور العروض (`PhotoStore` — F05)

ملف الاستوديو مؤقت (ذاكرة التطبيق المؤقتة في Android، و`tmp` في iOS)، ووثائق image_picker تطلب نقله إلى مكان دائم. لذلك:

| المرحلة | ما يحدث |
|---|---|
| الاختيار | نسخة فورية في `<مجلد دعم التطبيق>/photos/drafts/` (عبر `path_provider`)، ومرجع `zon-photo:drafts/<اسم>`. إن تعذر النسخ تظهر رسالة ولا يُضاف مسار مؤقت |
| الحفظ | `Store.saveListing` و`patchListing` و`saveStock` تنسخ المسودات إلى `photos/saved/` وتحفظ `zon-photo:saved/<اسم>` (صور العرض، غلاف المشروع، صورة كل وحدة). نسخة المسودة تبقى حتى التنظيف حتى لا تنكسر معاينة نموذج مفتوح |
| العرض | `imageFor` يحل المرجع على مجلد التطبيق عند كل تشغيل — لا مسار مطلق في البيانات، لأن مسار حاوية iOS يتغير مع تحديث التطبيق. الصورة المتعذرة: `PhotoUnavailable` («الصورة غير متاحة»)، لا صورة عقار آخر |
| التشغيل | مسارات 1.2.0 المطلقة تُنسخ إلى `saved` إن كان ملفها موجودًا. ثم يُحذف من `drafts` و`saved` فقط ملف لا تذكره أي قيمة محفوظة وعمره يوم فأكثر |

عند ربط الخادم: تُرفع الصورة ويُحفظ مرجعها الدائم بعد اكتمال الرفع؛ نقطة التغيير `PhotoStore.commit` و`imageFor`.

## تدفق البيانات

1. `main.dart` يجهّز مجلد الصور (`PhotoStore.init()`) ثم يستدعي `Store.init()` فيحمّل كل القيم المحفوظة في الذاكرة (ويرحّل بيانات 1.1 وملكيتها وصور 1.2.0 إن وُجدت)، ثم يشغّل التطبيق.
2. الشاشات تقرأ مباشرة من المخزن العام `store` ومن `billing` و`ownerCases` و`notifications`. القراءة متزامنة.
3. الحفظ يكتب في الذاكرة فوراً، ويحفظ على الجهاز في الخلفية، ثم يستدعي `notifyListeners()`.
4. أي جزء ملفوف بـ `ListenableBuilder(listenable: store, …)` يُعاد رسمه تلقائياً.

حالة كل نموذج أثناء تعبئته تبقى داخل `State` الشاشة، ولا تُرسل للمخزن إلا عند الحفظ أو النشر.

## التنقل

- `Nav.go(context, '/listings/l1')` يفتح صفحة، و`Nav.back(context)` يرجع.
- كل المسارات في `screenFor()` داخل `app/router.dart`، وفوقها جدول توثيقي بها.
- شريط التبويب السفلي يبدّل الصفحات بلا حركة انتقال (`NavArgs(animate: false)`).

## كيف أضيف…

### شاشة جديدة

1. أنشئ `lib/features/<feature>/<name>_screen.dart`، وابدأ الملف بتعليق من سطرين يشرح المسار وما تعرضه الشاشة.
2. لُفّ المحتوى بـ `AppShell(...)` ليأخذ الخلفية وشريط التبويب والهوامش.
3. أضف المسار في `screenFor()` وفي الجدول فوقها داخل `app/router.dart`.
4. استخدم المكوّنات الجاهزة: `AppButton`/`ButtonPair` للأزرار، `showAppDialog` للأسئلة، `FormError` لأخطاء النماذج، `Tx` و`ts()` للنصوص، `AppColors` و`AppShadows`. لا تكتب ألواناً أو أحجام خط حرّة.
5. تحقق من الشاشة على عرض 360 مع تكبير الخط ×1.2 (انظر `test/text_scale_test.dart` و`test/text_fit_test.dart`).

### سعر أو حد جديد

أضفه في `lib/data/billing/pricing.dart` واقرأه من هناك في الشاشة والفحص والاختبار؛ لا تكتب رقمًا داخل شاشة.

### نوع عقار جديد

كل شيء في `lib/data/catalog/property_types.dart`، والخطوات مكتوبة أعلى الملف:

1. أضف المفتاح في `kPropertyTypes`. ترتيب القائمة هو ترتيب الظهور.
2. أضف التسمية في `kTypeLabel` و`kTypePlural`.
3. اختر قالب المواصفات في `specsForType`.
4. أعطه صورة افتراضية في `kTypeImage`.
5. إن كان يصلح للمشاريع فأضفه إلى `kProjectTypes` و`kProjectUnitTypes`.

**إذا حذفت نوعاً**: أضف مفتاحه القديم في `kRetiredTypes` داخل `data/models/types.dart` مع النوع البديل.

### خدمة جديدة في «حسابي»

1. أنشئ `lib/features/account/services/<name>_view.dart` فيه صنف `…View`.
2. أضف عنوانها في `_titles` وحالتها في `switch` داخل `service_screen.dart`.
3. حدّد الأدوار المسموح لها في `kSvcRoles` داخل `data/catalog/labels.dart`.
4. أضف بلاطتها في قائمة `services` داخل `account_screen.dart`.
5. إن كانت بياناتها شخصية فاحفظها بمفتاح خاص بالحساب (أضف المفتاح إلى `_privateKeys` في `store.dart`).

### نوع إشعار جديد

أضف النوع في `NoteKind` (`data/models/notification.dart`)، ومصدره ووجهته (`target`) في `notification_center.dart` (حدث بـ `notifications.add(..., key: …)` أو حالة محسوبة)، وأيقونته في `notifications_screen.dart`، واختبارًا للوجهة في `test/notifications_test.dart`.

### أيقونة جديدة

انسخ أشكال الأيقونة من lucide.dev إلى `core/icons/lucide_data.dart` بنفس بنية بقية الأيقونات، ثم أضف ثابتاً باسمها في الصنف `Lu` داخل `core/icons/lucide.dart`.

## ربط خادم (backend)

النسخة التي فيها خادم تُبنى بـ `--dart-define=ZON_API_URL=https://api.zon.sa/api/v1`، وبدونه يبقى التطبيق النموذج المعتمد كما هو. الربط شريحة بعد شريحة، وكل نداء يمر بـ `ApiClient` (`core/net`)، فيحمل كل طلب المنصة والإصدار ويعمل عليه التحديث الإجباري.

**الشريحة الأولى — الحساب والجهاز (مربوطة):**

| ما رُبط | الملفات | الواجهة |
|---|---|---|
| التحديث الإجباري والصيانة والنسخة الأحدث | `core/net/app_gate.dart` · `app/gate_overlay.dart` · `app/server.dart` | `GET /app/config` عند البدء وعند العودة، و426/503 من أي طلب |
| الدخول والتسجيل والخروج | `data/auth/server_auth.dart` · `features/auth/login_screen.dart` | `/auth/login` · `/auth/register` · `/auth/otp/verify` · `/auth/otp/resend` · `/auth/logout` |
| «أجهزتي» | `features/account/services/devices_view.dart` | `GET /me/sessions` · `DELETE /me/sessions/{id}` |
| Push | `data/push/` · `shared/widgets/push_banner.dart` | `POST /me/devices` |
| مفاتيح الإشعارات، والاسم والمدينة | `features/account/services/settings_view.dart` | `PUT /me/notification-preferences` · `PATCH /me` · `GET /me` |
| تتبّع الأخطاء | `core/monitoring/monitoring.dart` | Sentry، بلا خادم زون |

**مفاتيح البناء:**

| المفتاح | ما يفعله |
|---|---|
| `ZON_API_URL` | عنوان الخادم. فارغ = النموذج المحلي |
| `SENTRY_DSN` · `SENTRY_ENVIRONMENT` | تتبّع الأخطاء (مشروع zon-app). فارغ = لا يُرسل شيء |
| `ZON_PUSH=true` | الإشعارات، مع ملفي Firebase (`docs/integrations.md` في المستودع: «الإشعارات — Firebase») |
| `ZON_SIMULATIONS=false` | لا محاكاة دفع ولا موافقة عقد ولا رقم ترخيص |

**الشرائح التالية:** الواجهات تعتمد على أسماء الدوال فقط. عند الربط تتغير أجسام هذه الأصناف، ويصبح **الخادم مصدر الحقيقة** للاستحقاق والخصم والتحقق:

| الصنف | الملف | ما ينتقل للخادم |
|---|---|---|
| `Store` | `data/store.dart` | العروض والطلبات وملكيتها (`ownerId` يصبح من الخادم)، البيانات الخاصة بكل حساب، طلبات استعادة بيانات 1.1 |
| `PhotoStore` | `core/services/photo_store.dart` | رفع الصور وحفظ مراجعها الدائمة بعد اكتمال الرفع |
| `LocalAuth` | `data/auth/local_auth.dart` | ما بقي: رغبة التسجيل (`POST /preferences`)، وتغيير الجوال برمز (`POST /me/phone`)، ونسيان كلمة السر |
| `Billing` | `data/billing/billing.dart` | الباقات والأسعار (جدول `packages`)، الاستحقاق، عدّادات الرصيد، تسجيل الخصم |
| `PaymentSimulator` | `data/billing/payment_simulator.dart` | بوابة الدفع والتحقق من الدفعة، ومطابقة العمليات المعلّقة (`PaymentRecord`) |
| `OwnerCases` | `data/billing/owner_cases.dart` | عقد الوساطة وموافقته (الهيئة عبر نفاذ)، رسوم الترخيص وإصداره |
| `NotificationCenter` | `data/notifications/notification_center.dart` | الجرس من الخادم (`GET /notifications` · `POST /notifications/read`). الإرسال Push نفسه مربوط |
| `licenceFormatOk` | `features/add/add_helpers.dart` | التحقق من رقم الترخيص الإعلاني لكل وحدة لدى الهيئة |
| `polishCopy` | `features/add/add_helpers.dart` | خدمة صياغة الذكاء الاصطناعي |

ثم احذف دمج البيانات التجريبية (`data/seed/`) عندما تصبح بيانات الخادم حقيقية، وابنِ بـ `ZON_SIMULATIONS=false`. ونسخة `ZON_SIMULATIONS=false` لا تعرض اليوم أي عيّنة: لا عروض ولا طلبات ولا محادثات ولا عملاء ولا ملاك ولا مخزون ولا متابَعون ولا مهام تجريبية، ولا أسماء في مسح المطابقة، ولا أرقام في الملف العام، ولا رقم اتصال لم يعطه أحد (`lib/core/simulations.dart`، واختبارها `test/no_simulations_test.dart`).

## الاختبارات

- `test/` يحوي اختبارات `flutter_test` تشغّل التطبيق كاملًا (`pumpApp`) على جهاز فارغ (`freshDevice`) وحسابات حقيقية من مسار التسجيل (`signUp`)، وتقرأ القيم المحفوظة بعد كل عملية.
- الخدمات التي لا تعمل في الاختبار الآلي (الإملاء، الاستوديو) تُستبدل داخل الاختبار فقط بإسناد المتغيرين العامين `dictation` و`photoPicker`. `freshDevice` يعطي كل اختبار مجلد صور مؤقتًا فارغًا، ويعيد ضبط بوابة الدفع.
- `test/flutter_test_config.dart` يسجّل قبل اختبارات كل ملف بدائل مرخّصة لخط النظام من `test/fonts/` (Inter بدل SF Pro، وNoto Sans Arabic بدل SF Arabic)، لأن خط SF غير موجود في محرك الاختبار ولا يجوز نسخه. شغّل الاختبارات من مجلد المشروع.
- `test/no_simulations_test.dart` متروك في التشغيل العادي؛ يعمل بـ `--dart-define=ZON_SIMULATIONS=false`.
- `tool/screenshot_harness/` أداة التقاط صور الشاشات للمراجعة (خارج التطبيق، ومستبعدة من الفحص).

## قواعد الكتابة

- **ملف لكل غرض**، وكل ملف يبدأ بتعليق يشرح ما فيه. الأصناف والدوال العامة عليها تعليق `///`.
- الاستيراد دائماً بمسار الحزمة `package:zon/...` (قاعدة `always_use_package_imports`)، ومرتب: `dart:` ثم `package:flutter/` ثم الحزم الخارجية ثم `package:zon/`.
- **التسمية**: الملفات `snake_case`، والشاشات `…Screen`، ومحتوى الخدمات `…View`، والمكوّنات الخاصة بملف واحد تبدأ بشرطة سفلية `_`.
- **التنسيق**: `dart format lib`، وعرض السطر 150 من `analysis_options.yaml`.
- **الجودة**: `flutter analyze` يجب أن ينتهي بـ `No issues found!` و`flutter test` بنجاح كل الاختبارات قبل أي تسليم.
- النصوص العربية مكتوبة في مكانها داخل الشاشات، لأن التطبيق عربي فقط. إن أُضيفت الإنجليزية لاحقاً فانقل النصوص إلى ملفات `l10n/*.arb`.
