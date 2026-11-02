#!/usr/bin/env python3
"""Screenshot plan for zon v3 (brief codes V01 … Q01).  usage: make_plan.py out.json [only-prefix]

The "gallery" photo returned by the harness's fake picker is a file on disk:
set ZON_SHOTS_GALLERY to any JPEG (default /tmp/zon-shots/IMG_2041.jpg).
"""
import json, os, sys

GALLERY = os.environ.get('ZON_SHOTS_GALLERY', '/tmp/zon-shots/IMG_2041.jpg')
S = []


def sc(name, route, setup=None, ops=None, size=None):
    d = {'name': name, 'route': route, 'setup': setup or [], 'ops': ops or [['shot', '']]}
    if size:
        d['size'] = size
    S.append(d)


def acct(role, name, username=None, minutes=0, city='الرياض'):
    return ['account', {'role': role, 'name': name, 'username': username or 'demo.' + role, 'minutesAgo': minutes, 'city': city}]


BROKER = acct('broker', 'فهد العتيبي')
SEEKER = acct('seeker', 'سارة القحطاني')
OWNER = acct('owner', 'خالد الدوسري')
DEV = acct('developer', 'شركة الدار للتطوير')


def choose3():
    return [['tapText', 'اختر'], ['tapText', 'الرياض'], ['tapText', 'اختر'], ['tapText', 'الملقا'], ['tapText', 'اختر'], ['tapText', 'فيلا']]


# ---------------------------------------------------------------- V01
OFFERS_HINT = 'مثال: فيلا بحديقة قريبة من المدارس شمال الرياض…'
REQ_HINT = 'مثال: عميل يبحث عن فيلا شمال الرياض…'
WISH_HINT = 'مثال: فيلا عوائل قرب مدارس حتى ثلاثة ملايين'

sc('v01-offers', '/account/search', [BROKER, ['plan', 'broker-year']], [
    ['scrollToText', 'الوصف', 0.35], ['shot', '1-locked'],
    *choose3(), ['scrollToText', 'الوصف', 0.35], ['shot', '2-ready'],
    ['fillHint', OFFERS_HINT, 'فيلا بحديقة'],
    ['tapKey', 'dictation-mic'], ['dictate', 'قريبة من المدارس', False], ['shot', '3-listening'],
    ['dictate', 'قريبة من المدارس والمسجد', True], ['shot', '4-dictated'],
])
sc('v01-requests', '/account/search', [BROKER, ['plan', 'broker-year']], [
    ['tapText', 'بحث طلبات'], *choose3(), ['scrollToText', 'الوصف', 0.35], ['shot', '1-ready'],
    ['fillHint', REQ_HINT, 'عميل يبحث عن فيلا'],
    ['tapKey', 'dictation-mic'], ['dictate', 'بمسبح وحديقة في الملقا', True], ['shot', '2-dictated'],
])
sc('v01-requests-denied', '/account/search', [BROKER, ['plan', 'broker-year'], ['dictation', 'denied']], [
    ['tapText', 'بحث طلبات'], *choose3(), ['fillHint', REQ_HINT, 'عميل يبحث عن فيلا بمسبح'],
    ['tapKey', 'dictation-mic'], ['scrollToText', 'الوصف', 0.35], ['shot', 'permission'],
])
sc('v01-matching', '/account/matching', [SEEKER, ['plan', 'seeker']], [
    ['scrollToText', 'وصف الرغبة', 0.3], ['shot', '1-empty'],
    ['fillHint', WISH_HINT, 'فيلا عوائل'],
    ['tapKey', 'dictation-mic'], ['dictate', 'قرب المدارس حتى ثلاثة', False], ['shot', '2-listening'],
    ['dictate', 'قرب المدارس حتى ثلاثة ملايين', True], ['shot', '3-dictated'],
    ['tapText', 'حفظ الرغبة'], ['wait', 2500], ['scrollTo', 0], ['shot', '4-saved', 600],
])

# ---------------------------------------------------------------- P01 / P03 / P04
sc('p01-broker-plan', '/account/plans', [BROKER], [
    ['shot', '1-top'], ['scrollToText', 'بدون اشتراك', 0.15], ['shot', '2-compare'],
    ['scrollTo', 0], ['tapText', 'شهري'], ['shot', '3-monthly'], ['tapText', 'سنوي'],
    ['tapText', 'اشترك في باقة الوسيط'], ['shot', '4-pay'],
])
sc('p01-broker-plan-full', '/account/plans', [BROKER], [['shot', '']], size=[390, 1900, 1.5])
sc('p01-broker-plan-active', '/account/plans', [BROKER, ['plan', 'broker-year']], [['shot', '1-top'], ['scrollTo', -1], ['shot', '2-bottom']])
sc('p03-seeker-plan', '/account/plans', [SEEKER], [['shot', '1-top'], ['scrollTo', -1], ['shot', '2-bottom']])
sc('p03-seeker-plan-full', '/account/plans', [SEEKER], [['shot', '']], size=[390, 1500, 1.5])
sc('p04-owner-plan', '/account/plans', [OWNER], [
    ['shot', '1-before'], ['scrollTo', -1], ['shot', '2-steps'],
    ['tapText', 'ابدأ نشر عقارك'], ['shot', '3-add-flow'],
])
sc('p04-owner-plan-contract', '/account/plans', [OWNER, ['ownerCase', {'stage': 'approved'}]], [['scrollTo', -1], ['shot', '']])
sc('p04-owner-plan-active', '/account/plans', [
    OWNER,
    ['listing', {'id': 'demo-owner-l1', 'title': 'فيلا للبيع في النرجس', 'hood': 'النرجس', 'source': 'owner', 'images': ['/listings/villa-2.jpg']}],
    ['ownerCase', {'stage': 'published', 'listingId': 'demo-owner-l1'}],
], [['shot', '1-active'], ['scrollTo', -1], ['shot', '2-steps']])

# ---------------------------------------------------------------- N01
N_SETUP = [
    acct('broker', 'فهد العتيبي', minutes=3000),
    ['plan', 'broker-month', 3000],
    ['listing', {'id': 'demo-l1', 'title': 'فيلا مودرن بحي الملقا', 'images': ['/listings/villa-1.jpg']}],
    ['listing', {'id': 'demo-l2', 'title': 'شقة عوائل في العليا', 'type': 'apartment', 'hood': 'العليا', 'price': 980000, 'images': ['/listings/apt-1.jpg'], 'featuredEndedMinutesAgo': 600}],
    ['quota', {'kind': 'featured', 'id': 'demo-l1', 'minutesAgo': 1500}],
    ['signout'],
    acct('seeker', 'نورة الشهري', username='demo.seeker2', minutes=200),
    ['request', {'id': 'r-area-1', 'hoods': ['الملقا'], 'types': ['villa'], 'minutesAgo': 90, 'description': 'فيلا عوائل قريبة من المدارس'}],
    ['request', {'id': 'r-area-2', 'purpose': 'rent', 'hoods': ['حطين'], 'types': ['apartment'], 'min': 60000, 'max': 90000, 'minutesAgo': 40, 'description': 'شقة إيجار سنوي'}],
    ['signout'], ['signin', 'demo.broker'],
    ['sync', 35],
    ['readNote', 'انتهى رصيد'],
    ['note', {'kind': 'message', 'title': 'رسالة جديدة من سارة القحطاني', 'body': 'أبي شقة عوائل بالعليا حتى مليون', 'target': '/chats/sara', 'minutesAgo': 4, 'demo': True}],
]
sc('n01-broker', '/', N_SETUP, [
    ['shot', '1-bell'], ['tapKey', 'home-bell'], ['shot', '2-list'],
    ['tapText', 'طلب جديد في منطقتك'], ['shot', '3-request'], ['back'], ['shot', '4-read'],
    ['tapText', 'رسالة جديدة من سارة القحطاني'], ['shot', '5-chat'],
])
sc('n01-seeker-match', '/', [
    SEEKER, ['plan', 'seeker'],
    ['wish', {'city': 'الرياض', 'hoods': ['الملقا'], 'types': ['villa'], 'text': 'فيلا عوائل قريبة من المدارس'}],
], [['shot', '1-bell'], ['tapKey', 'home-bell'], ['shot', '2-list'], ['tapText', 'عروض مطابقة لرغبتك'], ['shot', '3-matching', 800]])
sc('n01-empty', '/notifications', [OWNER], [['shot', '']])

# ---------------------------------------------------------------- P02
Q_LISTINGS = [
    ['listing', {'id': 'demo-l1', 'title': 'فيلا مودرن بحي الملقا', 'images': ['/listings/villa-1.jpg']}],
    ['listing', {'id': 'demo-l2', 'title': 'شقة عوائل في العليا', 'type': 'apartment', 'hood': 'العليا', 'price': 980000, 'images': ['/listings/apt-1.jpg']}],
    ['listing', {'id': 'demo-l3', 'title': 'دور أرضي في حطين', 'type': 'floor', 'hood': 'حطين', 'price': 1450000, 'images': ['/listings/floor-1.jpg']}],
]
sc('p02-yearly-quota', '/account/listings', [BROKER, ['plan', 'broker-year'], *Q_LISTINGS, ['quota', {'kind': 'featured', 'id': 'demo-l1'}]], [
    ['shot', '1-strip'], ['tapText', 'تمييز'], ['shot', '2-confirm'], ['tapText', 'استخدم الرصيد'], ['shot', '3-used'],
])
sc('p02-request-quota', '/add?type=request', [BROKER, ['plan', 'broker-year']], [['shot', '']])
sc('p02-no-plan-price', '/account/listings', [BROKER, *Q_LISTINGS[:1]], [['shot', '1-list'], ['tapText', 'عاجل'], ['shot', '2-price']])
sc('p02-monthly-used', '/account/listings', [BROKER, ['plan', 'broker-month'], *Q_LISTINGS[:2], ['quota', {'kind': 'featured', 'id': 'demo-l1'}]], [
    ['shot', '1-strip'], ['tapText', 'تمييز'], ['shot', '2-price-after-quota'],
])

# ---------------------------------------------------------------- R01
sc('r01-normal', '/add', [SEEKER], [['fillAfter', 'الوصف', 'شقة عوائل قرب المترو'], ['scrollTo', 0], ['shot', '1-free'], ['tapText', 'نشر الطلب'], ['shot', '2-published', 600]])
sc('r01-urgent', '/add', [SEEKER], [
    ['fillAfter', 'الوصف', 'فيلا عاجلة في الملقا'], ['scrollTo', 0], ['tapText', 'طلب عاجل'], ['shot', '1-urgent-on'],
    ['tapText', 'نشر الطلب العاجل'], ['shot', '2-pay'], ['tapText', 'إلغاء', 'exact', 'last'], ['shot', '3-cancelled'],
    ['tapText', 'نشر الطلب العاجل'], ['tapText', 'ادفع 50 ريالًا'], ['wait', 800], ['shot', '4-paid'],
])
sc('r01-my-requests', '/account/requests', [SEEKER, ['request', {'id': 'demo-r1', 'description': 'شقة عوائل قرب المترو', 'types': ['apartment'], 'hoods': ['العليا'], 'min': 700000, 'max': 1000000}]], [
    ['shot', '1-list'], ['tapText', 'اجعله طلبًا عاجلًا'], ['shot', '2-pay'], ['tapText', 'إلغاء', 'exact', 'last'],
    ['tapText', 'تعديل الطلب'], ['scrollTo', 0], ['shot', '3-edit'],
    ['tapText', 'طلب عاجل'], ['scrollTo', 0], ['shot', '4-edit-urgent'],
    ['tapText', 'حفظ التعديل'], ['shot', '5-edit-pay'],
])
sc('r01-q01-ranges', '/add', [SEEKER], [
    ['fillNth', 0, '٩٠٠٠٠٠'], ['fillNth', 1, '٥٠٠٠٠٠'], ['fillAfter', 'الوصف', 'فيلا في الملقا'],
    ['tapText', 'نشر الطلب'], ['shot', '1-range-error'],
    ['fillNth', 0, '٥٠٠٠٠٠'], ['fillNth', 1, '٩٠٠٠٠٠'], ['tapText', 'نشر الطلب'], ['wait', 600], ['shot', '2-arabic-digits-saved'],
])

# ---------------------------------------------------------------- L01
READY_UNITS = [
    {'name': 'شقة A-12', 'license': '7200001111', 'verified': True, 'image': '/listings/apartment-1.jpg', 'price': 1850000},
    {'name': 'شقة B-07', 'license': '7200002222', 'verified': True, 'image': '/listings/apartment-2.jpg', 'price': 2100000, 'area': 310},
]
sc('l01-add-ready', '/add?type=project', [DEV], [
    ['fillAfter', 'العنوان', 'مشروع النخبة السكني'], ['fillHint', 'قريب من الخدمات والمدارس والمسجد…', 'قريب من الخدمات والمدارس وطريق الملك سلمان.'],
    ['tapText', 'إضافة وحدة'],
    ['fillHint', '72001122', '7200001111', 0], ['fillHint', '72001122', '٧٢٠٠٠٠٢٢٢٢', 1], ['tapText', 'تحقق'],
    ['tapText', 'متابعة'], ['scrollToText', 'مطلوب: أدخل رقم ترخيص', 0.55], ['shot', '1-unit2-required'],
    ['scrollToText', 'رقم الترخيص الإعلاني للوحدة', 0.45, 0], ['shot', '2-unit1-verified'],
    ['tapText', 'تحقق'], ['scrollToText', 'رقم الترخيص الإعلاني للوحدة', 0.45, 1], ['shot', '3-unit2-verified'],
])
sc('l01-add-offplan', '/add?type=project', [DEV], [
    ['fillAfter', 'العنوان', 'أبراج الواحة — على الخارطة'], ['tapText', 'جاهز'], ['tapText', 'على الخارطة'], ['tapText', 'إضافة وحدة'],
    ['scrollToText', 'حالة المشروع', 0.15], ['shot', '1-offplan'],
    ['scrollToText', 'استبدل الصور من الاستوديو', 0.3, 0], ['shot', '2-unit1-no-licence'],
    ['scrollToText', 'استبدل الصور من الاستوديو', 0.3, 1], ['shot', '3-unit2-no-licence'],
])
sc('l01-ready-units', '/projects/demo-p1/units/demo-p1-u1', [DEV, ['project', {'id': 'demo-p1', 'title': 'مشروع النخبة السكني', 'units': READY_UNITS}]], [
    ['scrollTo', -1], ['shot', '1-unit1'],
    ['go', '/projects/demo-p1/units/demo-p1-u2'], ['scrollTo', -1], ['shot', '2-unit2'],
    ['go', '/account/listings/demo-p1'], ['scrollToText', 'الترخيص 7200001111', 0.3], ['shot', '3-editor'],
    ['tapText', 'تعديل', 'exact', 1], ['scrollToText', 'رقم الترخيص الإعلاني للوحدة', 0.4], ['shot', '4-unit-editor'],
])
sc('l01-offplan-unit', '/projects/demo-p2/units/demo-p2-u1', [DEV, ['project', {'id': 'demo-p2', 'title': 'أبراج الواحة', 'delivery': 'offplan', 'units': [
    {'name': 'شقة 101', 'image': '/listings/apartment-2.jpg'}, {'name': 'شقة 102', 'image': '/listings/apartment-1.jpg'}]}]], [
    ['scrollTo', -1], ['shot', '1-unit-no-licence'], ['go', '/account/listings/demo-p2'], ['scrollToText', 'الوحدات —', 0.15], ['shot', '2-editor'],
])
sc('l01-legacy', '/account/listings/demo-p3', [DEV, ['project', {'id': 'demo-p3', 'title': 'مشروع قديم البيانات', 'license': '7200999999', 'units': [
    {'name': 'شقة 1'}, {'name': 'شقة 2'}]}]], [['scrollToText', 'الترخيص الإعلاني أصبح لكل وحدة', 0.3], ['shot', '1-notice'], ['go', '/projects/demo-p3/units/demo-p3-u1'], ['scrollTo', -1], ['shot', '2-unit-not-inherited']])

# ---------------------------------------------------------------- E01
E_LISTING = ['listing', {'id': 'demo-l1', 'title': 'فيلا مودرن بحي الملقا', 'images': ['/listings/villa-1.jpg', '/listings/villa-2.jpg', '/listings/apt-2.jpg']}]
sc('e01-photos', '/account/listings/demo-l1', [BROKER, E_LISTING], [
    ['shot', '1-open'],
    ['gallery', [GALLERY]], ['tapKey', 'photo-add'], ['shot', '2-added'],
    ['tapSem', 'حذف الصورة 2'], ['tapSem', 'اجعل الصورة 3 الغلاف'], ['shot', '3-edited'],
    ['tapText', 'حفظ وتحديث العرض'], ['wait', 600], ['shot', '4-saved'],
    ['go', '/listings/demo-l1'], ['shot', '5-details'],
    ['go', '/account/listings/demo-l1'], ['shot', '6-reopened'],
])
sc('e01-cancel', '/account/listings/demo-l1', [BROKER, E_LISTING], [['tapSem', 'حذف الصورة 1'], ['tapText', 'إلغاء'], ['shot', '1-discard'], ['tapText', 'تجاهل'], ['go', '/account/listings/demo-l1'], ['shot', '2-original']])
sc('e01-last-photo', '/account/listings/demo-l9', [BROKER, ['listing', {'id': 'demo-l9', 'title': 'أرض سكنية في الياسمين', 'type': 'land', 'hood': 'الياسمين', 'images': ['/listings/land-1.jpg']}]], [
    ['tapSem', 'حذف الصورة 1'], ['shot', '1-last-photo'],
    ['gallery', [], 'اسمح لزون بالوصول إلى الصور من إعدادات الهاتف ثم حاول مرة أخرى.'], ['tapKey', 'photo-add'], ['shot', '2-permission'],
])
sc('e01-q01-negative-price', '/account/listings/demo-l1', [BROKER, E_LISTING], [
    ['fillAfter', 'السعر', '-100'], ['tapText', 'حفظ وتحديث العرض'], ['scrollToText', 'السعر لا يكون سالبًا', 0.4], ['shot', ''],
])
sc('e01-q01-guard', '/account/listings/demo-l1', [BROKER, E_LISTING, ['signout'], acct('broker', 'ماجد الحربي', username='demo.broker2')], [['shot', '1-other-account']])
sc('e01-q01-guest', '/account/listings/demo-l1', [BROKER, E_LISTING, ['signout']], [['shot', '1-guest-login']])

# ---------------------------------------------------------------- Q01
sc('q01-auth-wrong-password', '/login', [BROKER, ['signout']], [
    ['tapText', 'تسجيل الدخول', 'exact', 'last'], ['fillAfter', 'اسم المستخدم', 'demo.broker'], ['fillAfter', 'كلمة السر', 'wrong-pass-1'],
    ['tapText', 'دخول'], ['shot', '1-refused'],
    ['fillAfter', 'كلمة السر', 'demo-pass-2026'], ['tapText', 'دخول'], ['wait', 600], ['shot', '2-signed-in'],
])
sc('q01-auth-phone', '/login', [], [
    ['tapText', 'حساب جديد'], ['tapText', 'حدد رغبتك ثم تصفّح'], ['tapText', 'متابعة'],
    ['fillAfter', 'الاسم أو الشركة', 'سارة القحطاني'], ['fillHint', '05XXXXXXXX', '05512'],
    ['fillAfter', 'اسم المستخدم', 'sara.q'], ['fillAfter', 'كلمة السر', 'secret-2026'],
    ['tapText', 'تحقق من الجوال'], ['shot', ''],
])
sc('q01-task', '/account/tasks', [BROKER, ['plan', 'broker-year'], ['tasks', [
    {'id': 't1', 'title': 'معاينة فيلا الملقا مع العميل', 'pri': 'مهم', 'remindAt': '2026-10-12T16:05'},
    {'id': 't2', 'title': 'تحديث سعر شقة العليا', 'pri': 'مؤجل', 'remindAt': '2026-10-15T09:30'},
]]], [
    ['shot', '1-arabic-dates'], ['tapText', 'مهمة جديدة'], ['fillHint', 'روح أكلم خالد يشتري الفيلا', '     '],
    ['tapText', 'حفظ المهمة'], ['scrollToText', 'لا يكفي فراغ', 0.5], ['shot', '2-spaces-refused'],
])
for name, route, setup in [
    ('q01-scale-plans', '/account/plans', [BROKER]),
    ('q01-scale-search', '/account/search', [BROKER, ['plan', 'broker-year']]),
    ('q01-scale-request', '/add', [SEEKER]),
    ('q01-scale-notifications', '/notifications', N_SETUP),
]:
    sc(name, route, setup, [['textScale', 1.2], ['shot', '']], size=[360, 780, 2])

only = sys.argv[2] if len(sys.argv) > 2 else ''
plan = {'size': [390, 844, 2], 'scenarios': [s for s in S if s['name'].startswith(only)]}
json.dump(plan, open(sys.argv[1], 'w'), ensure_ascii=False, indent=1)
print(len(plan['scenarios']), 'scenarios,', sum(1 for s in plan['scenarios'] for o in s['ops'] if o[0] == 'shot'), 'shots')
