# زون — تطبيق Flutter

تطبيق «زون» لمنصة العرض والطلب العقاري في السعودية، لنظامَي iOS وAndroid من كود واحد.

**الإصدار:** 1.2.1+4 — النسخة الثالثة (تعديلات وثيقة 4 أكتوبر 2026: V01 · P01 · P02 · R01 · P03 · P04 · N01 · L01 · E01 · Q01) مع إصلاحات تقرير الفحص F01–F10 (8 أكتوبر 2026).

| الملف | المحتوى |
|---|---|
| `README.md` (هذا الملف) | التشغيل، الاختبارات، المحاكاة في هذه النسخة، بناء نسخ المتاجر، أين تعدّل ماذا |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | تقسيم المجلدات والطبقات، التخزين لكل حساب، الفوترة والأرصدة، الإشعارات، ونقاط ربط الخادم |
| [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) | الألوان، الخط وسلّم الأحجام، الظلال، الأزرار، الحقول، التكبير النسبي حسب حجم الجوال |
| [`docs/OPEN_POINTS.md`](docs/OPEN_POINTS.md) | النقاط غير المحسومة وما ينتظر خادمًا أو هاتفًا فعليًا |
| [`docs/history/`](docs/history/) | **سجل لا يُحدَّث:** تسليما أكتوبر 2026 كما سُلّما (1.2.0+3 و1.2.1+4): ملخص التعديلات (`CHANGES_v3.md`)، وإصلاحات F01–F10 (`CHANGES_v3_1.md`)، وسجل الاختبارات يومها (`TEST_LOG.md`)، وصور الشاشات (`screenshots_v3_1.pdf`). الحالة الآن في الفحص الآلي (`.github/workflows/app.yml`) |

## المتطلبات

- Flutter المستقر 3.47 أو أحدث (Dart 3.11+).
- لأندرويد: Android Studio وJDK 17 أو أحدث.
- لآيفون: جهاز Mac عليه Xcode وCocoaPods.

## التشغيل

```bash
flutter pub get
flutter run                                                           # النموذج المعتمد: بلا خادم
flutter run --dart-define=ZON_API_URL=http://10.0.2.2:8000/api/v1     # مع الخادم (محاكي أندرويد ← الخادم على جهازك)
```

مفاتيح البناء كلها في `docs/ARCHITECTURE.md` ← «ربط خادم»: `ZON_API_URL` و`SENTRY_DSN` و`ZON_PUSH` و`ZON_SIMULATIONS`.

### حسابات التجربة

بلا `ZON_API_URL` التسجيل والدخول **محاكاة محلية** على الجهاز (التفاصيل في `docs/ARCHITECTURE.md`). مع الخادم يُرسل الرمز برسالة SMS، وفي خادم التطوير (`SMS_DRIVER=fake` و`OTP_TEST_CODE`) يقبل الرمز الثابت نفسه:

- أنشئ حسابًا من «حساب جديد». رمز التحقق من الجوال في هذه النسخة ثابت: **204815** (`kTestOtp` في `lib/data/auth/local_auth.dart`) — لا تُرسل رسالة SMS.
- كلمة السر لا تُحفظ نصًا؛ يُحفظ ملخّصها (PBKDF2) فقط. الخروج يغلق الجلسة ويُبقي الحساب، فيعمل الدخول بالبيانات نفسها لاحقًا.
- كل حساب له بياناته الخاصة على الجهاز (المهام، العملاء، الاشتراك، الرصيد، الإشعارات…)؛ تبديل الحساب لا ينقلها.

## الاختبارات

```bash
flutter test             # كل اختبارات test/، وبعضها متروك عمدًا (تعطيل المحاكاة)؛ عددها في سجل CI
flutter test --dart-define=ZON_SIMULATIONS=false test/no_simulations_test.dart   # الثلاثة المتروكة: 3/3
```

الخط في التطبيق هو خط النظام (San Francisco على iPhone)، وهو غير موجود في محرك الاختبار؛ لذلك يسجّل `test/flutter_test_config.dart` بدائل مرخّصة من `test/fonts/` قبل الاختبارات (Inter وNoto Sans Arabic). شغّل `flutter test` من مجلد المشروع.

| الملف | ما يختبره |
|---|---|
| `test/dictation_test.dart` | V01: المايك داخل الوصف في الأماكن الثلاثة، مقاسات 360/390/430 مع الكيبورد والخط الأكبر، رفض الإذن، عدم تكرار النتائج الجزئية، الإيقاف عند المغادرة |
| `test/plans_test.dart` | P01 · P03 · P04: نصوص الباقات وأسعارها، الدفع بعد إظهار السعر، حالات باقة المالك |
| `test/billing_quota_test.dart` | P02 · R01: 15 «مميز» و10 «عاجل» و5 طلبات عاجلة، الخصم مرة واحدة، عزل الرصيد لكل حساب وفترة |
| `test/requests_test.dart` | R01 · REQ-02 · REQ-03: الطلب العادي المجاني والعاجل بـ50، الإلغاء والفشل، الأرقام العربية والنطاقات |
| `test/notifications_test.dart` | N01: الجرس، القائمة، وجهة كل نوع، القراءة والعدد، العنصر المحذوف |
| `test/licence_test.dart` | L01: ترخيص لكل وحدة، المشروع على الخارطة، البيانات القديمة |
| `test/listing_editor_test.dart` | E01 · EDIT-02 · ACCESS-02: الصور (إضافة، حذف، غلاف، إلغاء)، السعر السالب، حراسة المحرر |
| `test/auth_access_test.dart` | AUTH-02 · AUTH-03 · ACCESS-02 · ACCESS-03 |
| `test/tasks_and_boosts_test.dart` | TASK-05، موعد المهمة بالعربية، تمييز العروض من «عروضي» |
| `test/text_scale_test.dart` · `test/text_fit_test.dart` | الخط الأكبر (×1.2) على عرض 360: لا تجاوز للمقاسات، ولا نص مقطوع في المواضع المعدّلة |
| `test/legacy_migration_test.dart` | F01: ترقية جهاز عليه بيانات 1.1 — الملكية المثبتة فقط، عدم التكرار، البيانات الملتبسة و«طلب استعادة» |
| `test/account_switch_test.dart` | F02 · F10: نتيجة الدفع للحساب الذي دفع عند تبديل الحساب أثناء الدفع، ورجوع النظام أثناء الدفع |
| `test/project_units_test.dart` | F03 · F04 · F07: مسودة الوحدة، الترخيص والسعر قبل الكتابة، الحذف والعدادات، الملكية، سعر الوحدة في الإضافة، المشروع المخفي |
| `test/photo_store_test.dart` | F05: الصورة تبقى بعد تنظيف ملف الاستوديو وإعادة التشغيل، ترتيب الغلاف، الإلغاء، صور 1.2.0، «الصورة غير متاحة»، التنظيف الحذر |
| `test/listing_state_test.dart` | F06 · F07: الطابق القديم والحالي بعد تعديل السعر أو الوصف، العرض الموقوف |
| `test/dictation_timing_test.dart` | F08 · F09: الإيقاف أثناء الإذن أو البدء، مغادرة الصفحة، تبديل الحقل، النتيجة المتأخرة بعد الإيقاف |
| `test/no_simulations_test.dart` | تعطيل المحاكاة: لا دفع ولا موافقة عقد ولا رقم ترخيص تجريبي (يحتاج `--dart-define=ZON_SIMULATIONS=false`) |

الإملاء الصوتي الحقيقي ومعرض الصور الحقيقي لا يعملان داخل الاختبار الآلي؛ تحل محلهما خدمات وهمية داخل الاختبارات فقط (وفي F08/F09 خدمة تؤخر ردودها عمدًا). وما ينتظر هاتفاً حقيقياً في [`docs/OPEN_POINTS.md`](docs/OPEN_POINTS.md) ← «ما ينتظر هاتفًا فعليًا».

## فحص جودة الكود

```bash
dart format lib          # التنسيق (عرض السطر 150 — من analysis_options.yaml)
flutter analyze          # يجب أن تكون النتيجة: No issues found!
```

قواعد الفحص في `analysis_options.yaml`: استيراد بمسار الحزمة دائماً (`package:zon/...`)، وترتيب الاستيرادات، و`const` حيث أمكن، ومنع `print`.

## المحاكاة في هذه النسخة

هذه النسخة نموذج محلي بلا خادم. ما يلي **محاكاة** واضحة في الكود والواجهة، وليست خدمة فعلية:

| المحاكاة | الملف | ما يظهر للمستخدم |
|---|---|---|
| الدفع (الاشتراكات، الطلب العاجل، تمييز العروض، رسوم الترخيص) | `lib/data/billing/payment_simulator.dart` | وسم «دفع تجريبي — لا يُخصم مبلغ فعلي» داخل نافذة الدفع |
| موافقة عقد الوساطة وإصدار الترخيص الإعلاني للمالك | `lib/data/billing/owner_cases.dart` | زر «محاكاة موافقة العقد (نسخة تجريبية)» مع سطر «للتجربة فقط»، ورقم ترخيص تجريبي |
| رمز التحقق من الجوال | `lib/data/auth/local_auth.dart` | الرمز الثابت 204815 |
| التحقق من رقم الترخيص الإعلاني للوحدة | `lib/features/add/add_helpers.dart` (`licenceFormatOk`) | فحص الصيغة فقط (6–12 رقمًا) |

لإخفاء كل المحاكيات (فتتوقف المسارات عند «بانتظار الخدمة» بدل الادعاء بإتمامها):

```bash
flutter run --dart-define=ZON_SIMULATIONS=false
```

بلا خادم لا توجد بوابة دفع، ولا ربط بالهيئة العامة للعقار أو نفاذ أو مزوّد SMS، ولا إشعارات Push. مع الخادم: الحساب والجلسات والإشعارات وتتبّع الأخطاء مربوطة، والباقي شريحة بعد شريحة (`docs/ARCHITECTURE.md` ← «ربط خادم»).

## بناء نسخ المتاجر

```bash
# Google Play (ملف AAB)
flutter build appbundle --release

# App Store (ملف IPA) — على جهاز Mac
flutter build ipa --release
```

قبل الرفع:

1. **معرّف التطبيق** الحالي `com.zon.app`. لتغييره:
   - أندرويد: `namespace` و`applicationId` في `android/app/build.gradle.kts`، ومسار الحزمة في `android/app/src/main/kotlin/com/zon/app/MainActivity.kt`.
   - iOS: من Xcode ← Runner ← Signing & Capabilities (أو `PRODUCT_BUNDLE_IDENTIFIER` في `ios/Runner.xcodeproj/project.pbxproj`).
2. **التوقيع**:
   - **أندرويد:** مفتاح رفع (upload key) مرة واحدة، ثم ملف `android/key.properties` بجانبه. الملفان خارج git (`android/.gitignore`)، ويُحفظان في مكان آمن. ضياع المفتاح يعني طلب استبداله من Google Play ([Flutter](https://docs.flutter.dev/deployment/android#sign-the-app)):
     ```bash
     keytool -genkey -v -keystore ~/zon-upload.jks -keyalg RSA -keysize 2048 -validity 10000 -alias upload
     ```
     ```properties
     # android/key.properties
     storeFile=/Users/<you>/zon-upload.jks
     storePassword=…
     keyAlias=upload
     keyPassword=…
     ```
     `build.gradle.kts` يقرؤه وحده. بدونه تُوقَّع نسخة release بمفتاح debug: تعمل على جوال التجربة، وGoogle Play يرفضها.
   - **iOS:** اختر فريق التطوير (Team) في Xcode.
3. **رقم الإصدار**: السطر `version: 1.2.1+4` في `pubspec.yaml` (الرقم بعد `+` يزيد مع كل رفع).
4. **المحاكاة**: ابنِ نسخة المتجر بـ `--dart-define=ZON_SIMULATIONS=false` إلى أن تُربط الخدمات الحقيقية.
5. **الخادم والأخطاء والإشعارات** (حين تُربط كل الشرائح):
   ```bash
   flutter build appbundle --release \
     --dart-define=ZON_API_URL=https://api.zon.sa/api/v1 \
     --dart-define=SENTRY_DSN=<DSN مشروع zon-app> \
     --dart-define=ZON_PUSH=true --dart-define=ZON_SIMULATIONS=false
   ```
   `ZON_PUSH` يحتاج ملفي Firebase: `android/app/google-services.json` و`ios/Runner/GoogleService-Info.plist` (`flutterfire configure` يضعهما)، ومفتاح APNs في مشروع Firebase، وقدرة Push Notifications في توقيع iOS (`ios/Runner/Runner.entitlements`).

## الاسم والشعار والأيقونات

- الاسم الظاهر تحت الأيقونة: «زون» (`android:label` في AndroidManifest، و`CFBundleDisplayName` في Info.plist).
- **الشعار** هو حرف Z بلون كريمي تتوسطه عقدة نعناعية على خلفية خضراء. داخل التطبيق يُرسم كمتجه من الكود (`lib/core/widgets/zon_logo.dart`)، فيبقى حاداً بأي حجم:
  - `ZonLogo(size: 44)` — بلاطة الشعار (رأس الرئيسية، بطاقة زون، شاشة الدخول).
  - `ZonLogo.mark(size: 320, color: …)` — الحرف وحده بلون واحد (العلامة المائية في شاشة الدخول).
- **أيقونات التشغيل** مولّدة من الأشكال نفسها:
  - iOS: كل المقاسات في `ios/Runner/Assets.xcassets/AppIcon.appiconset/`.
  - أندرويد: الأيقونة العادية `mipmap-*/ic_launcher.png`، والمتكيّفة `mipmap-*/ic_launcher_foreground.png` مع لون الخلفية `#173124` في `values/colors.xml`.
- **شاشة البدء**: الشعار في وسط خلفية بيضاء (`LaunchImage` في iOS و`drawable/launch_background.xml` في أندرويد).
- **ملفات المتجر** في `store_assets/`:
  - `app_store_icon_1024.png` — أيقونة App Store بلا شفافية.
  - `play_store_icon_512.png` — أيقونة Google Play.
  - `logo_rounded_1024.png` — الشعار بزوايا دائرية للعروض والمواد التسويقية.

## الأذونات المستخدمة

- الميكروفون والتعرّف على الكلام: كتابة الوصف بالصوت في البحث الدلالي (العروض والطلبات) ومساعد المطابقة، عبر خدمة الإملاء في نظام الهاتف. يعتمد العمل دون إنترنت على دعم الهاتف واللغة.
- الكاميرا ومكتبة الصور: صور العقار والمشروع والوحدات، وتعديل صور العرض المنشور، وإرفاق صورة في المحادثة.
- فتح الروابط الخارجية والاتصال الهاتفي: الخريطة، وصفحة الهيئة، وزر الاتصال بالمعلن.
- الإشعارات (نسخة فيها `ZON_PUSH`): يُطلب الإذن مرة بعد الدخول. «العروض والتسويق» لا يصل للجوال إلا بموافقة صريحة من الإعدادات.

## بنية المشروع باختصار

```
lib/
  main.dart        نقطة البداية: تحميل البيانات المحفوظة ثم تشغيل التطبيق
  app/             جذر التطبيق، جدول المسارات، فتح الروابط الخارجية
  core/            أساسات عامة لا تعرف شيئاً عن العقار:
    theme/           الألوان، الخط وسلّم الأحجام، الظلال، ثيم Material
    layout/          إطار الصفحات وشريط التبويب، والتكبير النسبي حسب الشاشة
    widgets/         زر التطبيق وزوج الأزرار، النوافذ، الحقول، رسالة الخطأ، الشعار…
    icons/           أيقونات Lucide كمتجهات
    services/        خدمات الهاتف: الإملاء (dictation.dart)، اختيار الصور (photo_picker.dart) ومجلدها الدائم (photo_store.dart)
    utils/           الأرقام (ومنها تحويل الأرقام العربية)، الوقت، التواريخ بالعربية
  data/            البيانات:
    models/          النماذج (العرض، الوحدة، الطلب، الحساب، الجلسة، الإشعار…)
    catalog/         أنواع العقار، المدن، التسميات
    auth/            الحسابات المحلية وملخّص كلمة السر (محاكاة الدخول)
    billing/         الأسعار والحدود، الاشتراكات والأرصدة، محاكاة الدفع، حالات باقة المالك
    notifications/   إشعارات الحساب
    seed/            البيانات التجريبية
    store.dart       المخزن المحلي (عام + خاص بكل حساب)
  shared/widgets/  مكوّنات عقارية تتكرر (بطاقة العرض، الطلب، الدفع والتأكيد، خانة الوصف بالمايك…)
  features/        الشاشات، كل ميزة في مجلد:
    home/ requests/ details/ add/ chats/ account/ auth/ notifications/ profile/ legal/
test/              اختبارات flutter_test (وsupport/ لتهيئة جهاز فارغ وحسابات الاختبار، وfonts/ لبدائل الخط في الاختبار فقط)
assets/
  images/          صور العروض والأنواع
  (لا ملفات خطوط: التطبيق يستخدم خط النظام — San Francisco على iPhone)
docs/              المعمارية، نظام التصميم، التعديلات، سجل الاختبارات، النقاط المفتوحة
tool/screenshot_harness/   أداة التقاط صور الشاشات (ليست جزءًا من التطبيق)
store_assets/      أيقونات وشعار جاهزة لصفحتَي المتجرين
```

التفاصيل الكاملة: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## أين تعدّل ماذا

### الأسعار والحدود — ملف واحد: `lib/data/billing/pricing.dart`

| الرقم | الثابت | القيمة الحالية |
|---|---|---|
| باقة الوسيط الشهرية | `Pricing.brokerMonthly` | 280 ريالًا |
| باقة الوسيط السنوية | `Pricing.brokerYearly` | 2,300 ريال |
| التوفير المعروض للسنوي | `Pricing.brokerYearlySaving` (محسوب: 280 × 12 − 2300) | 1,060 ريالًا |
| مشاركة زون من **السعي** بدون اشتراك | `Pricing.brokerFreeCommissionSharePercent` | 10٪ |
| باقة الباحث الشهرية | `Pricing.seekerMonthly` | 199 ريالًا |
| سعي باقة المالك بعد بيع العقار المعلن | `Pricing.ownerCommissionPercent` | 0.5٪ |
| رسوم الترخيص الإعلاني (للهيئة، ليست اشتراكًا) | `Pricing.adLicenceFee` | 50 ريالًا |
| الطلب العاجل عند عدم وجود رصيد | `Pricing.urgentRequestPrice` | 50 ريالًا |
| تمييز العرض «مميز» بعد نفاد الرصيد | `Pricing.featuredListingPrice` | 100 ريال (السعر القائم — بانتظار قرار) |
| تمييز العرض «عاجل» بعد نفاد الرصيد | `Pricing.urgentListingPrice` | 100 ريال (السعر القائم — بانتظار قرار) |
| مدة بقاء العرض مميزًا / عاجلًا | `Pricing.featuredDuration` · `Pricing.urgentListingDuration` | 7 أيام (بانتظار تأكيد) |
| رصيد الباقة السنوية | `kPlanQuotas[Plans.brokerYear]` | 15 «مميز» · 10 «عاجل» · 5 طلبات عاجلة لكل سنة اشتراك |
| رصيد الباقة الشهرية | `kPlanQuotas[Plans.brokerMonth]` | تمييز «مميز» واحد شهريًا (المعتمد السابق — بانتظار قرار) |
| مدة الفترة المدفوعة | `Plans.periodOf` | سنة = 365 يومًا، شهر = 30 يومًا |

الطلب العادي مجاني دائمًا ولا يخصم من أي رصيد (لا ثابت له). كل الشاشات والفحوص والاختبارات تقرأ من هذا الملف.

### بقية المواضع

| المطلوب | الملف |
|---|---|
| نصوص الباقات الثلاث | `lib/features/account/services/plans_view.dart` |
| قواعد الرصيد والخصم والدفع | `lib/data/billing/billing.dart` |
| نافذة الدفع والتأكيد ونصوص الرصيد خارج بطاقة الباقة | `lib/shared/widgets/billing_flow.dart` |
| خطوات باقة المالك (العقد ← الرسوم ← النشر) | `lib/data/billing/owner_cases.dart` |
| خانة الوصف بالمايك | `lib/shared/widgets/dictation_field.dart` (والخدمة في `lib/core/services/dictation.dart`) |
| أنواع الإشعارات ومصادرها ووجهاتها | `lib/data/notifications/notification_center.dart` و`lib/features/notifications/notifications_screen.dart` |
| تحقق رقم ترخيص الوحدة | `lib/features/add/widgets/unit_licence_field.dart` و`licenceFormatOk` في `add_helpers.dart` |
| تعديل صور العرض | `lib/features/add/widgets/photo_manager.dart` (الحد الأقصى `kMaxListingPhotos` = 12 صورة) |
| حفظ الصور ومراجعها وتنظيفها | `lib/core/services/photo_store.dart` (`PhotoStore`) |
| حد تكبير الخط | `kMaxTextScale` في `lib/app/zon_app.dart` (1.2) |
| لون من ألوان التطبيق | `lib/core/theme/colors.dart` (`AppColors`) |
| الخط (خط النظام: San Francisco على iPhone) | `kFontFamily` و`kDisplayFontFamily` في `lib/core/theme/typography.dart` |
| حجم أو وزن خط | `lib/core/theme/typography.dart` (سلّم الأحجام `kTypeScale`) |
| ظل البطاقات أو الأزرار العائمة | `lib/core/theme/shadows.dart` (`AppShadows`) |
| شكل الأزرار | `lib/core/widgets/app_button.dart` (`AppButton` و`ButtonPair`) |
| أنواع العقار وترتيبها ومواصفات كل نوع | `lib/data/catalog/property_types.dart` |
| المدن والأحياء | `lib/data/catalog/cities.dart` |
| نصوص التسميات (الغرض، نوع الأرض…) | `lib/data/catalog/labels.dart` |
| مسارات الصفحات | `lib/app/router.dart` |
| حفظ البيانات أو ربطها بخادم | `lib/data/store.dart` و`data/auth` و`data/billing` و`data/notifications` (الواجهات لا تتغير) |

## البيانات

تُحفظ البيانات محلياً على الجهاز عبر `shared_preferences`:

- **عامة** (يراها كل من يستخدم الجهاز): العروض والطلبات المنشورة — ومع كل منها `ownerId` لحساب ناشره — وسجل الحسابات والجلسة الحالية.
- **خاصة بكل حساب** (المفتاح `اسم@معرّف_الحساب`): المهام، العملاء، الملاك، المخزون، المحادثات، الرغبة، الاشتراك والرصيد وسجل العمليات، حالات باقة المالك، الإشعارات.

- **الصور** المختارة من الاستوديو: نسخ دائمة في مجلد دعم التطبيق (`photos/`)، والبيانات تحفظ مرجعًا قصيرًا `zon-photo:…` لا مسارًا مؤقتًا.

وتُدمج مع العروض بيانات تجريبية (`lib/data/seed/`) حتى لا يظهر التطبيق فارغاً. بيانات النسخة 1.1 تُرحّل تلقائيًا عند أول تشغيل (الجلسة القديمة تصبح حسابًا بملخّص كلمة سر، وعروضها وطلباتها المثبتة تبقى معه؛ غير المثبت لا يُنسب لأحد — انظر `docs/ARCHITECTURE.md`). عند ربط خادم تتغير أجسام الدوال في `Store` و`LocalAuth` و`Billing` و`OwnerCases` و`NotificationCenter` فقط؛ الخطوات في [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md#ربط-خادم-backend).
