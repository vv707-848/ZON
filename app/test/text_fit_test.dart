// §13 "phones 360 / 390 / 430 — no text or button cut": a cut-off text does
// not raise a layout error, so the overflow test cannot see it. Here every
// text added or changed by this update is checked on the smallest phone
// (360 wide) with the larger text setting (×1.2): shown in full — on one
// line or wrapped — never cut or ended with «…».

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/services/dictation.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/store.dart';

import 'support/test_env.dart';

const Size _phone = Size(360, 780);

/// Texts among [texts] that are missing or not shown in full.
List<String> _cut(WidgetTester tester, Iterable<String> texts) {
  final out = <String>[];
  for (final t in texts) {
    final f = find.text(t, findRichText: true);
    if (f.evaluate().isEmpty) {
      out.add('missing: $t');
      continue;
    }
    for (final e in f.evaluate()) {
      final p = e.renderObject;
      if (p is! RenderParagraph) continue;
      final fits = p.getMaxIntrinsicWidth(double.infinity) <= p.size.width + 0.5;
      final wraps = p.softWrap && p.getMinIntrinsicWidth(double.infinity) <= p.size.width + 0.5;
      if (p.didExceedMaxLines || !(fits || wraps)) out.add('cut: $t (${p.size.width.toStringAsFixed(1)} wide)');
    }
  }
  return out;
}

Future<void> _open(WidgetTester tester, String route) async {
  tester.platformDispatcher.textScaleFactorTestValue = 1.2;
  addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
  await pumpApp(tester, route, size: _phone);
  await tester.pump(const Duration(milliseconds: 300));
}

class _DeniedDictation implements DictationService {
  @override
  Future<DictationAvailability> prepare() async => DictationAvailability.denied;
  @override
  Future<bool> start({
    required void Function(String words, bool isFinal) onWords,
    required VoidCallback onEnd,
    required void Function(String code) onError,
  }) async => false;
  @override
  Future<void> stop() async {}
  @override
  Future<void> cancel() async {}
}

class _ListeningDictation implements DictationService {
  @override
  Future<DictationAvailability> prepare() async => DictationAvailability.available;
  @override
  Future<bool> start({
    required void Function(String words, bool isFinal) onWords,
    required VoidCallback onEnd,
    required void Function(String code) onError,
  }) async => true;
  @override
  Future<void> stop() async {}
  @override
  Future<void> cancel() async {}
}

void main() {
  testWidgets('P01 broker plan and its payment dialog', (tester) async {
    await freshDevice();
    signUp('fit.broker', role: 'broker');
    await _open(tester, '/account/plans');
    final cut = _cut(tester, const <String>[
      'سعيك كامل. وأعمالك أكثر تنظيمًا.',
      'وفّر 1,060 ريال مقارنة بالاشتراك الشهري',
      'وفّر 1,060',
      'الوصول إلى طلبات العملاء في مدينتك',
      'بحث متقدم في العروض والطلبات',
      'تنظيم الملاحظات ومتابعة الملاك',
      'تحتفظ به كاملًا',
      'مشاركة زون بـ10٪ من السعي',
      'طلبات العملاء والبحث المتقدم',
      'تلخيص الملاحظات ومتابعة الملاك',
      'اشترك في باقة الوسيط',
    ]);
    await tapText(tester, 'اشترك في باقة الوسيط');
    cut.addAll(_cut(tester, const <String>['باقة الوسيط السنوية — 2,300 ريال', 'دفع تجريبي — لا يُخصم مبلغ فعلي', 'ادفع 2,300 ريال', 'إلغاء']));
    expect(cut, isEmpty, reason: cut.join('\n'));
  });

  testWidgets('P03 seeker plan and P04 owner plan with its steps', (tester) async {
    await freshDevice();
    signUp('fit.seeker', role: 'seeker');
    await _open(tester, '/account/plans');
    final cut = _cut(tester, const <String>[
      'عروض أقرب إلى رغبتك',
      'وصول مباشر إلى المطورين والملاك',
      'تصفح العروض والمشاريع، والفلاتر، والطلبات العادية، والمفضلة والتواصل.',
      'اشترك في باقة الباحث',
    ]);
    await freshDevice();
    signUp('fit.owner', role: 'owner');
    final c = ownerCases.submitContract(deedNo: '310115004512');
    ownerCases.approveContract(c.id, reference: 'test');
    await _open(tester, '/account/plans');
    cut.addAll(
      _cut(tester, const <String>[
        'انشر عقارك بسعي واضح',
        'سعي بعد بيع العقار المعلن',
        'ظهور عرضك في البحث والمطابقة',
        'رسوم الترخيص الإعلاني: 50 ريالًا للهيئة العامة للعقار',
        'تتفعّل الباقة بعد إتمام عقد الوساطة ونشر العرض.',
        'حالة الباقة: غير مفعّلة بعد',
        'رسوم الترخيص الإعلاني',
        'لم تُدفع',
        'أكمل نشر عقارك',
      ]),
    );
    expect(cut, isEmpty, reason: cut.join('\n'));
  });

  testWidgets('P02 quota lines, the confirmation and the boost price', (tester) async {
    await freshDevice();
    signUp('fit.quota', role: 'broker');
    billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
    publishTestListing('fit-l1');
    await _open(tester, '/account/listings');
    final cut = _cut(tester, const <String>[
      'المتبقي من رصيدك السنوي: 15 تمييزًا مميزًا',
      'المتبقي من رصيدك السنوي: 10 تمييزات عاجلة',
      'تمييز',
      'عاجل',
    ]);
    await tapText(tester, 'تمييز');
    cut.addAll(
      _cut(tester, const <String>['تمييز العرض «مميز»', 'من رصيدك السنوي — يبقى بعده 14 تمييزًا مميزًا. يستمر 7 أيام.', 'استخدم الرصيد', 'إلغاء']),
    );
    await tapText(tester, 'إلغاء', last: true);

    await freshDevice();
    signUp('fit.price', role: 'broker');
    publishTestListing('fit-l2');
    await _open(tester, '/account/listings');
    cut.addAll(_cut(tester, const <String>['تمييز «مميز» — 100 ريال', 'تمييز «عاجل» — 100 ريال']));
    await tapText(tester, 'عاجل');
    cut.addAll(_cut(tester, const <String>['تمييز العرض «عاجل» — 100 ريال', 'تُدفع مرة واحدة لهذه العملية.', 'ادفع 100 ريال']));
    expect(cut, isEmpty, reason: cut.join('\n'));
  });

  testWidgets('R01 request prices, the urgent payment and «اجعله طلبًا عاجلًا»', (tester) async {
    await freshDevice();
    signUp('fit.req', role: 'broker');
    billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
    await _open(tester, '/add?type=request');
    final cut = _cut(tester, const <String>['طلب عادي', 'مجاني', 'من رصيدك — المتبقي: 5 طلبات عاجلة']);

    await freshDevice();
    signUp('fit.req2', role: 'seeker');
    await _open(tester, '/add');
    cut.addAll(_cut(tester, const <String>['50 ريالًا']));
    await tester.enterText(find.byType(EditableText).at(6), 'فيلا في الملقا');
    await tapText(tester, 'طلب عاجل');
    await tapText(tester, 'نشر الطلب العاجل');
    cut.addAll(_cut(tester, const <String>['طلب عاجل — 50 ريالًا', 'ادفع 50 ريالًا']));
    await tapText(tester, 'إلغاء', last: true);
    cut.addAll(_cut(tester, const <String>['لم يُنشر الطلب. بياناتك باقية — انشره عاديًا مجانًا أو أكمل الدفع.']));

    store.saveRequest(
      const RequestItem(
        id: 'fit-r1',
        purpose: 'sale',
        city: 'الرياض',
        neighborhoods: <String>['العليا'],
        types: <String>['apartment'],
        priceMin: 700000,
        priceMax: 1000000,
        areaMin: 0,
        areaMax: 0,
        roomsMin: 2,
        roomsMax: 4,
        description: 'شقة عوائل',
        author: 'حساب اختبار',
        role: 'seeker',
      ),
    );
    await _open(tester, '/account/requests');
    cut.addAll(_cut(tester, const <String>['اجعله طلبًا عاجلًا']));
    expect(cut, isEmpty, reason: cut.join('\n'));
  });

  testWidgets('V01 dictation status and messages inside the field', (tester) async {
    await freshDevice();
    signUp('fit.v01', role: 'seeker');
    dictation = _ListeningDictation();
    await _open(tester, '/account/matching');
    await tester.tap(find.byKey(const ValueKey<String>('dictation-mic')));
    await tester.pump();
    final cut = _cut(tester, const <String>['يستمع… اضغط المايك للإيقاف']);
    await tester.tap(find.byKey(const ValueKey<String>('dictation-mic')));
    await tester.pump();

    dictation = _DeniedDictation();
    await _open(tester, '/account/matching');
    await tester.tap(find.byKey(const ValueKey<String>('dictation-mic')));
    await tester.pump();
    cut.addAll(_cut(tester, const <String>['اسمح بالميكروفون والتعرّف على الكلام من إعدادات الهاتف، أو اكتب الوصف.']));
    expect(cut, isEmpty, reason: cut.join('\n'));
  });

  testWidgets('L01 licence field of each unit and E01 photo editor', (tester) async {
    await freshDevice();
    signUp('fit.dev', role: 'developer');
    await _open(tester, '/add?type=project');
    final cut = _cut(tester, const <String>['رقم الترخيص الإعلاني للوحدة', 'لكل وحدة رقمها — أدخله ثم تحقق', 'تحقق']);

    await freshDevice();
    signUp('fit.e01', role: 'broker');
    publishTestListing('fit-e1', images: const <String>['/listings/villa-1.jpg']);
    final semantics = tester.ensureSemantics();
    await _open(tester, '/account/listings/fit-e1');
    cut.addAll(_cut(tester, const <String>['الغلاف', 'أضف صوراً', 'الصورة الأولى هي الغلاف — اضغط النجمة لتغييره.', 'حفظ وتحديث العرض']));
    await tester.tap(find.bySemanticsLabel('حذف الصورة 1'));
    await tester.pump();
    cut.addAll(_cut(tester, const <String>['يبقى للعرض صورة واحدة على الأقل — أضف صورة أخرى ثم احذف هذه.']));
    semantics.dispose();
    expect(cut, isEmpty, reason: cut.join('\n'));
  });

  testWidgets('N01 notification rows and the empty state', (tester) async {
    await freshDevice();
    signUp('fit.n01', role: 'owner');
    await _open(tester, '/notifications');
    final cut = _cut(tester, const <String>['الإشعارات', 'لا توجد إشعارات جديدة']);
    await freshDevice();
    signUp('fit.n01b', role: 'broker');
    billing.startPeriod(Plans.brokerMonth, paymentRef: 'test');
    publishTestListing('fit-n1');
    billing.useQuota(BoostKind.featured, 'fit-n1'); // the monthly featuring used → a plan notification
    // a message row (the type is ready for the server; added here as a sample)
    notifications.add(
      kind: NoteKind.message,
      title: 'رسالة جديدة من سارة القحطاني',
      body: 'أبي شقة عوائل بالعليا حتى مليون',
      target: '/chats/sara',
      demo: true,
    );
    await _open(tester, '/notifications');
    cut.addAll(_cut(tester, const <String>['انتهى رصيد تمييز «مميز»', 'رسالة جديدة من سارة القحطاني']));
    expect(cut, isEmpty, reason: cut.join('\n'));
  });
}
