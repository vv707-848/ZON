#!/usr/bin/env python3
"""Screenshot plan for zon 1.2.1+4 — the places changed by the fixes F01–F10
(docs/history/screenshots_v3_1.pdf).  usage: make_plan_v3_1.py out.json [only-prefix]

The "gallery" photo is a file on disk: set ZON_SHOTS_GALLERY to any JPEG
(default /tmp/zon-shots/IMG_2041.jpg). F05 copies it next to itself as the
picker's temporary file, lets the app keep it, then deletes the temporary
file; GONE is a path that does not exist (a photo cleaned by the system).
"""
import json, os, sys

GALLERY = os.environ.get('ZON_SHOTS_GALLERY', '/tmp/zon-shots/IMG_2041.jpg')
PICKED = os.path.join(os.path.dirname(GALLERY), 'image_picker_8F2C.jpg')   # the picker's temporary copy
GONE = os.path.join(os.path.dirname(GALLERY), 'image_picker_GONE_0007.jpg')  # cleaned by the system
S = []


def sc(name, route, setup=None, ops=None, size=None):
    d = {'name': name, 'route': route, 'setup': setup or [], 'ops': ops or [['shot', '']]}
    if size:
        d['size'] = size
    S.append(d)


def acct(role, name, username=None, city='الرياض'):
    return ['account', {'role': role, 'name': name, 'username': username or 'demo.' + role, 'city': city}]


BROKER = acct('broker', 'فهد العتيبي')
DEV = acct('developer', 'شركة الدار للتطوير')


def l11(id, title, advertiser, role='broker', img='/listings/villa-1.jpg', hood='الملقا', ptype='villa', price=2500000):
    """A listing as version 1.1 saved it: author name and role, no owner id."""
    return {'id': id, 'kind': 'unit', 'title': title, 'purpose': 'sale', 'city': 'الرياض', 'neighborhood': hood,
            'propertyType': ptype, 'source': role, 'price': price, 'rooms': 5, 'baths': 4, 'area': 400, 'images': [img],
            'description': 'عرض محفوظ بالإصدار السابق.', 'advertiser': advertiser, 'advertiserRole': role,
            'license': '7200000001', 'active': True}


def listing(id, title, **kw):
    d = {'id': id, 'kind': 'unit', 'title': title, 'purpose': 'sale', 'city': 'الرياض', 'neighborhood': 'العليا',
         'propertyType': 'apartment', 'source': 'broker', 'price': 1250000, 'rooms': 3, 'baths': 2, 'area': 165,
         'images': ['/listings/apt-1.jpg'], 'description': 'شقة عوائل بتشطيب ممتاز قريبة من المترو.',
         'advertiser': 'فهد العتيبي', 'advertiserRole': 'broker', 'license': '7200123456', 'active': True}
    d.update(kw)
    return ['listingJson', d]


# ---------------------------------------------------------------- F01
SESSION11 = json.dumps({'name': 'فهد العتيبي', 'username': 'fahd', 'password': 'old-password-1', 'phone': '0551234567',
                        'role': 'broker', 'city': 'الرياض'}, ensure_ascii=False)
F01 = ['prefs', {
    'zon.session.v1': SESSION11,
    'zon.extra.listings.v1': json.dumps([
        l11('u-1700000000001', 'فيلا الملقا — محفوظة بالإصدار السابق', 'فهد العتيبي'),
        l11('u-1700000000002', 'شقة العليا — ناشر آخر على الجهاز', 'سالم القحطاني', role='owner', img='/listings/apt-2.jpg', hood='العليا', ptype='apartment', price=950000),
    ], ensure_ascii=False),
}]
sc('f01-upgrade', '/account/listings', [F01], [
    ['shot', '1-my-listings'],
    ['go', '/account/settings'], ['scrollToText', 'بيانات من الإصدار السابق', 0.3], ['shot', '2-recovery'],
    ['tapText', 'طلب استعادة'], ['scrollToText', 'بيانات من الإصدار السابق', 0.3], ['shot', '3-requested'],
])

# ---------------------------------------------------------------- F02 / F10
sc('f10-pay-back', '/account/plans', [BROKER], [
    ['tapText', 'اشترك في باقة الوسيط'], ['payHold'], ['tapText', 'ادفع 2,300 ريال'], ['wait', 400],
    ['back'], ['shot', '1-back-blocked'],
    ['payRelease'], ['wait', 1500], ['scrollTo', -1], ['shot', '2-active'],
])
sc('f02-switch', '/account/plans', [BROKER], [
    ['tapText', 'اشترك في باقة الوسيط'], ['payHold'], ['tapText', 'ادفع 2,300 ريال'], ['wait', 400],
    ['switch', 'demo.broker2', 'broker', 'ماجد الحربي'], ['payRelease'], ['wait', 800],
    ['go', '/account/plans'], ['scrollTo', -1], ['shot', '1-other-account'],
    ['switch', 'demo.broker'], ['go', '/notifications'], ['shot', '2-payer-notified'],
    ['go', '/account/plans'], ['scrollTo', -1], ['shot', '3-payer-active'],
])

# ---------------------------------------------------------------- F03 / F04 (project editor)
UNIT_A = {'name': 'شقة A-12', 'license': '7200001111', 'verified': True, 'image': '/listings/apartment-1.jpg', 'price': 1850000}
UNIT_B = {'name': 'شقة B-07', 'license': '7200002222', 'verified': True, 'image': '/listings/apartment-2.jpg', 'price': 2100000, 'area': 310}
P1 = ['project', {'id': 'demo-p1', 'title': 'مشروع النخبة السكني', 'units': [UNIT_A]}]
P2 = ['project', {'id': 'demo-p2', 'title': 'مشروع النخبة السكني', 'units': [UNIT_A, UNIT_B]}]
sc('f03-draft', '/account/listings/demo-p1', [DEV, P1], [
    ['tapText', 'إضافة وحدة'], ['scrollToText', 'نوع الوحدة', 0.25, 0], ['shot', '1-draft-open'],
    ['tapText', 'حفظ الوحدة'], ['scrollToText', 'أكمل رقم الترخيص الإعلاني لهذه الوحدة', 0.6], ['shot', '2-draft-refused'],
    ['go', '/projects/demo-p1'], ['scrollToText', 'شقة A-12', 0.4], ['shot', '3-published-unchanged'],
])
sc('f03-licence', '/account/listings/demo-p1', [DEV, P1], [
    ['tapText', 'تعديل'], ['fillHint', '72001122', ''], ['tapText', 'حفظ الوحدة'],
    ['scrollToText', 'أكمل رقم الترخيص الإعلاني لهذه الوحدة', 0.6], ['shot', '1-cleared-refused'],
    ['scrollToText', 'الترخيص 7200001111', 0.3], ['shot', '2-published-kept'],
])
sc('f03-delete', '/account/listings/demo-p2', [DEV, P2], [
    ['tapText', 'مباع', 'exact', 1], ['scrollToText', 'الوحدات —', 0.15], ['shot', '1-sold'],
    ['tapText', 'حذف'], ['shot', '2-confirm'],
])
sc('f04-editor', '/account/listings/demo-p1', [DEV, P1], [
    ['tapText', 'تعديل'], ['fillAfter', 'السعر الثابت', '-100'], ['tapText', 'حفظ الوحدة'],
    ['scrollToText', 'السعر لا يكون سالبًا', 0.6], ['shot', '1-negative'],
    ['fillAfter', 'السعر الثابت', '١٩٠٠٠٠٠'], ['tapText', 'حفظ الوحدة'], ['scrollToText', 'شقة A-12', 0.3], ['shot', '2-arabic-digits-saved'],
])
sc('f04-add', '/add?type=project', [DEV], [
    ['fillAfter', 'العنوان', 'مشروع النخبة السكني'], ['fillHint', 'قريب من الخدمات والمدارس والمسجد…', 'قريب من الخدمات والمدارس وطريق الملك سلمان.'],
    ['fillHint', '72001122', '7200001111'], ['tapText', 'تحقق'],
    ['fillAfter', 'السعر الثابت', '-5'], ['tapText', 'متابعة'], ['scrollToText', 'السعر لا يكون سالبًا: وحدة 1', 0.6], ['shot', '1-negative'],
    ['fillAfter', 'السعر الثابت', '12.5.1'], ['scrollToText', 'أدخل السعر بالأرقام: وحدة 1', 0.6], ['shot', '2-not-a-number'],
])

# ---------------------------------------------------------------- F05
E_LISTING = ['listing', {'id': 'demo-l1', 'title': 'فيلا مودرن بحي الملقا', 'images': ['/listings/villa-1.jpg', '/listings/villa-2.jpg']}]
sc('f05-kept', '/account/listings/demo-l1', [BROKER, E_LISTING], [
    ['copyFile', GALLERY, PICKED], ['gallery', [PICKED]], ['tapKey', 'photo-add'], ['wait', 400],
    ['tapSem', 'اجعل الصورة 3 الغلاف'], ['shot', '1-picked-cover'],
    ['tapText', 'حفظ وتحديث العرض'], ['wait', 600],
    ['deleteFile', PICKED], ['go', '/listings/demo-l1'], ['shot', '2-after-temp-cleaned'],
])
sc('f05-unavailable', '/listings/demo-u1', [BROKER, listing('demo-u1', 'شقة العليا — صورة لم تعد على الجهاز', images=[GONE])], [
    ['shot', '1-details'], ['go', '/account/listings'], ['shot', '2-my-listings'],
])

# ---------------------------------------------------------------- F06 / F07
sc('f06-floor', '/account/listings/demo-f6', [BROKER, listing('demo-f6', 'شقة العليا — قرب المترو', floorLevel='second')], [
    ['scrollToText', 'الطابق', 0.4], ['shot', '1-editor-floor-2'],
    ['fillAfter', 'السعر', '1300000'], ['tapText', 'حفظ وتحديث العرض'], ['wait', 600],
    ['go', '/listings/demo-f6'], ['scrollToText', 'الطابق', 0.4], ['shot', '2-details-kept'],
])
sc('f07-paused', '/account/listings/demo-f7', [BROKER, listing('demo-f7', 'شقة العليا — موقوفة', active=False)], [
    ['fillAfter', 'السعر', '1200000'], ['tapText', 'حفظ وتحديث العرض'], ['wait', 700],
    ['tapText', 'غير النشط 1'], ['shot', '1-still-paused'], ['scrollToText', 'تنشيط', 0.7], ['shot', '2-activate-button'],
])

only = sys.argv[2] if len(sys.argv) > 2 else ''
plan = {'size': [390, 844, 2], 'scenarios': [s for s in S if s['name'].startswith(only)]}
json.dump(plan, open(sys.argv[1], 'w'), ensure_ascii=False, indent=1)
print(len(plan['scenarios']), 'scenarios,', sum(1 for s in plan['scenarios'] for o in s['ops'] if o[0] == 'shot'), 'shots')
