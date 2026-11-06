# سجل الاختبارات — زون 1.2.1+4

**التاريخ:** 8 أكتوبر 2026. **النتيجة:** 126 اختبارًا: **123 ناجحًا، 0 فاشلًا، 3 متروكة عمدًا** (اختبارات تعطيل المحاكاة)، ثم الثلاثة بأمرها الخاص: **3/3**. الفحص الساكن: `No issues found!`. التنسيق: 0 ملفات تحتاج تنسيقًا.

هذا السجل يذكر ما شُغّل فعلًا وكيف، وما **لم** يُختبر. الصور لا تُعد إثباتًا للملكية أو الدفع أو الحفظ؛ الإثبات في الاختبارات التي تقرأ القيم المحفوظة. نجاح المحاكاة لا يعني اكتمال الربط.

## البيئة — بصراحة

| العنصر | ما استُخدم |
|---|---|
| الجهاز | حاوية Linux سحابية (x86-64). **لا هاتف ولا محاكي iOS/Android.** |
| إطار Flutter | كود Flutter 3.47.5 مع محرك Flutter يعمل بلا شاشة (headless) |
| Dart | 3.13.4 (stable) |
| حزمة الاختبار | `flutter_test` الحقيقية من Flutter SDK، مع `test_api` و`matcher` و`fake_async` و`leak_tracker` |
| حزم التطبيق | shared_preferences 2.5.5، image_picker 1.2.3، speech_to_text 7.5.0، crypto 3.0.x، **path_provider 2.1.x** (جديدة لمجلد الصور — F05) من مستودعاتها الرسمية على GitHub |
| أداة التشغيل | **لم يكن أمر `flutter` ولا pub.dev متاحين.** شُغّلت ملفات `test/*_test.dart` كما هي بمشغّل بديل يحمّلها عبر `test_api` (المحرك الذي يستخدمه `flutter test`) داخل محرك Flutter بلا شاشة مع `FLUTTER_TEST=true`، ويستدعي `test/flutter_test_config.dart` كما يفعل `flutter test`. يُنصح بإعادة التشغيل بالأمر الرسمي |
| الخط في الاختبار | خط التطبيق صار **خط النظام: San Francisco على iPhone** (SF Pro + SF Arabic). المحرك لا يملكه ولا يجوز نسخه، فيسجّل `flutter_test_config.dart` بدائل مرخّصة من `test/fonts/`: **Inter** بدل SF Pro و**Noto Sans Arabic** بدل SF Arabic. تحققتُ بقياس فعلي أن النص اللاتيني يُقاس بـ Inter والعربي بـ Noto Sans Arabic (لا بخط الاختبار المربع). Noto Sans Arabic أعرض من خط IBM Plex السابق بنحو 5–14٪ في العبارات المجرّبة |
| الفحص الساكن | `dart analyze lib test tool` ← `No issues found!` |
| التنسيق | `dart format --set-exit-if-changed lib test` ← 0 ملفات |

## الأوامر

```bash
flutter pub get
flutter analyze
flutter test                                                                   # 126: 123 ✅ و3 متروكة
flutter test --dart-define=ZON_SIMULATIONS=false test/no_simulations_test.dart   # 3/3
```

شغّل `flutter test` من مجلد المشروع (بدائل الخط تُقرأ من `test/fonts/`).

## الترتيب الذي طلبه تقرير الفحص

1. **الاختبارات الأصلية مع خط التطبيق:** اختبارات 1.2.0+3 الـ81 كلها ناجحة دون تغيير أي توقع فيها، مع بدائل خط النظام الموصوفة أعلاه (عدد اختباراتها الآن 81 من أصل 126).
2. **الحالات المستقلة:** ملفات A01–A18 لم تصل مع التقرير (وصل نصه فقط)، فكُتبت لكل رمز F حالات مكافئة باسمه (42 حالة جديدة، الجدول أدناه). **يُنصح بإعادة تشغيل ملفات A01–A18 الأصلية على هذه النسخة.**
3. **تعطيل المحاكاة:** `test/no_simulations_test.dart` متروك في التشغيل العادي، وبالأمر المخصص: 3/3.

## قبل الإصلاح وبعده

شُغّلت الاختبارات الجديدة التي تُبنى على واجهات 1.2.0+3 نفسها (بنسخ ملفاتها إلى نسخة منفصلة من 1.2.0+3) لإثبات أنها تكشف الخلل:

| الملف | على 1.2.0+3 | على 1.2.1+4 |
|---|---|---|
| `project_units_test.dart` (F03 · F04 · F07) | 2 من 10 (8 فشلت) | 10 من 10 |
| `listing_state_test.dart` (F06 · F07) | 1 من 6 (5 فشلت) | 6 من 6 |
| `dictation_timing_test.dart` (F08 · F09) | 2 من 7 (5 فشلت) | 7 من 7 |

الحالات التي نجحت قبلًا ضمانات لسلوك كان صحيحًا ويجب أن يبقى (المشروع على الخارطة، السعر الفارغ صفر، العرض النشط يبقى نشطًا، الكلمات النهائية بلا كتابة يدوية، الجلسة الجديدة). اختبارات F01 وF02 وF05 وF10 تستخدم واجهات جديدة لا توجد في 1.2.0+3؛ دليل «قبل» فيها حالات التقرير A01–A04 وA12 وA18.

## F01–F10 ← الاختبار ← النتيجة

| الرمز | الاختبار | النتيجة | ينتظر |
|---|---|---|---|
| F01 | `legacy_migration_test` (6): حساب معروف يحتفظ بعروضه وطلباته ومخزونه وصلاحياته · إعادة الترحيل لا تكرر · كاتب آخر أو دور مختلف لا يُنسب · حساب لاحق بالاسم نفسه لا يأخذ شيئًا · بلا جلسة 1.1 لا نسبة · بطاقة «طلب استعادة» لا تنقل شيئًا | ✅ 6/6 | التحقق من طلبات الاستعادة (خادم/دعم) |
| F02 | `account_switch_test` F02 (6): الاشتراك · تمييز العرض · طلب عاجل جديد · ترقية طلب · رسوم المالك · منع النقر المزدوج لكل حساب | ✅ 6/6 (دفع محاكاة) | بوابة دفع حقيقية ومطابقة العمليات المعلّقة |
| F03 | `project_units_test` F03 (6) | ✅ 6/6 | — |
| F04 | `project_units_test` F04 (3) | ✅ 3/3 | — |
| F05 | `photo_store_test` (5): الصورة تبقى بعد حذف ملف الاستوديو المؤقت وإعادة التشغيل وترتيب الغلاف محفوظ · الإلغاء · تعذر النسخ · صور 1.2.0 · غلاف المشروع وصورة الوحدة والتنظيف | ✅ 5/5 (بمعرض وهمي وملفات حقيقية على القرص) | الاستوديو الحقيقي وتنظيف ذاكرة الهاتف، والرفع للخادم |
| F06 | `listing_state_test` F06 (4) | ✅ 4/4 | — |
| F07 | `listing_state_test` F07 (2) · `project_units_test` F07 (1) | ✅ 3/3 | — |
| F08 | `dictation_timing_test` F08 (4): الإيقاف أثناء الإذن · أثناء البدء · مغادرة الصفحة · تبديل الحقل | ✅ 4/4 (بديل خدمة مؤجل) | إذن الميكروفون الفعلي على هاتف |
| F09 | `dictation_timing_test` F09 (3) | ✅ 3/3 (بديل خدمة مؤجل) | خدمة الكلام الحقيقية على هاتف |
| F10 | `account_switch_test` F10 (2): الرجوع أثناء الدفع لا يغلق النافذة · الرجوع قبل الدفع يغلقها بلا خصم | ✅ 2/2 (أمر الرجوع نفسه الذي يرسله المحرك) | زر الرجوع وإيماءته على هاتف |

## ملاحظات أثناء التشغيل

- **توقعان صُحّحا في اختباراتي الجديدة (لا في الأصلية):** (1) توقعتُ أن رقم الترخيص المكتوب بالأرقام العربية يُحفظ بها، والتطبيق يحفظه بالأرقام اللاتينية كما يثبت `licence_test` الأصلي — صُحح التوقع إلى `7200005555`. (2) أضفتُ في البداية توقعًا أن العرض الموقوف لا يظهر للآخرين؛ تبيّن أن الرئيسية والبحث والمطابقة تعرض العروض غير النشطة منذ 1.2.0 (خارج F07)، فحُذف التوقع وسُجلت النقطة قرارًا لصاحب التطبيق في `OPEN_POINTS.md` بدل تغيير سلوك لم يُطلب.
- **F10:** بعد وصول نتيجة الدفع تحتاج النافذة بضعة إطارات لحركة الإغلاق؛ الاختبار ينتظرها حتى ثانية واحدة. تحققتُ أن الاختبار يفشل إذا أُعيد `canPop: true`.
- **F08:** طابور نداءات خدمة الكلام عام؛ الاختبارات تعيد ضبطه في `setUp` (`resetDictationQueue`) لأن كل اختبار يعمل في منطقة تنفيذ مستقلة.
- **الخط:** بعد التحويل إلى خط النظام نجحت كل الاختبارات بالبديلين، ومنها `text_scale_test` و`text_fit_test` (عرض 360 مع ×1.2) واختبارات موضع المايك.
- بدائل الهاتف داخل الاختبارات فقط: `dictation` و`photoPicker` (ومعها `SlowDictation` المؤجل في F08/F09)، و`PaymentSimulator.gate` لتعليق نتيجة الدفع.

## ما لم يُختبر

- **لم يُبنَ IPA ولا APK، ولم يُجرَّب التطبيق على iPhone أو Android.**
- **خط SF الحقيقي على iPhone** (SF Pro + SF Arabic): الاختبارات والصور هنا ببدائل مرخّصة متقاربة. على Android يظهر خط النظام لأن SF لا يجوز تضمينه.
- الإملاء الحقيقي وإذن الميكروفون، والاستوديو الحقيقي وتنظيف ذاكرة الهاتف، وزر الرجوع وإيماءته على هاتف.
- أي دفع حقيقي؛ الربط مع الهيئة ونفاذ وSMS وPush؛ رفع الصور وعرضها من جهاز آخر.
- التخزين محلي: «إعادة التشغيل» في الاختبارات تعيد تهيئة المخزن فوق التفضيلات المحفوظة، وليست إغلاق عملية من النظام أو مزامنة بين جهازين.

## صور الشاشات

`docs/history/screenshots_v3_1.pdf` (120 صورة): المواضع المتأثرة بإصلاحات F01–F10، ثم كل شاشات النسخة الثالثة بعد تغيير الخط. التُقطت من تشغيل كود التطبيق نفسه في محرك Flutter بلا شاشة (390×844، و360×780 مع ×1.2 لصور تكبير الخط) بأداة `tool/screenshot_harness/`، بالبديلين نفسيهما للخط. الصور توضيح فقط ولا تثبت السلوك.

## القائمة الكاملة لآخر تشغيل

#### `test/account_switch_test.dart`

- ✅ F02 — the result goes to the account that paid subscription: A pays, B signs in meanwhile → A has the plan, B has nothing
- ✅ F02 — the result goes to the account that paid listing boost: the featuring and its ledger entry stay with A
- ✅ F02 — the result goes to the account that paid new urgent request: published as A’s with A’s ledger entry
- ✅ F02 — the result goes to the account that paid upgrading A’s request to urgent: A’s request, A’s ledger
- ✅ F02 — the result goes to the account that paid owner licence fee: completed on A’s case — no «unknown owner case»
- ✅ F02 — the result goes to the account that paid the double-tap guard is per account: B’s own payment is not blocked by A’s
- ✅ F10 — system back during the payment cannot close the dialog while paying; the plan is active when it ends
- ✅ F10 — system back during the payment before paying, back simply closes it and nothing is charged

#### `test/auth_access_test.dart`

- ✅ AUTH-02 — the account is separate from the session sign up → sign out → sign in with the same details works
- ✅ AUTH-02 — the account is separate from the session a wrong password is refused with a clear message
- ✅ AUTH-02 — the account is separate from the session the password is never stored in plain text
- ✅ AUTH-02 — the account is separate from the session the same username cannot be taken twice
- ✅ AUTH-02 — the account is separate from the session a session saved by version 1.1 becomes an account without a plain password
- ✅ AUTH-03 — phone format before the code step letters are refused, Saudi mobile formats are accepted
- ✅ ACCESS-03 — private data stays with its account tasks, subscription and quota of account A are not loaded for account B
- ✅ ACCESS-02 — ownership only the publishing account may edit a listing

#### `test/billing_quota_test.dart`

- ✅ the numbers live in one place prices and quotas
- ✅ yearly broker plan — «مميز» 15 the 15th is included, the 16th asks for payment
- ✅ yearly broker plan — «مميز» 15 re-boosting a featured listing takes nothing
- ✅ yearly broker plan — «مميز» 15 a featuring ends after its own duration, not after a year
- ✅ yearly broker plan — «عاجل» 10 the 10th is included, the 11th asks for payment
- ✅ requests — normal free, urgent 50 (R01) 5 urgent requests are included, the 6th costs 50
- ✅ requests — normal free, urgent 50 (R01) a normal request stays free after every quota is used up
- ✅ requests — normal free, urgent 50 (R01) cancel or failed payment: not urgent, nothing taken
- ✅ requests — normal free, urgent 50 (R01) a double tap pays once
- ✅ the quota belongs to the account and the yearly period restart, sign out / in and 40 days later: no refill
- ✅ the quota belongs to the account and the yearly period another account on the same device gets nothing from it
- ✅ the quota belongs to the account and the yearly period the monthly plan keeps the approved 1 featuring per month only
- ✅ the quota belongs to the account and the yearly period buying a plan goes through the simulated payment

#### `test/dictation_test.dart`

- ✅ placement — inside the description field semantic search on offers: disabled until city, district and type
- ✅ placement — inside the description field semantic search on requests (broker tab)
- ✅ placement — inside the description field matching assistant — the wish description
- ✅ placement — inside the description field offers at 360, 390 and 430 wide, text ×1 and ×1.2, keyboard open: mic and button above the keyboard
- ✅ placement — inside the description field requests at 360, 390 and 430 wide, text ×1 and ×1.2, keyboard open: mic and button above the keyboard
- ✅ placement — inside the description field matching at 360, 390 and 430 wide, text ×1 and ×1.2, keyboard open: mic and button above the keyboard
- ✅ behaviour partial results replace each other — no repeated words
- ✅ behaviour permission refused: text unchanged, short message, typing still works
- ✅ behaviour service unavailable or an error: the text is not changed
- ✅ behaviour tap again stops; typing by hand stops; disposing stops
- ✅ behaviour opening another page over the screen stops the recording
- ✅ behaviour a disabled field has a disabled mic
- ✅ the wish is saved per account (dictated text is ordinary text)

#### `test/dictation_timing_test.dart`

- ✅ F08 — a cancelled start never starts later stop while the permission is pending: the recording never starts
- ✅ F08 — a cancelled start never starts later stop while the service is starting: the late start is stopped at once
- ✅ F08 — a cancelled start never starts later leaving the page while the permission is pending or the service is starting
- ✅ F08 — a cancelled start never starts later switching to another field while the first is starting: only the second dictates
- ✅ F09 — late words after «stop» a late final result does not replace newer text typed by hand
- ✅ F09 — late words after «stop» without typing, the final words are kept — once, after the earlier text
- ✅ F09 — late words after «stop» a new session ignores the late words of the previous one

#### `test/legacy_migration_test.dart`

- ✅ a 1.1 device with one known account keeps its listings, requests, stock and edit rights
- ✅ a 1.1 device with one known account running the upgrade again changes nothing and duplicates nothing
- ✅ records whose author cannot be proven another author on the same device: shown, unchanged, owned by nobody
- ✅ records whose author cannot be proven an account made later with the same name gets nothing
- ✅ records whose author cannot be proven no 1.1 session on the device (signed out then): nothing is assigned
- ✅ records whose author cannot be proven settings show them with a recovery request that moves nothing

#### `test/licence_test.dart`

- ✅ ready project with two units: two independent numbers, saved and shown per unit
- ✅ changing one unit’s number does not touch the other (unit editor)
- ✅ off-plan: fields hidden, no hidden check, nothing inherited — and back to ready keeps the draft
- ✅ older data: a project-level number is not shown on units nor copied to them

#### `test/listing_editor_test.dart`

- ✅ add a photo, delete another, change the cover → saved, shown, reopened the same
- ✅ cancel or back without saving keeps the original photos
- ✅ a price-only edit keeps the photos; a negative price is refused with a message
- ✅ the last photo cannot be deleted; permission refused / closed picker / missing file
- ✅ ACCESS-02: a guest and another account cannot open the editor by its address
- ✅ the add form keeps the shared picker working for projects and units

#### `test/listing_state_test.dart`

- ✅ F06 — the floor is kept 1.1 «second»: shown as floor 2, kept after a price-only edit
- ✅ F06 — the floor is kept 1.1 «first»: shown as floor 1, kept after a description-only edit
- ✅ F06 — the floor is kept current values (number 7, annex, ground, none) are kept by a price-only edit
- ✅ F06 — the floor is kept changing the floor of a 1.1 listing saves the new floor
- ✅ F07 — a paused listing stays paused an edit keeps it paused; «تنشيط» in «عروضي» shows it again
- ✅ F07 — a paused listing stays paused an active listing stays active

#### `test/no_simulations_test.dart`

- ⏭️ simulations off a charge reports «unavailable» and nothing is granted — متروك في التشغيل العادي (يحتاج `--dart-define=ZON_SIMULATIONS=false`)
- ⏭️ simulations off the pay dialog says payment is not available and its pay button is off — متروك في التشغيل العادي (يحتاج `--dart-define=ZON_SIMULATIONS=false`)
- ⏭️ simulations off owner path: no simulated contract approval, fee or licence number — متروك في التشغيل العادي (يحتاج `--dart-define=ZON_SIMULATIONS=false`)

#### `test/notifications_test.dart`

- ✅ bell → list → the matching row opens the matching assistant; read state and count update
- ✅ each type opens its own destination
- ✅ a deleted target shows a short notice instead of a broken page
- ✅ empty state
- ✅ stored rows keep id, type, target, time and read state; newest first
- ✅ a broker is told about new requests in his city from other accounts — and only his account
- ✅ using the last of a quota adds a plan notification for the related service

#### `test/photo_store_test.dart`

- ✅ kept after the picker’s file is cleaned and the app restarts; the cover order is kept
- ✅ leaving the edit keeps the original photos; the unused copy goes after a day
- ✅ a photo that cannot be copied is not added (no temporary path is kept)
- ✅ photos saved by version 1.2.0 (absolute temporary paths) adopted while the file exists, once; a missing one shows «الصورة غير متاحة», never another photo
- ✅ a project’s cover and unit photo are kept; an old photo nothing refers to any more is removed

#### `test/plans_test.dart`

- ✅ P01 — broker plan short text: 2,300 yearly, save 1,060 vs 280 monthly, no defensive paragraphs
- ✅ P01 — broker plan subscribe: price shown first, cancel changes nothing, pay activates
- ✅ P01 — broker plan the pay button shows the whole amount (360 wide, text ×1.2)
- ✅ P03 — seeker plan 199 monthly, short features, free line, no «طلب جاد»
- ✅ P04 — owner plan texts; «ابدأ نشر عقارك» opens the add flow and does NOT activate the plan
- ✅ P04 — owner plan form, contract and publishing are separate states — both conditions are needed
- ✅ P04 — owner plan full path in the app: contract → approval → fee → details → publish → «الباقة مفعّلة»
- ✅ P04 — owner plan EDIT-02 in the add form: a negative price is refused; Arabic digits are read

#### `test/project_units_test.dart`

- ✅ F03 — a unit is written only after its checks «إضافة وحدة» then back: the published project is unchanged
- ✅ F03 — a unit is written only after its checks clearing a ready unit’s licence and saving it is refused; the number is kept
- ✅ F03 — a unit is written only after its checks a new unit of a ready project: refused without a licence, written once verified
- ✅ F03 — a unit is written only after its checks off-plan: no licence field and none required — the new unit is saved
- ✅ F03 — a unit is written only after its checks sold / available and delete keep the counters right; deleting a published unit asks first
- ✅ F03 — a unit is written only after its checks the owner is checked before every write
- ✅ F04 — unit prices editor: a negative or invalid price is refused and the price is kept; Arabic digits are read
- ✅ F04 — unit prices editor: an empty price is still 0, as before (a product decision)
- ✅ F04 — unit prices add form: a negative or invalid unit price stops «متابعة» and names the unit
- ✅ F07 — saving keeps the project hidden «حفظ المشروع» and «حفظ الوحدة» on a hidden project leave it hidden

#### `test/requests_test.dart`

- ✅ add a request a normal request is published free — no payment step
- ✅ add a request urgent: 50 SAR shown before paying; cancel keeps the form and publishes nothing
- ✅ add a request a yearly broker uses the quota — no 50 on top
- ✅ add a request REQ-03 Arabic digits are kept; REQ-02 «from» larger than «to» is refused
- ✅ my requests upgrade to urgent: 50 after confirmation, then it stays urgent (no second charge)
- ✅ my requests edit: Arabic digits, ranges, and making it urgent from the editor

#### `test/tasks_and_boosts_test.dart`

- ✅ tasks TASK-05: a title of spaces is refused with a message; saved titles are trimmed
- ✅ tasks the reminder is shown in full on the task card (360 wide, text ×1.2)
- ✅ tasks the reminder is shown in Arabic — never English months or "Invalid Date"
- ✅ listing boosts in «عروضي» yearly broker: «مميز» from the quota after one confirmation, shown with its usage
- ✅ listing boosts in «عروضي» no plan: «عاجل» shows 100 first; cancel → nothing; pay → urgent once

#### `test/text_fit_test.dart`

- ✅ P01 broker plan and its payment dialog
- ✅ P03 seeker plan and P04 owner plan with its steps
- ✅ P02 quota lines, the confirmation and the boost price
- ✅ R01 request prices, the urgent payment and «اجعله طلبًا عاجلًا»
- ✅ V01 dictation status and messages inside the field
- ✅ L01 licence field of each unit and E01 photo editor
- ✅ N01 notification rows and the empty state

#### `test/text_scale_test.dart`

- ✅ no overflow at 360 wide with text ×1.2 — broker
- ✅ no overflow at 360 wide with text ×1.2 — seeker
- ✅ no overflow at 360 wide with text ×1.2 — owner
- ✅ no overflow at 360 wide with text ×1.2 — developer

**TOTAL: 126 — pass 123, fail 0, skip 3**

#### `test/no_simulations_test.dart` بالأمر `--dart-define=ZON_SIMULATIONS=false`

- ✅ simulations off a charge reports «unavailable» and nothing is granted
- ✅ simulations off the pay dialog says payment is not available and its pay button is off
- ✅ simulations off owner path: no simulated contract approval, fee or licence number

**TOTAL: 3 — pass 3, fail 0, skip 0**
