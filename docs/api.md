# مرجع واجهة التطبيق — `/api/v1`

> مولَّد من جدول المسارات الفعلي (`_tools/api_doc.py`). أي مسار جديد بلا وصف يوقف التوليد.

## الاتفاقات

- **القاعدة:** `https://api.zon.sa/api/v1` · JSON فقط · الحقول snake_case.
- **الدخول:** `Authorization: Bearer <token>` من `auth/otp/verify` أو `auth/login` (Sanctum، صلاحية 90 يوماً).
- **ترويسات يرسلها التطبيق:**
  - `X-App-Platform` (ios · android) و`X-App-Version`: للتحديث الإجباري (426) ووضع الصيانة (503).
  - `X-Device-Id`: طبقة الجهاز في حصة الذكاء.
  - `X-Request-Id` (اختياري): وإلا يولّده الخادم. يعود في كل رد.
- **النجاح:** `{ "success": true, "data": …, "meta": { … } }`. الصفحات في `meta.page = {current, per_page, total, last}`.
- **الخطأ:** `{ "success": false, "error": { "code", "message", "details", "trace_id" } }`.
  - أخطاء الحقول في `details.fields`.
  - `trace_id` هو رقم التتبع نفسه في السجلات واللوحة («تتبّع طلب»).
- **الأرقام:** الحقول الرقمية تقبل الأرقام العربية والفواصل (`٨٨٠,٠٠٠`)، والقيمة غير الصالحة تُرفض ولا تصير صفراً.
- **الجوال:** يُقبل بأي صيغة سعودية ويُعاد `05XXXXXXXX`.
- **حصة الذكاء:** ردود خدمات الذكاء تحمل `X-AI-Limit` و`X-AI-Remaining` و`X-AI-Resets-At`. النفاد: 429 `QUOTA_EXCEEDED`، والخدمة خارج الباقة: 402 `PAYMENT_REQUIRED`، ومع كليهما `details.upgrade`.
- **حالة الذكاء في الرد:** `meta.ai = {status, reasons}`.
  - `status`: `ok` · `cached` (نفس السؤال خلال 15 دقيقة، بلا خصم) · `degraded` · `fallback` · `timeout` · `rejected` · `skipped` (لا مرشّحين، فلا نداء).
  - الخصم من الرصيد مع `ok` وحده. `reasons = true` مع `ok` و`cached` فقط.
  - مع `degraded` يُستخدم ترتيب الوكيل بلا أسباب، ومع `fallback` و`timeout` و`rejected` يعود ترتيب SQL.
  - المحاولات نفسها محدودة: 3 أضعاف الحد اليومي لكل حساب وخدمة، مجاباً أو لا. بعدها 429 `TOO_MANY_REQUESTS` حتى التجديد.
- **الحد العام:** 120 طلباً في الدقيقة لكل حساب أو عنوان، وحدود أخرى مذكورة في عمود الصلاحية.
- **الزمن الحي:** Reverb على `wss://api.zon.sa/app/…`، والتوثيق عبر `POST /api/v1/broadcasting/auth`.

## رموز الأخطاء

| الحالة | `error.code` | الرسالة الافتراضية |
|---|---|---|
| 401 | `UNAUTHENTICATED` | سجّل الدخول للمتابعة. |
| 401 | `INVALID_CREDENTIALS` | اسم المستخدم أو كلمة السر غير صحيحة. |
| 422 | `OTP_INVALID` | رمز التحقق غير صحيح أو انتهت صلاحيته. |
| 403 | `ACCOUNT_SUSPENDED` | الحساب موقوف. تواصل مع الدعم. |
| 403 | `ACCOUNT_PENDING` | حسابك بانتظار التفعيل بعد توقيع عقد التسويق. |
| 403 | `ROLE_FORBIDDEN` | هذه الخدمة غير متاحة لدورك. |
| 403 | `FAL_REQUIRED` | رخصة فال سارية مطلوبة للنشر. |
| 403 | `NAFATH_REQUIRED` | التحقق عبر نفاذ مطلوب قبل النشر. |
| 404 | `NOT_FOUND` | العنصر غير موجود. |
| 422 | `VALIDATION_FAILED` | تحقق من الحقول المطلوبة. |
| 428 | `PREFERENCE_REQUIRED` | أكمل رغبتك أولاً لتبدأ. |
| 402 | `PAYMENT_REQUIRED` | هذه الخدمة ضمن الباقات. |
| 409 | `PLAN_LIMIT_REACHED` | بلغت حد باقتك. |
| 429 | `QUOTA_EXCEEDED` | تجاوزت الحد المسموح، حاول بعد التجديد. |
| 429 | `TOO_MANY_REQUESTS` | تجاوزت الحد المسموح، حاول بعد قليل. |
| 409 | `INVALID_TRANSITION` | لا يمكن تنفيذ هذا الإجراء على حالته الحالية. |
| 409 | `ALREADY_ACTIVE` | مفعّل بالفعل. |
| 409 | `PROJECT_NO_UNITS` | أضف نموذج وحدة واحداً على الأقل قبل النشر. |
| 409 | `LICENCE_REQUIRED` | الترخيص الإعلاني مطلوب قبل النشر. |
| 403 | `USER_BLOCKED` | تعذّر إرسال الرسالة. |
| 426 | `UPDATE_REQUIRED` | حدّث التطبيق للمتابعة. |
| 503 | `MAINTENANCE_MODE` | المنصة تحت الصيانة، نعود قريباً. |
| 502 | `INTEGRATION_FAILED` | تعذّر الاتصال بالجهة المختصة، حاول لاحقاً. |
| 500 | `SERVER_ERROR` | حدث خطأ غير متوقع. |

## نقاط الواجهة (135)

الصلاحية: `عام` بلا دخول · 🔒 رمز مميِّز · الدور · `رغبة` = الباحث يحتاج رغبة فعّالة (428) · `حصة` = رصيد الذكاء اليومي. العمود الأخير موضع الكود في `app/Http/Controllers/Api/V1`.

### ١. التطبيق والمرجع

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `GET` | `/app/config` | عام | قيم الإقلاع: إصدار المنصة (`X-App-Platform`) والصيانة، رابط المشاركة، رقم نسخة المرجع، حدود الصور والشات | `AppController@config` |
| `GET` | `/catalog` | عام | المرجع: المدن وأحياؤها، أنواع العقار وحقول كل نوع، وقوائم الاختيار (الغرض، الأدوار، الإشغال، فئات الملاحظات، أسباب البلاغ، تفضيلات الإشعار) | `AppController@catalog` |
| `GET` | `/legal/{slug}` | عام | صفحة سياسة بنصها ورقم نسختها (terms · privacy · …) كما تحرَّر من اللوحة | `AppController@legal` |
| `POST` | `/support-tickets` | عام · reports: 20/س | تذكرة دعم (للزائر والمسجّل)؛ تُرجع رقم القيد، والرد من اللوحة | `SupportController@store` |

### ٢. التسجيل والدخول

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `POST` | `/auth/login` | عام · login: 5/د | الدخول بـ `login` = اسم المستخدم أو الجوال + كلمة السر؛ يلغي طلب حذف معلّقاً | `AuthController@login` |
| `POST` | `/auth/logout` | 🔒 | إلغاء رمز هذا الجهاز | `AuthController@logout` |
| `POST` | `/auth/otp/resend` | عام · otp: 3/10د للجوال · 10/س للعنوان | إعادة إرسال الرمز (3 كل 10 دقائق للجوال) | `AuthController@resend` |
| `POST` | `/auth/otp/verify` | عام | تأكيد رمز التسجيل: ينشئ الحساب ويُرجع الرمز المميِّز وبيانات حسابي (201) | `AuthController@verify` |
| `POST` | `/auth/password/forgot` | عام · otp: 3/10د للجوال · 10/س للعنوان | إرسال رمز استعادة كلمة السر إلى الجوال | `AuthController@forgot` |
| `POST` | `/auth/password/reset` | عام · login: 5/د | تعيين كلمة سر جديدة بالرمز؛ يُخرج كل الأجهزة | `AuthController@reset` |
| `POST` | `/auth/register` | عام · otp: 3/10د للجوال · 10/س للعنوان | بدء التسجيل: يحفظ النموذج مؤقتاً 15 دقيقة ويرسل رمز الجوال (202) | `AuthController@register` |

### ٣. حسابي ورغباتي وإشعاراتي

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `POST` | `/account/delete` | 🔒 | طلب حذف الحساب: يُخفى المحتوى فوراً، والحذف النهائي بعد المهلة | `AccountController@requestDeletion` |
| `POST` | `/account/delete/cancel` | 🔒 | إلغاء طلب الحذف خلال المهلة وإرجاع المحتوى المخفي | `AccountController@cancelDeletion` |
| `POST` | `/account/legacy-claims` | 🔒 · reports: 20/س | طلب استعادة بيانات الإصدار السابق؛ تراجعه اللوحة يدوياً | `AccountController@legacyClaim` |
| `GET` | `/me` | 🔒 | حسابي: الدور، الحالة، فال، نفاذ، الباقة وحدودها، تفضيلات الإشعار | `MeController@show` |
| `PATCH` | `/me` | 🔒 | تعديل الاسم والمدينة | `MeController@update` |
| `POST` | `/me/devices` | 🔒 | تسجيل رمز Push للجهاز (FCM) ومنصته | `MeController@registerDevice` |
| `POST` | `/me/nafath` | 🔒 · otp: 3/10د للجوال · 10/س للعنوان | بدء التحقق عبر نفاذ: يُرجع الرقم الذي يختاره المستخدم في تطبيق نفاذ | `MeController@startNafath` |
| `GET` | `/me/nafath` | 🔒 | حالة التحقق عبر نفاذ | `MeController@nafathStatus` |
| `PUT` | `/me/notification-preferences` | 🔒 | مفاتيح الإشعارات الست: رسائل · نشر المتابَعين · طلبات المنطقة · حالة الإعلان · تقييمات · مطابقة | `MeController@notificationPrefs` |
| `PUT` | `/me/password` | 🔒 · login: 5/د | تغيير كلمة السر (`current_password` مطلوبة)؛ تخرج الأجهزة الأخرى | `MeController@changePassword` |
| `POST` | `/me/phone` | 🔒 · otp: 3/10د للجوال · 10/س للعنوان | طلب تغيير الجوال: رمز إلى الرقم الجديد | `MeController@changePhone` |
| `POST` | `/me/phone/verify` | 🔒 | تأكيد الجوال الجديد بالرمز | `MeController@verifyPhone` |
| `GET` | `/notifications` | 🔒 | الإشعارات (صفحات)، و`meta.unread` | `NotificationController@index` |
| `POST` | `/notifications/read` | 🔒 | تعليم إشعارات أو كلها كمقروءة | `NotificationController@read` |
| `GET` | `/preferences` | 🔒 | رغباتي (الباحث) | `PreferenceController@index` |
| `POST` | `/preferences` | 🔒 | إضافة رغبة: المدينة، الأحياء، الأنواع، الميزانية، الملاحظات، ومشاركتها في التقارير | `PreferenceController@store` |
| `PUT|PATCH` | `/preferences/{preference}` | 🔒 | تعديل رغبة؛ إعادة تفعيلها تُحسب من حد الباقة | `PreferenceController@update` |
| `DELETE` | `/preferences/{preference}` | 🔒 | حذف رغبة | `PreferenceController@destroy` |
| `GET` | `/support-tickets` | 🔒 | تذاكري وردود الدعم | `SupportController@index` |

### ٤. التصفح العام

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `GET` | `/customers/{username}` | عام | الملف العام باسم المستخدم: المدينة، التقييم وتقييمي له، أعداد المتابِعين والعروض، وهل أتابعه | `ProfileController@show` |
| `GET` | `/customers/{username}/listings` | عام | عروض المعلن المنشورة | `ProfileController@listings` |
| `GET` | `/listings` | عام | العروض المنشورة مع التصفية (المدينة، الحي، النوع، الغرض، السعر…) والفرز؛ المميز أولاً | `ListingController@index` |
| `GET` | `/listings/{listing}` | عام | تفاصيل عرض منشور (أو عرضي بأي حالة)؛ أرقام التواصل للمسجّلين فقط، ولا شيء بين متحاظرَين | `ListingController@show` |
| `GET` | `/projects` | عام | المشاريع المنشورة مع التصفية | `ProjectController@index` |
| `GET` | `/projects/{project}` | عام | تفاصيل مشروع ووحداته؛ نموذج المشروع «الجاهز» لا يظهر للعموم إلا بترخيص ساري | `ProjectController@show` |
| `GET` | `/projects/{project}/units/{unit}` | عام | تفاصيل نموذج وحدة | `ProjectController@unit` |
| `GET` | `/requests` | عام | طلبات البحث المنشورة: تصفية بالدور والغرض والمدينة والحي والنوع، والفرز `new` أو `urgent`؛ التواصل حسب الحق (`access`) | `RequestController@index` |
| `GET` | `/requests/{searchRequest}` | عام | تفاصيل طلب بحث | `RequestController@show` |

### ٥. العروض والصور

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `POST` | `/listings` | 🔒 · رغبة · وسيط · مالك · مطوّر · wording: 60/س | إضافة عرض: البوابات (فال/نفاذ/الحد/الترخيص أو ملف المالك) ← الصياغة في طابور `ai` ← `pending_review` أو النشر | `ListingController@store` |
| `GET` | `/listings/mine` | 🔒 · رغبة | عروضي بكل حالاتها | `ListingController@mine` |
| `PATCH` | `/listings/{listing}` | 🔒 · رغبة · وسيط · مالك · مطوّر · wording: 60/س | تعديل عرضي؛ تغيير النص يعيده إلى مراجعة الصياغة | `ListingController@update` |
| `DELETE` | `/listings/{listing}` | 🔒 · رغبة · وسيط · مالك · مطوّر | حذف عرضي (عدا المباع) وإخراجه من الفهرس | `ListingController@destroy` |
| `POST` | `/listings/{listing}/boost` | 🔒 · رغبة · وسيط · مالك · مطوّر | تمييز أو عاجل: من رصيد الباقة، وإلا عملية دفع | `ListingController@boost` |
| `POST` | `/listings/{listing}/pause` | 🔒 · رغبة · وسيط · مالك · مطوّر | إيقاف مؤقت (من «منشور» فقط) | `ListingController@pause` |
| `POST` | `/listings/{listing}/resume` | 🔒 · رغبة · وسيط · مالك · مطوّر | استئناف ما أوقفه المعلن أو صار «غير نشط». المخفي والمرفوض يعيدهما الإشراف وحده | `ListingController@resume` |
| `GET` | `/listings/{listing}/review` | 🔒 · رغبة · وسيط · مالك · مطوّر | الصياغة المقترحة مقابل النص الأصلي | `ListingController@review` |
| `POST` | `/listings/{listing}/review` | 🔒 · رغبة · وسيط · مالك · مطوّر | قرار المعلن: `approve` المقترح · `edit` بنصه · `reject` فيبقى نصه الأصلي ← النشر | `ListingController@decide` |
| `POST` | `/listings/{listing}/sold` | 🔒 · رغبة · وسيط · مالك · مطوّر | تم البيع (نهائي)؛ عرض المالك يُنشئ سعي زون المستحق من سعر البيع | `ListingController@sold` |
| `POST` | `/media` | 🔒 · رغبة · uploads: 40/د | رفع صورة `photo` (jpg · png · webp حتى 8MB) إلى MinIO؛ تُربط بالعرض أو المشروع بمعرّفها | `MediaController@store` |
| `DELETE` | `/media/{media}` | 🔒 · رغبة | حذف صورة لي | `MediaController@destroy` |

### ٦. المشاريع والوحدات (المطوّر)

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `POST` | `/projects` | 🔒 · رغبة · مطوّر · wording: 60/س | إضافة مشروع (المطوّر المفعّل بعقد تسويق) | `ProjectController@store` |
| `GET` | `/projects/mine` | 🔒 · رغبة · مطوّر | مشاريعي بكل حالاتها | `ProjectController@mine` |
| `PATCH` | `/projects/{project}` | 🔒 · رغبة · مطوّر · wording: 60/س | تعديل مشروعي | `ProjectController@update` |
| `POST` | `/projects/{project}/pause` | 🔒 · رغبة · مطوّر | إيقاف مؤقت (من «منشور» فقط) | `ProjectController@pause` |
| `POST` | `/projects/{project}/publish` | 🔒 · رغبة · مطوّر | النشر الأول: وصف مقرَّر، وحدة واحدة على الأقل، وترخيص لكل نموذج في «الجاهز» | `ProjectController@publish` |
| `POST` | `/projects/{project}/resume` | 🔒 · رغبة · مطوّر | استئناف ما أوقفه المطوّر، بعد شروط النشر كاملة من جديد | `ProjectController@resume` |
| `GET` | `/projects/{project}/review` | 🔒 · رغبة · مطوّر | الصياغة المقترحة لوصف المشروع | `ProjectController@review` |
| `POST` | `/projects/{project}/review` | 🔒 · رغبة · مطوّر | قرار المطوّر على وصف المشروع | `ProjectController@decide` |
| `POST` | `/projects/{project}/units` | 🔒 · رغبة · مطوّر · wording: 60/س | إضافة نموذج وحدة | `ProjectUnitController@store` |
| `POST` | `/projects/{project}/units/review` | 🔒 · رغبة · مطوّر | قرار المطوّر على أوصاف الوحدات دفعة واحدة | `ProjectUnitController@decide` |
| `PATCH` | `/projects/{project}/units/{unit}` | 🔒 · رغبة · مطوّر · wording: 60/س | تعديل نموذج وحدة | `ProjectUnitController@update` |
| `DELETE` | `/projects/{project}/units/{unit}` | 🔒 · رغبة · مطوّر | حذف نموذج وحدة | `ProjectUnitController@destroy` |
| `POST` | `/projects/{project}/units/{unit}/licence` | 🔒 · رغبة · مطوّر | التحقق من ترخيص الوحدة لدى الهيئة (REGA) | `ProjectUnitController@licence` |
| `POST` | `/projects/{project}/units/{unit}/sold` | 🔒 · رغبة · مطوّر | بيع عدد من وحدات النموذج؛ عمولة العقد لكل وحدة | `ProjectUnitController@sold` |

### ٧. مسار المالك

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `GET` | `/owner-cases` | 🔒 · رغبة · مالك | ملفاتي كمالك | `OwnerCaseController@index` |
| `POST` | `/owner-cases` | 🔒 · رغبة · مالك | فتح ملف: الصك ← عقد الوساطة مع زون وموافقة نفاذ ← رسوم الترخيص ← الترخيص | `OwnerCaseController@store` |
| `GET` | `/owner-cases/{ownerCase}` | 🔒 · رغبة · مالك | حالة الملف وخطوته التالية | `OwnerCaseController@show` |

### ٨. طلبات البحث

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `POST` | `/requests` | 🔒 · رغبة | إضافة طلب بحث؛ `urgent` من الرصيد أو بالدفع (النتيجة في `meta.urgent`) | `RequestController@store` |
| `GET` | `/requests/area` | 🔒 · رغبة · وسيط | طلبات مدينة الوسيط من غيره، العاجل أولاً، مع حقه في التواصل (`meta.access`) | `RequestController@area` |
| `GET` | `/requests/mine` | 🔒 · رغبة | طلباتي | `RequestController@mine` |
| `POST` | `/requests/{searchRequest}/close` | 🔒 · رغبة | إغلاق طلبي | `RequestController@close` |
| `POST` | `/requests/{searchRequest}/to-client` | 🔒 · رغبة · وسيط · مطوّر | تحويل طلب إلى عميل في مكتبي | `RequestController@toClient` |

### ٩. التواصل والمجتمع

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `GET|POST` | `/broadcasting/auth` | 🔒 | توثيق القنوات الخاصة لـ Reverb: `customers.{id}` و`conversations.{id}` | `BroadcastController@authenticate` |
| `GET` | `/conversations` | 🔒 · رغبة | محادثاتي مع آخر رسالة وغير المقروء، الأحدث أولاً (30 في الصفحة) | `ConversationController@index` |
| `POST` | `/conversations` | 🔒 · رغبة · messages: 30/د | بدء محادثة مع `to` (اسم المستخدم) حول عرض أو مشروع أو طلب منشور لأحد الطرفين، بأول رسالة (تُرفض بين متحاظرَين) | `ConversationController@store` |
| `GET` | `/conversations/{conversation}/messages` | 🔒 · رغبة | رسائل محادثة أنا طرف فيها (صفحات) | `ConversationController@messages` |
| `POST` | `/conversations/{conversation}/messages` | 🔒 · رغبة · messages: 30/د | إرسال رسالة؛ تُبث على `conversations.{id}` وتُشعِر الطرف الآخر | `ConversationController@send` |
| `POST` | `/conversations/{conversation}/read` | 🔒 · رغبة | تعليم المحادثة مقروءة | `ConversationController@markRead` |
| `POST` | `/customers/{username}/block` | 🔒 · رغبة | حظر: يلغي المتابعة بالاتجاهين، ويخفي المحتوى ويمنع الرسائل بين الطرفين | `SocialController@block` |
| `DELETE` | `/customers/{username}/block` | 🔒 · رغبة | إلغاء الحظر | `SocialController@unblock` |
| `POST` | `/customers/{username}/follow` | 🔒 · رغبة | متابعة معلن | `SocialController@follow` |
| `DELETE` | `/customers/{username}/follow` | 🔒 · رغبة | إلغاء المتابعة | `SocialController@unfollow` |
| `POST` | `/customers/{username}/rating` | 🔒 · رغبة | تقييم بالنجوم (مرة لكل معلن، قابلة للتعديل) | `SocialController@rate` |
| `GET` | `/favorites` | 🔒 · رغبة | مفضلتي | `FavoriteController@index` |
| `POST` | `/favorites` | 🔒 · رغبة | إضافة إلى المفضلة (`listing` · `project`) | `FavoriteController@store` |
| `DELETE` | `/favorites/{type}/{id}` | 🔒 · رغبة | إزالة من المفضلة | `FavoriteController@destroy` |
| `GET` | `/me/followers` | 🔒 · رغبة | من يتابعني | `SocialController@followers` |
| `GET` | `/me/following` | 🔒 · رغبة | من أتابع | `SocialController@following` |
| `POST` | `/reports` | 🔒 · رغبة · reports: 20/س | بلاغ عمّا يراه المبلِّغ: عرض أو مشروع أو طلب منشور، حساب، تقييم، أو رسالة في محادثة هو طرفها؛ ثلاثة مبلِّغين مختلفين يخفون آلياً | `ReportController@store` |

### ١٠. المكتب: العملاء والملاك والمخزون والمهام

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `GET` | `/clients` | 🔒 · رغبة · وسيط · مطوّر | عملائي | `ClientController@index` |
| `POST` | `/clients` | 🔒 · رغبة · وسيط · مطوّر | إضافة عميل | `ClientController@store` |
| `GET` | `/clients/{client}` | 🔒 · رغبة · وسيط · مطوّر | تفاصيل عميل | `ClientController@show` |
| `PUT|PATCH` | `/clients/{client}` | 🔒 · رغبة · وسيط · مطوّر | تعديل عميل | `ClientController@update` |
| `DELETE` | `/clients/{client}` | 🔒 · رغبة · وسيط · مطوّر | حذف عميل | `ClientController@destroy` |
| `GET` | `/clients/{client}/notes` | 🔒 · رغبة · وسيط · مطوّر | ملاحظات العميل | `ClientController@notes` |
| `POST` | `/clients/{client}/notes` | 🔒 · رغبة · وسيط · مطوّر | إضافة ملاحظة (فئة + نص) | `ClientController@addNote` |
| `DELETE` | `/clients/{client}/notes/{note}` | 🔒 · رغبة · وسيط · مطوّر | حذف ملاحظة | `ClientController@deleteNote` |
| `GET` | `/inventory` | 🔒 · رغبة · وسيط · مطوّر | مخزوني (خاص؛ لا يُفهرس ولا يظهر لأحد) | `InventoryController@index` |
| `POST` | `/inventory` | 🔒 · رغبة · وسيط · مطوّر | إضافة عقار إلى المخزون | `InventoryController@store` |
| `GET` | `/inventory/{item}` | 🔒 · رغبة · وسيط · مطوّر | تفاصيل عقار في المخزون | `InventoryController@show` |
| `PUT|PATCH` | `/inventory/{item}` | 🔒 · رغبة · وسيط · مطوّر | تعديله | `InventoryController@update` |
| `DELETE` | `/inventory/{item}` | 🔒 · رغبة · وسيط · مطوّر | حذفه | `InventoryController@destroy` |
| `POST` | `/inventory/{item}/publish` | 🔒 · رغبة · وسيط · مطوّر · wording: 60/س | نشره كإعلان عبر مسار `POST /listings` نفسه وبوّاباته | `InventoryController@publish` |
| `GET` | `/property-owners` | 🔒 · رغبة · وسيط | ملاك العقارات لدي | `PropertyOwnerController@index` |
| `POST` | `/property-owners` | 🔒 · رغبة · وسيط | إضافة مالك | `PropertyOwnerController@store` |
| `GET` | `/property-owners/{owner}` | 🔒 · رغبة · وسيط | تفاصيل مالك | `PropertyOwnerController@show` |
| `PUT|PATCH` | `/property-owners/{owner}` | 🔒 · رغبة · وسيط | تعديل مالك | `PropertyOwnerController@update` |
| `DELETE` | `/property-owners/{owner}` | 🔒 · رغبة · وسيط | حذف مالك | `PropertyOwnerController@destroy` |
| `GET` | `/property-owners/{owner}/notes` | 🔒 · رغبة · وسيط | ملاحظات المالك | `PropertyOwnerController@notes` |
| `POST` | `/property-owners/{owner}/notes` | 🔒 · رغبة · وسيط | إضافة ملاحظة | `PropertyOwnerController@addNote` |
| `DELETE` | `/property-owners/{owner}/notes/{note}` | 🔒 · رغبة · وسيط | حذف ملاحظة | `PropertyOwnerController@deleteNote` |
| `GET` | `/tasks` | 🔒 · رغبة | مهامي | `TaskController@index` |
| `POST` | `/tasks` | 🔒 · رغبة | إضافة مهمة (أولوية، موعد تذكير) | `TaskController@store` |
| `PUT|PATCH` | `/tasks/{task}` | 🔒 · رغبة | تعديل مهمة | `TaskController@update` |
| `DELETE` | `/tasks/{task}` | 🔒 · رغبة | حذف مهمة | `TaskController@destroy` |
| `POST` | `/tasks/{task}/done` | 🔒 · رغبة | إنجاز مهمة | `TaskController@done` |

### ١١. الباقات والدفع والفواتير

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `POST` | `/checkout` | 🔒 · رغبة | بدء عملية دفع `purpose`: subscription · featured · urgent_listing · urgent_request · ad_licence. الخادم يسعّر ويعيد استخدام عملية معلّقة خلال 30 دقيقة | `BillingController@checkout` |
| `GET` | `/invoices` | 🔒 · رغبة | فواتيري | `BillingController@invoices` |
| `GET` | `/invoices/{invoice}` | 🔒 · رغبة | فاتورة (رقم `ZON-YYYY-NNNNNN` المتسلسل) | `BillingController@invoice` |
| `GET` | `/packages` | عام | الباقات الفعّالة بأسعارها وأرصدتها وميزاتها وهل تُشترى، وأسعار الخدمات المفردة (تمييز، عاجل…) | `BillingController@packages` |
| `POST` | `/payments/webhook/{provider}` | عام | إشعار مزوّد الدفع؛ لا يُصدَّق محتواه — يُقرأ الدفع من المزوّد ثم يُطبَّق (ConfirmPayment) | `PaymentWebhookController@handle` |
| `GET` | `/payments/{payment}` | 🔒 · رغبة | حالة عملية دفع لي (المعرّف العام `public_id`) | `BillingController@payment` |
| `POST` | `/payments/{payment}/confirm` | 🔒 · رغبة | تأكيد بعد الدفع في التطبيق: قراءة المزوّد ثم التطبيق مرة واحدة والفاتورة | `BillingController@confirm` |
| `GET` | `/subscription` | 🔒 · رغبة | باقتي ومدتها وأرصدتها وحدودها | `BillingController@subscription` |

### ١٢. الذكاء

| الطريقة | المسار | الصلاحية | ما يفعله | الكود |
|---|---|---|---|---|
| `POST` | `/ai/chat` | 🔒 · رغبة · حصة chat | شات زون: سؤال حسب النية من مكتبة المعرفة، مع حارس يستبدل الإجابة غير الآمنة | `AiController@chat` |
| `GET` | `/ai/chat` | 🔒 · رغبة | سجل محادثتي مع زون | `AiController@chatHistory` |
| `POST` | `/ai/feedback` | 🔒 · رغبة | «مطابق/غير مطابق» على نتيجة؛ السبب إلزامي لغير المطابق ويُخزَّن مقنَّعاً | `AiController@feedback` |
| `POST` | `/ai/matching` | 🔒 · رغبة · باحث · مطوّر · حصة matching | مساعد المطابقة: من رغبتي (أو حاجة مرسلة) ← عروض مطابقة، عروض أوسع، ومشاريع | `AiController@matching` |
| `GET` | `/ai/matching/projects/{project}/brokers` | 🔒 · رغبة · مطوّر | وسطاء مدينة المشروع بعدد عملائهم المطابقين — دون كشف العملاء | `AiController@brokers` |
| `POST` | `/ai/matching/projects/{project}/searchers` | 🔒 · رغبة · مطوّر · حصة matching | باحثون تناسبهم وحدات مشروعي المنشور، مع الوحدة الأنسب | `AiController@searchers` |
| `GET` | `/ai/quota` | 🔒 · رغبة | رصيدي اليومي لكل خدمة ذكاء ووقت التجديد | `AiController@quota` |
| `POST` | `/ai/search` | 🔒 · رغبة · حصة search | البحث الدلالي في العروض: الخانات الإجبارية + نص حر؛ مرشّحو SQL ← ترتيب الوكيل ← أسباب ≤12 كلمة | `AiController@search` |
| `POST` | `/ai/search/requests` | 🔒 · رغبة · وسيط · حصة search | بحث الوسيط في طلبات الباحثين (ميزة باقة: 402 بدونها) | `AiController@searchRequests` |
| `POST` | `/ai/summaries` | 🔒 · رغبة · حصة summary | ملخص ملاحظات عميل أو مالك أو مهامي (نطاق مالكها فقط)، مخزَّن ويُعاد ما لم تتغير الملاحظات | `AiController@summarize` |
