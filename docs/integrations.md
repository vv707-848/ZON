# التكاملات الخارجية: من التجريبي إلى الحي

## المبدأ

- كل جهة خارجية عقد واحد (`interface`) في `backend/app/Integrations/<الجهة>/`، وله سائقان: `fake` (محاكاة) والحي.
- الاختيار بقيمة واحدة في `.env` (`<SERVICE>_DRIVER`)، والربط في سطر واحد لكل سائق داخل `app/Providers/IntegrationServiceProvider.php`.
- **الإنتاج يرفض المحاكاة** إلا لجهة مسمّاة في `PRODUCTION_ALLOWS_FAKE`. الرفض لحظة استعمال الجهة، و`zon:doctor` وصفحة «صحة النظام» تعرضان كل محاكاة: «مسموحة مؤقتاً» تحذيراً، و«مرفوضة» فشلاً.
- خطأ الجهة الحية يصير `IntegrationException`، فيرى التطبيق `502 INTEGRATION_FAILED` برقم تتبع يقود إلى السجل.
- كل سائق محاكاة مكتوب في رأسه `SIMULATION`.
- محاكاة الدفع تقول ذلك صراحة للتطبيق (`simulation: true`)، ولا تُقدَّم أبداً كبوابة دفع حقيقية.

## الحالة

| الجهة | العقد | المحاكاة (`fake`) | السائق الحي | حالة الحي |
|---|---|---|---|---|
| رسائل التحقق | `Sms\SmsGateway` | لا يرسل شيئاً؛ النص في السجل، و`OTP_TEST_CODE` رمز ثابت للتطوير | `taqnyat` | مكتوب ومختبَر على شكل الطلب، **لم يُجرَّب بحساب حقيقي** |
| الدفع | `Payments\PaymentGateway` | المرجع `fake_{public_id}` ينجح بالمبلغ نفسه، و`fake_{public_id}_fail` يُرفض | `moyasar` | مكتوب ومختبَر على شكل الطلب، **لم يُجرَّب بحساب حقيقي** |
| شراء داخل التطبيق: آبل | `Stores\StoreGateway` | `sim:{رمز الحساب}:{معرّف}` مع علامات `:pending` · `:revoked` · `:test` · `:renewal-N` | `apple` (App Store Server API) | مكتوب ومختبَر على شكل الطلب، **لم يُجرَّب بحساب حقيقي** |
| شراء داخل التطبيق: Google Play | `Stores\StoreGateway` | كالسابق | `google` (Play Developer API) | مكتوب ومختبَر على شكل الطلب، **لم يُجرَّب بحساب حقيقي** |
| الإشعارات | `Push\PushGateway` | لا يصل شيء للجوال؛ جرس التطبيق يعمل | `fcm` (HTTP v1) | مكتوب ومختبَر على شكل الطلب، **لم يُجرَّب بمشروع Firebase حقيقي** |
| الهيئة العامة للعقار | `Rega\RegaGateway` | قواعد ثابتة (أدناه) | — | لا سائق: ينتظر منح الهيئة واجهة برمجية |
| نفاذ | `Nafath\NafathGateway` | أول فحص «بانتظار المستخدم»، والثاني يوثّق (أو يرفض رقم هوية ينتهي بـ 0)، والطلب ينتهي بعد 3 دقائق بلا جواب | — | لا سائق: ينتظر العقد |
| الفوترة | `Invoicing\InvoicingGateway` | — | `local`: QR المرحلة الأولى لهيئة الزكاة (TLV) | يعمل محلياً؛ المرحلة الثانية (الربط) سائق جديد |
| بصمة الصور | `ImageHash\ImageHasher` | — | `gd` (محلي) · `agents` (خدمة الوكلاء) | يعملان |

الشكل المختبَر لكل سائق حي في `backend/tests/Feature/Integrations/LiveDriversTest.php`، وللمتجرين في `StoreDriversTest.php`.

## قواعد محاكاة الهيئة (`FakeRegaGateway`)

تسمح بتجربة كل فرع في التطبيق:
- رقم ترخيص من 6–12 رقماً صالح، إلا ما ينتهي بـ `0000` فيُرفض.
- رقم صك ينتهي بـ `0000` يُرفض عقده، وغيره يُعتمد عند أول متابعة.
- الروابط إلى `sim.zon.local`، لا إلى أي نطاق حكومي.

## ⚠️ قرار قبل أي إطلاق عام

لا سائق حياً للهيئة ولا لنفاذ. لو أُضيفا إلى `PRODUCTION_ALLOWS_FAKE=rega,nafath`:
- يُقبل أي رقم ترخيص بصيغة صحيحة دون فحصه لدى الهيئة.
- تظهر شارة «موثّق عبر نفاذ» دون توثيق حقيقي.

`.env.example` يتركها فارغة عمداً. البدائل:

| البديل | ما يعنيه |
|---|---|
| إطلاق تجريبي مغلق | حسابات `is_tester` فقط، والسماح بالمحاكاة صراحة |
| تأجيل النشر للجمهور | حتى سائقي الهيئة ونفاذ |
| تحقق يدوي مؤقت | الموظف يطابق رقم الترخيص من منصة الهيئة قبل اعتماد العرض |

## خطوات التحويل إلى الحي

### رسائل التحقق — تقنيات
1. حساب لدى تقنيات، واسم مرسل معتمد.
2. في `.env`:
   - `SMS_DRIVER=taqnyat`
   - `TAQNYAT_TOKEN=…`
   - `TAQNYAT_SENDER=ZON`
3. `OTP_TEST_CODE` فارغ في الإنتاج.
4. التحقق: تسجيل برقم حقيقي. عند أي خطأ: صفحة «صحة النظام»، ثم السجل برقم التتبع.

### الدفع — ميسّر
1. حساب ميسّر، ثم المفاتيح:
   - `MOYASAR_SECRET_KEY` (الخادم فقط).
   - `MOYASAR_PUBLISHABLE_KEY` (يصل للتطبيق عبر `clientPayload`).
2. رابط الإشعارات في لوحة ميسّر: `https://api.zon.sa/api/v1/payments/webhook/moyasar`، مع رمز سري في `MOYASAR_WEBHOOK_SECRET` (يُقارن بزمن ثابت).
3. `PAYMENTS_DRIVER=moyasar`.
4. **قاعدة لا تُكسر:** لا يُطبَّق دفع من محتوى الإشعار أو من التطبيق. الخادم يقرأ العملية من ميسّر (`fetch`)، ويطبّقها مرة واحدة إن تطابق المبلغ والعملة ومعرّفنا في `metadata.payment_id` (`App\Actions\Billing\ConfirmPayment`).
   - **الاسترداد من لوحة ميسّر نفسها** يصل بإشعار `payment_refunded`، فيُقرأ من ميسّر أيضاً: إن أعيد المبلغ كله للعملية نفسها التي دفعت، صارت الدفعة «مستردة» وتوقف ما اشترته، مرة واحدة مهما أعاد ميسّر الإرسال (`RecordProviderRefund`). والاسترداد الجزئي لا يوقف شيئاً، ويُسجَّل في السجل.
   - في الإشعارات المفعّلة في لوحة ميسّر: `payment_paid` و`payment_refunded` على الأقل.
5. التحقق ببطاقات ميسّر التجريبية على مفاتيح الاختبار أولاً:
   - عملية ناجحة، ومرفوضة، ومبلغ معدَّل.
   - ثم ظهورها في اللوحة ← «المدفوعات» والفاتورة.
6. **الموقع** (`zon.sa/packages` ← `/pay/…`):
   - يحمّل نموذج ميسّر بإصدار `MOYASAR_FORM_VERSION`.
   - يعرض طرق الدفع في `MOYASAR_WEB_METHODS` فقط: ما فُعّل في حساب ميسّر.
   - Apple Pay يحتاج تسجيل `zon.sa` في لوحة ميسّر، ووضع ملف Apple في `MOYASAR_APPLE_PAY_DOMAIN_FILE`.
   - التفصيل في [`website.md`](website.md).

### الشراء داخل التطبيق — آبل وGoogle Play

ما يُشترى في التطبيقين: الباقات والتمييز والعاجل. المتجر يبيع ويحدد السعر الظاهر، والخادم يقرأ الشراء من المتجر ويطبّقه (`App\Actions\Billing\ApplyStorePurchase`). الموقع ورسوم الترخيص الإعلاني عبر ميسّر.

1. **المنتجات:** المعرّفات نفسها في المتجرين، كما في `config/zon.php` ← `store_products`:
   - اشتراكات تتجدد آلياً: `com.zon.app.seeker.month` · `com.zon.app.broker.month` · `com.zon.app.broker.year`.
   - منتجات تُستهلك: `com.zon.app.featured` · `com.zon.app.urgent.listing` · `com.zon.app.urgent.request`.
   - السعر في كل متجر يحدده المالك، ويُطابق سعر الباقة في اللوحة يدوياً.
2. **آبل** (App Store Connect):
   - مفتاح In-App Purchase من Users and Access ← Integrations: ملف `.p8` في `/etc/zon/app-store-key.p8` بقراءة لمستخدم التطبيق فقط.
   - في `.env`: `APP_STORE_DRIVER=apple` · `APP_STORE_ISSUER_ID` · `APP_STORE_KEY_ID` · `APP_STORE_PRIVATE_KEY` · `APP_STORE_BUNDLE_ID`.
   - App Store Server Notifications V2 (للإنتاج والتجربة): `https://api.zon.sa/api/v1/store/notices/apple?key=…` بقيمة `STORE_NOTICES_KEY`.
3. **Google Play** (Play Console):
   - حساب خدمة في Google Cloud، مدعو في Play Console بصلاحيتي «عرض البيانات المالية» و«إدارة الطلبات والاشتراكات». ملف JSON في `/etc/zon/google-play-service-account.json`.
   - في `.env`: `GOOGLE_PLAY_DRIVER=google` · `GOOGLE_PLAY_PACKAGE` · `GOOGLE_PLAY_CREDENTIALS`.
   - الإشعارات الفورية (RTDN): موضوع Pub/Sub باشتراك push إلى `https://api.zon.sa/api/v1/store/notices/google?key=…`.
4. **قواعد لا تُكسر:**
   - لا يُطبَّق شراء من كلام التطبيق ولا من الإشعار: الخادم يسأل المتجر.
   - الشراء لحسابه فقط: رمز الحساب (`GET /store/products`) يرسله التطبيق مع كل شراء (`appAccountToken` · `obfuscatedAccountId`).
   - شراء التجربة (sandbox أو بطاقة اختبار) يُقبل لحسابات المراجعة والاختبار فقط.
   - Google يسترد ما لم يُؤكَّد خلال 3 أيام: التأكيد في مهمة تُعاد حتى يوم (`SettleStorePurchase`).
5. **الاسترداد** من المتجر نفسه: آبل بطلب العميل، وGoogle من Play Console. يصلنا إشعاره فيتوقف ما اشتُري تلقائياً، ولا استرداد من اللوحة لمشتريات المتجر.
   - **فترة السماح في Google:** إن فشل تجديد ويعيد Google المحاولة، يمدّ `expiryTime` للعملية نفسها ويبقى الاستحقاق ([Google](https://developer.android.com/google/play/billing/lifecycle/subscriptions)). فتمتد فترة الاشتراك معه، بلا دفعة ثانية ولا فاتورة. ولا تُقصَّر أبداً من قراءة لاحقة.
6. **الضريبة والفاتورة:**
   - آبل وكيل بالعمولة في السعودية، يجبي الضريبة ويوردها ([ملحق اتفاقية آبل](https://developer.apple.com/support/downloads/terms/exhibits/Exhibits-to-Schedule-2-and-3-20250821-English.pdf)). لا فاتورة من زون.
   - Google يجبي ضريبة 15٪ ويوردها، إلا للمطوّر السعودي المسجّل الذي أعطى Google رقمه الضريبي ([مساعدة Play Console](https://support.google.com/googleplay/android-developer/answer/138000)). إن فعلت زون ذلك، فعّل «ضريبة مبيعات Google Play على زون» في الإعدادات: فاتورة زون لكل شراء من Google Play. **قرار للمالك مع المحاسب.**
7. التحقق بحساب مراجعة: شراء تجربة من TestFlight وحساب sandbox، ومن Google Play بحساب مختبِر ترخيص. ثم ظهوره في «المدفوعات»، وتجديده، واسترداده.

### الإشعارات — Firebase
1. مشروع Firebase يضم تطبيقي iOS وAndroid بالمعرّف `com.zon.app`:
   - **مفتاح APNs** (ملف ‎.p8 من حساب آبل للمطورين) في إعدادات المشروع ← Cloud Messaging. بدونه لا يصل شيء لآيفون.
   - **ملفا الإعداد في التطبيق:** `flutterfire configure --project=<المشروع>` من مجلد `app/`، أو تنزيلهما من لوحة Firebase: `android/app/google-services.json` و`ios/Runner/GoogleService-Info.plist` (يُضاف للـ Runner في Xcode). حين يوجد الأول يطبّق أندرويد إضافة google-services وحده (`android/app/build.gradle.kts`).
   - **البناء:** `--dart-define=ZON_PUSH=true`. بدونه، أو بدون الملفين، يعمل التطبيق بلا Push ولا شيء آخر يتغير.
   - **iOS:** قدرة Push Notifications في التوقيع (`ios/Runner/Runner.entitlements`: `aps-environment`)، وRemote notifications في الخلفية (`Info.plist`).
   - **أندرويد:** الأيقونة الصغيرة `drawable/ic_stat_zon` (حرف Z بالأبيض)، ولونها، وقناة «إشعارات زون» التي ينشئها `MainActivity`.
2. حساب خدمة بصلاحية Firebase Cloud Messaging، وملف JSON في `/etc/zon/fcm-service-account.json` بقراءة لمستخدم التطبيق فقط.
3. في `.env`:
   - `PUSH_DRIVER=fcm`
   - `FCM_PROJECT_ID=…`
   - `FCM_CREDENTIALS=/etc/zon/fcm-service-account.json`
4. **المعرّفات عندنا لا عند Firebase** — الطريقة التي يوصي بها Firebase، **بلا Device Groups** التي تتوقف بعد 29 سبتمبر 2027 ([Firebase](https://firebase.google.com/docs/cloud-messaging/migrate-off-device-groups)):
   - **المعرّف هو Firebase Installation ID (FID)**، لا رمز التسجيل القديم: firebase_messaging 17 أهمل `getToken`، وواجهة FCM v1 تقول عن `message.token` «Deprecated: Use fid instead». التطبيق يأخذه من `onRegistered` (مفعّل بـ `firebase_messaging_installation_id_enabled` في أندرويد و`FirebaseMessagingInstallationIdEnabled` في iOS)، والخادم يرسل بـ `message.fid`. اسم الحقل في واجهتنا بقي `push_token`.
   - كل جوال مسجّل الدخول له سطر في `customer_devices`: معرّفه ومنصته وإصداره ووقت آخر تسجيل (`last_seen_at`)، مربوط بجلسته.
   - التطبيق يرسل معرّفه عند الدخول، وعند كل فتح، وعند تغيّره (`POST /me/devices`، `app/lib/data/push/push.dart`). المعرّف القديم للجوال نفسه يُحذف، فلا يصل الإشعار مرتين.
   - الإذن يُطلب مرة بعد الدخول، لا عند أول فتح. من رفض لا يُسأل مجدداً من التطبيق.
   - النقر على الإشعار يفتح وجهته (`target`)، والإشعار والتطبيق مفتوح بطاقة أعلى الشاشة على النظامين.
   - إنهاء الجلسة لأي سبب (الخروج، «أجهزتي»، اللوحة، تغيير كلمة السر، انتهاء الـ 90 يوماً) يحذف معرّفها.
   - الإرسال رسالة لكل جوال (HTTP v1). يُحذف المعرّف الذي يقول FCM إنه لن يعمل أبداً، ويُكمل الإرسال للبقية ([Firebase](https://firebase.google.com/docs/cloud-messaging/manage-tokens)):
     - `UNREGISTERED` (404).
     - `INVALID_ARGUMENT` على المعرّف نفسه (400 مع `message.fid` أو `message.token`). أما الخطأ في محتوى الرسالة فلا يحذف شيئاً.
     - `SENDER_ID_MISMATCH` (403): معرّف مشروع Firebase آخر.
   - أي خطأ آخر يخص الجميع (الدخول، المحتوى، FCM نفسه): يتوقف الإرسال ويُسجَّل، والإشعار باقٍ في الجرس.

### الهيئة العامة للعقار ونفاذ (عند توفر الواجهة أو العقد)
1. صنف جديد يطبّق العقد نفسه، مثل `Rega\RegaApiGateway`:
   - الدوال: `checkFal` · `verifyAdLicence` · `submitBrokerageContract` · `contractStatus` · `issueAdLicence`.
   - لنفاذ: `start` · `status` (يرجع `NafathAnswer`: waiting · completed · rejected · expired، بلغة نفاذ).
   - ما يُتحقق منه في توثيق Elm عند العقد، لا من مصادر غير رسمية: عنوان البيئة، وأسماء الحقول، ومدة الطلب (`zon.nafath.request_seconds`)، وردّ نفاذ إلى الخادم (callback) وتوقيعه.
2. سطره في `IntegrationServiceProvider`، ومفاتيحه في `config/services.php` و`.env.example`.
3. اختبار لشكل الطلب في `LiveDriversTest` بـ `Http::fake`.
4. حذف الجهة من `PRODUCTION_ALLOWS_FAKE`.

ما يستدعي هذه العقود جاهز ولا يتغير:
- إنشاء العرض (`CreateListing`).
- مسار المالك (`OwnerCaseFlow`).
- متابعة العقود كل 10 دقائق (`SyncOwnerContracts`).
- إعادة فحص الرخص (`RecheckBrokerLicenses`، `CheckBrokerLicence`).

### الفوترة — المرحلة الثانية لهيئة الزكاة
- **المرحلة الأولى (اليوم):**
  - البائع والرقم الضريبي من «إعدادات المنصة» (`tax.seller_name` · `tax.vat_number`).
  - الترقيم المتصل لكل سنة في `invoice_counters`.
- **المرحلة الثانية (عند دخول المنشأة موجتها):** سائق جديد لـ `InvoicingGateway` يوقّع الفاتورة ويرسلها لمنصة «فاتورة»، دون تغيير المهمة `App\Jobs\IssueInvoice`.
