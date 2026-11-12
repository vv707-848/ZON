"""Builds docs/api.md from the live route table and the ErrorCode enum.

    cd backend
    php artisan route:list --path=api/v1 --json > /tmp/routes.json
    php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php";
      $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
      echo json_encode(array_map(fn ($c) => ["code" => $c->value, "status" => $c->status(), "message" => $c->message()],
      App\\Enums\\ErrorCode::cases()), JSON_UNESCAPED_UNICODE);' > /tmp/errors.json
    python3 -I ../_tools/api_doc.py /tmp/routes.json /tmp/errors.json ../docs/api.md

A route without a line in DESCRIPTIONS stops the build: a new endpoint is documented on purpose.
"""

import json
import re
import sys

ROLES = {"seeker": "باحث", "broker": "وسيط", "owner": "مالك", "developer": "مطوّر"}

SECTIONS = [
    ("app", "١. التطبيق والمرجع"),
    ("auth", "٢. التسجيل والدخول"),
    ("me", "٣. حسابي ورغباتي وإشعاراتي"),
    ("browse", "٤. التصفح العام"),
    ("listings", "٥. العروض والصور"),
    ("projects", "٦. المشاريع والوحدات (المطوّر)"),
    ("owner", "٧. مسار المالك"),
    ("requests", "٨. طلبات البحث"),
    ("social", "٩. التواصل والمجتمع"),
    ("desk", "١٠. المكتب: العملاء والملاك والمخزون والمهام"),
    ("billing", "١١. الباقات والدفع والفواتير"),
    ("ai", "١٢. الذكاء"),
]

# "METHOD uri" (uri without api/v1/) → (section, description)
DESCRIPTIONS = {
    # ── app ──
    "GET app/config": ("app", "قيم الإقلاع: إصدار المنصة (`X-App-Platform`) والصيانة، رابط المشاركة، رقم نسخة المرجع، حدود الصور والشات"),
    "GET catalog": (
        "app",
        "المرجع: المدن وأحياؤها، أنواع العقار وحقول كل نوع، وقوائم الاختيار (الغرض، الأدوار، الإشغال، فئات الملاحظات، أسباب البلاغ، تفضيلات الإشعار)",
    ),
    "GET legal": ("app", "صفحات السياسات المنشورة بلا نصوصها: الخمس أولاً، ثم ما أضافته الإدارة"),
    "GET legal/{slug}": ("app", "صفحة سياسة بنصها ورقم نسختها (terms · privacy · …) كما تحرَّر من اللوحة"),
    "GET packages": ("billing", "الباقات الفعّالة بأسعارها وأرصدتها وميزاتها وهل تُشترى، وأسعار الخدمات المفردة (تمييز، عاجل…)"),
    "POST support-tickets": ("app", "شكوى · اقتراح · استفسار (للزائر والمسجّل)، وعرض تخصه إن وُجد؛ تُرجع رقم القيد ومدة الرد المعلنة"),
    "GET|POST broadcasting/auth": ("social", "توثيق القنوات الخاصة لـ Reverb: `customers.{id}` و`conversations.{id}`"),
    "POST payments/webhook/{provider}": ("billing", "إشعار مزوّد الدفع؛ لا يُصدَّق محتواه — يُقرأ الدفع من المزوّد ثم يُطبَّق (ConfirmPayment)"),
    "POST store/notices/{store}": ("billing", "إشعار المتجر (آبل V2 · Google RTDN) بمفتاح في الرابط؛ لا يُصدَّق محتواه — يُقرأ الشراء من المتجر: تجديد أو استرداد"),
    # ── auth ──
    "POST auth/register": ("auth", "بدء التسجيل: يحفظ النموذج مؤقتاً 15 دقيقة ويرسل رمز الجوال (202)"),
    "POST auth/otp/verify": ("auth", "تأكيد رمز التسجيل: ينشئ الحساب ويُرجع الرمز المميِّز وبيانات حسابي (201)"),
    "POST auth/otp/resend": ("auth", "إعادة إرسال الرمز (3 كل 10 دقائق للجوال)"),
    "POST auth/login": ("auth", "الدخول بـ `login` = اسم المستخدم أو الجوال + كلمة السر؛ يلغي طلب حذف معلّقاً"),
    "POST auth/logout": ("auth", "إلغاء رمز هذا الجهاز"),
    "POST auth/password/forgot": ("auth", "إرسال رمز استعادة كلمة السر إلى الجوال"),
    "POST auth/password/reset": ("auth", "تعيين كلمة سر جديدة بالرمز؛ يُخرج كل الأجهزة"),
    # ── me ──
    "GET me": ("me", "حسابي: الدور، الحالة، فال، نفاذ، الباقة وحدودها، تفضيلات الإشعار"),
    "PATCH me": ("me", "تعديل الاسم والمدينة"),
    "POST me/phone": ("me", "طلب تغيير الجوال: رمز إلى الرقم الجديد"),
    "POST me/phone/verify": ("me", "تأكيد الجوال الجديد بالرمز"),
    "PUT me/password": ("me", "تغيير كلمة السر (`current_password` مطلوبة)؛ تخرج الأجهزة الأخرى"),
    "PUT me/notification-preferences": ("me", "مفاتيح الإشعارات: الست مفعّلة افتراضياً (رسائل · نشر المتابَعين · طلبات المنطقة · حالة الإعلان · تقييمات · مطابقة)، و`marketing` (العروض والتسويق) مطفأ حتى يوافق المستخدم"),
    "POST me/devices": ("me", "تسجيل معرّف Push للجهاز (`push_token`: معرّف التثبيت FID من FCM) ومنصته — مربوط بجلسة هذا الجهاز، وعند كل فتح للتطبيق"),
    "GET me/sessions": ("me", "أجهزتي: جلسة لكل جهاز (الاسم، المنصة، آخر استخدام، وهذا الجهاز)"),
    "DELETE me/sessions/{session}": ("me", "إنهاء جلسة جهاز: يخرج ويتوقف عنه الـ Push"),
    "POST me/nafath": ("me", "بدء التحقق عبر نفاذ: الرقم الذي يختاره المستخدم في تطبيق نفاذ ومهلته؛ طلب واحد في الوقت نفسه"),
    "GET me/nafath": ("me", "حالة التحقق، و`attempt`: waiting · verified · rejected · expired (يبدأ من جديد)"),
    "POST account/delete": ("me", "طلب حذف الحساب: يُخفى المحتوى فوراً، والحذف النهائي بعد المهلة"),
    "POST account/legacy-claims": ("me", "طلب استعادة بيانات الإصدار السابق؛ تراجعه اللوحة يدوياً"),
    "GET preferences": ("me", "رغباتي (الباحث)"),
    "POST preferences": ("me", "إضافة رغبة: المدينة، الأحياء، الأنواع، الميزانية، الملاحظات، ومشاركتها في التقارير"),
    "PUT|PATCH preferences/{preference}": ("me", "تعديل رغبة؛ إعادة تفعيلها تُحسب من حد الباقة"),
    "DELETE preferences/{preference}": ("me", "حذف رغبة"),
    "GET notifications": ("me", "الإشعارات (صفحات)، و`meta.unread`"),
    "POST notifications/read": ("me", "تعليم إشعارات أو كلها كمقروءة"),
    "GET support-tickets": ("me", "مراسلاتي مع «فريق زون»: آخر رسالة وما لم أقرأه"),
    "GET support-tickets/{ticket}": ("me", "المراسلة كاملة (الفريق باسم «فريق زون» دون أسماء الموظفين)، وتُعلَّم مقروءة"),
    "POST support-tickets/{ticket}/messages": ("me", "ردّي في المراسلة؛ المغلقة تُفتح من جديد"),
    "GET me/followers": ("social", "من يتابعني"),
    "GET me/following": ("social", "من أتابع"),
    # ── browse ──
    "GET feed": ("browse", "الرئيسية: العروض والمشاريع في قائمة واحدة بفلاترها وترتيبها (الأحدث · الأقدم · المميز · السعر · المساحة)، ولكل صف `kind`"),
    "GET listings": ("browse", "العروض المنشورة مع التصفية (المدينة، الحي، النوع، الغرض، السعر…) والفرز؛ المميز أولاً"),
    "GET listings/{listing}": ("browse", "تفاصيل عرض منشور (أو عرضي بأي حالة)؛ أرقام التواصل للمسجّلين فقط، ولا شيء بين متحاظرَين"),
    "GET projects": ("browse", "المشاريع المنشورة مع التصفية"),
    "GET projects/{project}": ("browse", "تفاصيل مشروع ووحداته؛ نموذج المشروع «الجاهز» لا يظهر للعموم إلا بترخيص ساري"),
    "GET projects/{project}/units/{unit}": ("browse", "تفاصيل نموذج وحدة"),
    "GET requests": ("browse", "طلبات البحث المنشورة: تصفية بالدور والغرض والمدينة والحي والنوع، والفرز `new` · `old` (العاجل أولاً) · `urgent` (العاجل وحده) · `cheap` (أقل ميزانية، وبلا ميزانية آخراً)؛ التواصل حسب الحق (`access`)"),
    "GET requests/{searchRequest}": ("browse", "تفاصيل طلب بحث"),
    "GET customers/{customer}": ("browse", "الملف العام باسم المستخدم: المدينة، التقييم وتقييمي له، أعداد المتابِعين والعروض، وهل أتابعه"),
    "GET customers/{customer}/listings": ("browse", "عروض المعلن المنشورة"),
    # ── listings ──
    "GET listings/mine": ("listings", "عروضي بكل حالاتها"),
    "POST listings": ("listings", "إضافة عرض: البوابات (فال/نفاذ/الحد/الترخيص أو ملف المالك) ← الصياغة في طابور `ai` ← `pending_review` أو النشر"),
    "PATCH listings/{listing}": ("listings", "تعديل عرضي؛ تغيير النص يعيده إلى مراجعة الصياغة"),
    "DELETE listings/{listing}": ("listings", "حذف عرضي (عدا المباع) وإخراجه من الفهرس"),
    "GET listings/{listing}/review": ("listings", "الصياغة المقترحة مقابل النص الأصلي"),
    "POST listings/{listing}/review": ("listings", "قرار المعلن: `approve` المقترح · `edit` بنصه · `reject` فيبقى نصه الأصلي ← النشر"),
    "POST listings/{listing}/pause": ("listings", "إيقاف مؤقت (من «منشور» فقط)"),
    "POST listings/{listing}/resume": ("listings", "استئناف ما أوقفه المعلن أو صار «غير نشط». المخفي والمرفوض يعيدهما الإشراف وحده، ومنتهي الرخصة يعيده التجديد"),
    "POST listings/{listing}/licence": ("listings", "تجديد الرخصة: رقمها (أو رقم جديد) يُفحص لدى الهيئة بتاريخ انتهائها، ويعود العرض «منتهي الترخيص» إلى النشر"),
    "POST listings/{listing}/sold": ("listings", "تم البيع (نهائي)؛ عرض المالك يُنشئ سعي زون المستحق من سعر البيع"),
    "POST listings/{listing}/boost": ("listings", "تمييز أو عاجل: من رصيد الباقة، وإلا عملية دفع"),
    "POST media": ("listings", "رفع صورة `photo` (jpg · png · webp حتى 8MB) إلى MinIO؛ تُربط بالعرض أو المشروع بمعرّفها"),
    "DELETE media/{media}": ("listings", "حذف صورة لي"),
    # ── projects ──
    "GET projects/mine": ("projects", "مشاريعي بكل حالاتها"),
    "POST projects": ("projects", "إضافة مشروع (المطوّر المفعّل بعقد تسويق)"),
    "PATCH projects/{project}": ("projects", "تعديل مشروعي"),
    "GET projects/{project}/review": ("projects", "الصياغة المقترحة لوصف المشروع"),
    "POST projects/{project}/review": ("projects", "قرار المطوّر على وصف المشروع"),
    "POST projects/{project}/publish": ("projects", "النشر الأول: وصف مقرَّر، وحدة واحدة على الأقل، وترخيص لكل نموذج في «الجاهز»"),
    "POST projects/{project}/pause": ("projects", "إيقاف مؤقت (من «منشور» فقط)"),
    "POST projects/{project}/resume": ("projects", "استئناف ما أوقفه المطوّر، بعد شروط النشر كاملة من جديد"),
    "POST projects/{project}/units": ("projects", "إضافة نموذج وحدة"),
    "POST projects/{project}/units/review": ("projects", "قرار المطوّر على أوصاف الوحدات دفعة واحدة"),
    "PATCH projects/{project}/units/{unit}": ("projects", "تعديل نموذج وحدة"),
    "DELETE projects/{project}/units/{unit}": ("projects", "حذف نموذج وحدة"),
    "POST projects/{project}/units/{unit}/licence": ("projects", "التحقق من ترخيص الوحدة لدى الهيئة (REGA)، وتجديده: الأحدث هو الرخصة"),
    "POST projects/{project}/units/{unit}/sold": ("projects", "بيع عدد من وحدات النموذج؛ عمولة العقد لكل وحدة"),
    # ── owner ──
    "GET owner-cases": ("owner", "ملفاتي كمالك"),
    "POST owner-cases": ("owner", "فتح ملف: الصك ← عقد الوساطة مع زون وموافقة نفاذ ← رسوم الترخيص ← الترخيص"),
    "GET owner-cases/{ownerCase}": ("owner", "حالة الملف وخطوته التالية"),
    # ── requests ──
    "GET requests/mine": ("requests", "طلباتي"),
    "GET requests/area": ("requests", "طلبات مدينة الوسيط من غيره، العاجل أولاً، مع حقه في التواصل (`meta.access`)"),
    "POST requests": ("requests", "إضافة طلب بحث؛ `urgent` من الرصيد أو بالدفع (النتيجة في `meta.urgent`)"),
    "PUT requests/{searchRequest}": ("requests", "تعديل طلبي بنموذجه كاملاً (الحقل الفارغ يُفرَّغ، و`urgent` لا يُعدَّل هنا)؛ المغلق لا يُعدَّل (409)"),
    "POST requests/{searchRequest}/urgent": ("requests", "اجعله طلبًا عاجلًا: من رصيد الباقة، وإلا 402 بالسعر"),
    "POST requests/{searchRequest}/close": ("requests", "إغلاق طلبي"),
    "POST requests/{searchRequest}/to-client": ("requests", "تحويل طلب إلى عميل في مكتبي"),
    # ── social ──
    "GET conversations": ("social", "محادثاتي مع آخر رسالة وغير المقروء، الأحدث أولاً (30 في الصفحة)"),
    "POST conversations": ("social", "بدء محادثة مع `to` (اسم المستخدم) حول عرض أو مشروع أو طلب منشور لأحد الطرفين، بأول رسالة (تُرفض بين متحاظرَين)"),
    "GET conversations/{conversation}/messages": ("social", "رسائل محادثة أنا طرف فيها (صفحات)"),
    "POST conversations/{conversation}/messages": ("social", "إرسال رسالة؛ تُبث على `conversations.{id}` وتُشعِر الطرف الآخر"),
    "POST conversations/{conversation}/read": ("social", "تعليم المحادثة مقروءة"),
    "GET favorites": ("social", "مفضلتي"),
    "POST favorites": ("social", "إضافة إلى المفضلة (`listing` · `project`)"),
    "DELETE favorites/{type}/{id}": ("social", "إزالة من المفضلة"),
    "POST reports": ("social", "بلاغ عمّا يراه المبلِّغ: عرض أو مشروع أو طلب منشور، حساب، تقييم، أو رسالة في محادثة هو طرفها؛ ثلاثة مبلِّغين مختلفين يخفون آلياً"),
    "POST customers/{customer}/follow": ("social", "متابعة معلن"),
    "DELETE customers/{customer}/follow": ("social", "إلغاء المتابعة"),
    "POST customers/{customer}/rating": ("social", "تقييم بالنجوم (مرة لكل معلن، قابلة للتعديل)"),
    "POST customers/{customer}/block": ("social", "حظر: يلغي المتابعة بالاتجاهين، ويخفي المحتوى ويمنع الرسائل بين الطرفين"),
    "DELETE customers/{customer}/block": ("social", "إلغاء الحظر"),
    # ── desk ──
    "GET clients": ("desk", "عملائي"),
    "POST clients": ("desk", "إضافة عميل"),
    "GET clients/{client}": ("desk", "تفاصيل عميل"),
    "PUT|PATCH clients/{client}": ("desk", "تعديل عميل"),
    "DELETE clients/{client}": ("desk", "حذف عميل"),
    "GET clients/{client}/notes": ("desk", "ملاحظات العميل"),
    "POST clients/{client}/notes": ("desk", "إضافة ملاحظة (فئة + نص)"),
    "DELETE clients/{client}/notes/{note}": ("desk", "حذف ملاحظة"),
    "GET property-owners": ("desk", "ملاك العقارات لدي"),
    "POST property-owners": ("desk", "إضافة مالك"),
    "GET property-owners/{owner}": ("desk", "تفاصيل مالك"),
    "PUT|PATCH property-owners/{owner}": ("desk", "تعديل مالك"),
    "DELETE property-owners/{owner}": ("desk", "حذف مالك"),
    "GET property-owners/{owner}/notes": ("desk", "ملاحظات المالك"),
    "POST property-owners/{owner}/notes": ("desk", "إضافة ملاحظة"),
    "DELETE property-owners/{owner}/notes/{note}": ("desk", "حذف ملاحظة"),
    "GET inventory": ("desk", "مخزوني (خاص؛ لا يُفهرس ولا يظهر لأحد)"),
    "POST inventory": ("desk", "إضافة عقار إلى المخزون"),
    "GET inventory/{item}": ("desk", "تفاصيل عقار في المخزون"),
    "PUT|PATCH inventory/{item}": ("desk", "تعديله"),
    "DELETE inventory/{item}": ("desk", "حذفه"),
    "POST inventory/{item}/publish": ("desk", "نشره كإعلان عبر مسار `POST /listings` نفسه وبوّاباته"),
    "GET tasks": ("desk", "مهامي"),
    "POST tasks": ("desk", "إضافة مهمة (أولوية، موعد تذكير)"),
    "PUT|PATCH tasks/{task}": ("desk", "تعديل مهمة"),
    "DELETE tasks/{task}": ("desk", "حذف مهمة"),
    "POST tasks/{task}/done": ("desk", "إنجاز مهمة"),
    # ── billing ──
    "GET subscription": ("billing", "باقتي ومدتها وأرصدتها وحدودها"),
    "POST checkout": (
        "billing",
        "بدء عملية دفع `purpose`: subscription · featured · urgent_listing · urgent_request · ad_licence. الخادم يسعّر ويعيد استخدام عملية معلّقة خلال 30 دقيقة",
    ),
    "GET payments/{payment}": ("billing", "حالة عملية دفع لي (المعرّف العام `public_id`)"),
    "POST payments/{payment}/confirm": ("billing", "تأكيد بعد الدفع في التطبيق: قراءة المزوّد ثم التطبيق مرة واحدة والفاتورة"),
    "GET store/products": ("billing", "منتجات المتجر لدوري، ورمز حسابي الذي يُرفق بكل شراء"),
    "POST store/check": ("billing", "قبل نافذة المتجر: هل يُشترى هذا الآن، لهذا العرض أو الطلب؟ (ردود الدفع نفسها)"),
    "POST store/purchases": ("billing", "بعد نافذة المتجر: معرّف عملية آبل أو رمز شراء Google؛ يُطبَّق مرة واحدة بكلمة المتجر"),
    "GET invoices": ("billing", "فواتيري"),
    "GET invoices/{invoice}": ("billing", "فاتورة (رقم `ZON-YYYY-NNNNNN` المتسلسل)"),
    # ── ai ──
    "GET ai/quota": ("ai", "رصيدي اليومي لكل خدمة ذكاء ووقت التجديد"),
    "POST ai/search": ("ai", "البحث الدلالي في العروض: الخانات الإجبارية + نص حر؛ مرشّحو SQL ← ترتيب الوكيل ← كل نتيجة العرض ودرجته"),
    "POST ai/search/requests": ("ai", "بحث الوسيط في طلبات الباحثين (ميزة باقة: 402 بدونها)"),
    "POST ai/matching": ("ai", "مساعد المطابقة: من رغبتي (أو حاجة مرسلة) ← عروض مطابقة، عروض أوسع، ومشاريع"),
    "POST ai/matching/projects/{project}/searchers": ("ai", "باحثون تناسبهم وحدات مشروعي المنشور، مع الوحدة الأنسب"),
    "GET ai/matching/projects/{project}/brokers": ("ai", "وسطاء مدينة المشروع بعدد عملائهم المطابقين — دون كشف العملاء"),
    "POST ai/chat": ("ai", "شات زون: سؤال حسب النية من مكتبة المعرفة، مع حارس يستبدل الإجابة غير الآمنة"),
    "GET ai/chat": ("ai", "سجل محادثتي مع زون"),
    "POST ai/summaries": ("ai", "ملخص ملاحظات عميل أو مالك أو مهامي (نطاق مالكها فقط)، مخزَّن ويُعاد ما لم تتغير الملاحظات"),
    "POST ai/feedback": ("ai", "«مطابق/غير مطابق» على نتيجة؛ السبب إلزامي لغير المطابق ويُخزَّن مقنَّعاً"),
}

THROTTLES = {
    "otp": "otp: 3/10د للجوال · 10/س للعنوان",
    "login": "login: 5/د",
    "messages": "messages: 30/د",
    "reports": "reports: 20/س",
    "uploads": "uploads: 40/د",
    "wording": "wording: 60/س",
    "licence": "licence: 20/س",
}


def access(middleware: list[str]) -> str:
    parts = []
    authed = any(m.endswith("Authenticate:api") for m in middleware)
    parts.append("🔒" if authed else "عام")
    for m in middleware:
        if "RequireRole:" in m:
            parts.append(" · ".join(ROLES[r] for r in m.split(":", 1)[1].split(",")))
        elif m.endswith("RequirePreference"):
            parts.append("رغبة")
        elif "EnforceAiQuota:" in m:
            parts.append("حصة " + m.split(":", 1)[1])
        elif "ThrottleRequests:" in m:
            name = m.split(":", 1)[1]
            if name != "api":
                parts.append(THROTTLES.get(name, name))
    return " · ".join(parts)


def main(routes_path: str, errors_path: str, out_path: str) -> None:
    routes = json.load(open(routes_path, encoding="utf-8"))
    errors = json.load(open(errors_path, encoding="utf-8"))
    rows: dict[str, list[str]] = {key: [] for key, _ in SECTIONS}
    missing = []
    seen = set()
    for route in routes:
        method = route["method"].replace("|HEAD", "")
        # Laravel 13 prints a binding field in the URI ({customer:username}); the key keeps the parameter only.
        uri = re.sub(r"\{(\w+):\w+\}", r"{\1}", route["uri"].removeprefix("api/v1/"))
        key = f"{method} {uri}"
        seen.add(key)
        if key not in DESCRIPTIONS:
            missing.append(key)
            continue
        section, text = DESCRIPTIONS[key]
        action = (route["action"] or "").split("\\")[-1]
        path = "/" + uri.replace("{customer}", "{username}")
        rows[section].append(f"| `{method}` | `{path}` | {access(route['middleware'])} | {text} | `{action}` |")
    stale = sorted(set(DESCRIPTIONS) - seen)
    if missing or stale:
        sys.exit(f"undocumented routes: {missing}\nno longer routes: {stale}")

    out = [HEADER.strip(), ""]
    out.append("## رموز الأخطاء")
    out.append("")
    out.append("| الحالة | `error.code` | الرسالة الافتراضية |")
    out.append("|---|---|---|")
    out += [f"| {e['status']} | `{e['code']}` | {e['message']} |" for e in errors]
    out.append("")
    out.append(
        "**في التطبيق:** `UNAUTHENTICATED` يعيد إلى صفحة الدخول فقط إن جاء ردّاً على طلب أُرسل بجلسة الهاتف الحالية نفسها. "
        "ورد متأخر لجلسة انتهت (خروج، أو دخول بحساب آخر) لا يكتب حساباً ولا مفاتيح إشعارات، ولا يُخرج الجلسة التالية "
        "(`ApiClient` و`ServerSession.owns`)."
    )
    out.append("")
    out.append(f"## نقاط الواجهة ({len(routes)})")
    out.append("")
    out.append(
        "الصلاحية: `عام` بلا دخول · 🔒 رمز مميِّز · الدور · `رغبة` = الباحث يحتاج رغبة فعّالة (428) · `حصة` = رصيد الذكاء اليومي. "
        "العمود الأخير موضع الكود في `app/Http/Controllers/Api/V1`."
    )
    for key, title in SECTIONS:
        if not rows[key]:
            continue
        out += ["", f"### {title}", "", "| الطريقة | المسار | الصلاحية | ما يفعله | الكود |", "|---|---|---|---|---|"] + rows[key]
    out.append("")
    open(out_path, "w", encoding="utf-8").write("\n".join(out))


HEADER = """
# مرجع واجهة التطبيق — `/api/v1`

> مولَّد من جدول المسارات الفعلي (`_tools/api_doc.py`). أي مسار جديد بلا وصف يوقف التوليد.

## الاتفاقات

- **القاعدة:** `https://api.zon.sa/api/v1` · JSON فقط · الحقول snake_case.
- **الدخول:** `Authorization: Bearer <token>` من `auth/otp/verify` أو `auth/login` (Sanctum، صلاحية 90 يوماً).
- **ترويسات يرسلها التطبيق:**
  - `X-App-Platform` (ios · android) و`X-App-Version`: للتحديث الإجباري (426) ووضع الصيانة (503).
  - `X-Device-Id`: طبقة الجهاز في حصة الذكاء.
  - `X-Request-Id` (اختياري): وإلا يولّده الخادم. يعود في كل رد.
  - `Idempotency-Key` (اختياري، مع POST وبعد الدخول): مفتاح ثابت للعملية الواحدة (8–100 من `A-Z a-z 0-9 _ -`)، يُعاد هو نفسه في كل محاولة. تُنفَّذ مرة واحدة مهما تكررت أو تزامنت:
    - التكرار بعد انتهائها يأخذ ردها نفسه (وفيه `Idempotent-Replayed: true`) ولا يغيّر شيئاً.
    - التكرار وهي ما زالت تعمل: 409 `IDEMPOTENCY_IN_PROGRESS`، فيعيد التطبيق المحاولة بالمفتاح نفسه.
    - المفتاح نفسه ببيانات أخرى: 422 `IDEMPOTENCY_MISMATCH`.
    - الرد الذي لا يحسم العملية (5xx · 401 · 408 · 409 · 425 · 429) لا يُحفظ، فتُنفَّذ المحاولة التالية. والمفتاح للحساب نفسه، ويُحفظ يوماً.
- **النجاح:** `{ "success": true, "data": …, "meta": { … } }`. الصفحات في `meta.page = {current, per_page, total, last}`.
- **الخطأ:** `{ "success": false, "error": { "code", "message", "details", "trace_id" } }`.
  - أخطاء الحقول في `details.fields`.
  - شراء لم يؤكده المتجر: `PURCHASE_NOT_VERIFIED` و`details.reason` (`unknown` · `other_account` · `pending` · `refunded` · `test_purchase` · `expired` · `product_mismatch`).
  - `trace_id` هو رقم التتبع نفسه في السجلات واللوحة («تتبّع طلب»).
- **الأرقام:** الحقول الرقمية تقبل الأرقام العربية والفواصل (`٨٨٠,٠٠٠`)، والقيمة غير الصالحة تُرفض ولا تصير صفراً.
- **الجوال:** يُقبل بأي صيغة سعودية ويُعاد `05XXXXXXXX`.
- **حصة الذكاء:** ردود خدمات الذكاء تحمل `X-AI-Limit` و`X-AI-Remaining` و`X-AI-Resets-At`. النفاد: 429 `QUOTA_EXCEEDED`، والخدمة خارج الباقة: 402 `PAYMENT_REQUIRED`، ومع كليهما `details.upgrade`.
- **حالة الذكاء في الرد:** `meta.ai = {status}`.
  - `status`: `ok` · `cached` (نفس السؤال خلال 15 دقيقة، بلا خصم) · `degraded` · `fallback` · `timeout` · `rejected` · `skipped` (لا مرشّحين، فلا نداء).
  - الخصم من الرصيد مع `ok` وحده.
  - مع `degraded` يُستخدم ترتيب البحث الهجين في خدمة الوكلاء دون النموذج، ومع `fallback` و`timeout` و`rejected` يعود ترتيب SQL.
  - نتيجة الترتيب السجلُّ ودرجته فقط، بلا شرح.
  - المحاولات نفسها محدودة: 3 أضعاف الحد اليومي لكل حساب وخدمة، مجاباً أو لا. بعدها 429 `TOO_MANY_REQUESTS` حتى التجديد.
- **الحد العام:** 120 طلباً في الدقيقة لكل حساب أو عنوان، وحدود أخرى مذكورة في عمود الصلاحية.
- **الزمن الحي:** Reverb على `wss://api.zon.sa/app/…`، والتوثيق عبر `POST /api/v1/broadcasting/auth`.
"""

if __name__ == "__main__":
    main(*sys.argv[1:4])
