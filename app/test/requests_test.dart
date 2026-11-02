// R01 — normal request free, urgent request 50 SAR (or from the quota),
// cancel / failure keep the form; REQ-02 ranges; REQ-03 Arabic digits.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/requests/request_detail_screen.dart';

import 'support/test_env.dart';

Finder _field(int i) => find.byType(EditableText).at(i);

/// Request form fields in order: budget from/to, area from/to, rooms from/to, description.
const int _pmin = 0, _pmax = 1, _desc = 6;

Future<void> _type(WidgetTester tester, int index, String text) async {
  await tester.ensureVisible(_field(index));
  await tester.enterText(_field(index), text);
  await tester.pump();
}

RequestItem? _mine() => store.myRequests().isEmpty ? null : store.myRequests().first;

void main() {
  group('add a request', () {
    testWidgets('a normal request is published free — no payment step', (tester) async {
      await freshDevice();
      signUp('r01.normal', role: 'seeker');
      await pumpApp(tester, '/add');
      expect(find.text('مجاني'), findsWidgets);
      await _type(tester, _desc, 'شقة عوائل قرب المترو');
      await tapText(tester, 'نشر الطلب');
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.byType(RequestDetailScreen), findsOneWidget);
      expect(_mine()!.urgent, isFalse);
      expect(billing.ledger(), isEmpty);
    });

    testWidgets('urgent: 50 SAR shown before paying; cancel keeps the form and publishes nothing', (tester) async {
      await freshDevice();
      signUp('r01.urgent', role: 'seeker');
      await pumpApp(tester, '/add');
      await _type(tester, _desc, 'فيلا عاجلة في الملقا');
      await tapText(tester, 'طلب عاجل');
      expect(find.text('50 ريالًا'), findsWidgets, reason: 'the price note of the urgent request');
      await tapText(tester, 'نشر الطلب العاجل');
      expect(find.text('طلب عاجل — 50 ريالًا'), findsOneWidget);
      expect(find.text('ادفع 50 ريالًا'), findsOneWidget);
      await tapText(tester, 'إلغاء');
      expect(store.myRequests(), isEmpty, reason: 'nothing published');
      expect(billing.ledger(), isEmpty, reason: 'nothing charged');
      expect(find.text('فيلا عاجلة في الملقا'), findsOneWidget, reason: 'the form keeps the description');
      expect(find.textContaining('بياناتك باقية'), findsOneWidget);

      // a failed payment: same — nothing changes
      PaymentSimulator.nextStatus = PaymentStatus.failed;
      await tapText(tester, 'نشر الطلب العاجل');
      await tapText(tester, 'ادفع 50 ريالًا');
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.textContaining('تعذّر إتمام الدفع'), findsOneWidget);
      await tapText(tester, 'إلغاء');
      expect(store.myRequests(), isEmpty);

      // pay → published as urgent, charged once
      await tapText(tester, 'نشر الطلب العاجل');
      await tapText(tester, 'ادفع 50 ريالًا');
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(RequestDetailScreen), findsOneWidget);
      expect(_mine()!.urgent, isTrue);
      expect(billing.ledger().single.amount, 50);
    });

    testWidgets('a yearly broker uses the quota — no 50 on top', (tester) async {
      await freshDevice();
      signUp('r01.quota', role: 'broker');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      await pumpApp(tester, '/add?type=request');
      expect(find.textContaining('المتبقي: 5 طلبات عاجلة'), findsOneWidget);
      await _type(tester, _desc, 'عميل يبحث عن أرض');
      await tapText(tester, 'طلب عاجل');
      await tapText(tester, 'نشر الطلب العاجل');
      expect(find.text('استخدم الرصيد'), findsOneWidget);
      expect(find.textContaining('ادفع'), findsNothing);
      await tapText(tester, 'استخدم الرصيد');
      await tester.pump(const Duration(milliseconds: 300));
      expect(_mine()!.urgent, isTrue);
      expect(billing.ledger().single.fromQuota, isTrue);
      expect(billing.ledger().single.amount, 0);
      expect(billing.remaining(BoostKind.urgentRequest), 4);
    });

    testWidgets('REQ-03 Arabic digits are kept; REQ-02 «from» larger than «to» is refused', (tester) async {
      await freshDevice();
      signUp('r01.digits', role: 'seeker');
      await pumpApp(tester, '/add');
      await _type(tester, _pmin, '٩٠٠٠٠٠');
      await _type(tester, _pmax, '٥٠٠٠٠٠');
      await _type(tester, _desc, 'شقة');
      await tapText(tester, 'نشر الطلب');
      expect(find.textContaining('«من» أكبر من «إلى»'), findsOneWidget);
      expect(store.myRequests(), isEmpty);

      await _type(tester, _pmin, '٥٠٠٠٠٠');
      await _type(tester, _pmax, '٩٠٠٠٠٠');
      await tapText(tester, 'نشر الطلب');
      await tester.pump(const Duration(milliseconds: 300));
      final r = _mine()!;
      expect(r.priceMin, 500000);
      expect(r.priceMax, 900000);
    });
  });

  group('my requests', () {
    RequestItem seed(String id) => RequestItem.fromJson(<String, Object?>{
      'id': id,
      'purpose': 'sale',
      'city': 'الرياض',
      'neighborhoods': <String>['الملقا'],
      'types': <String>['villa'],
      'priceMin': 1000000,
      'priceMax': 2000000,
      'description': 'طلب قائم',
      'author': 'أنا',
      'role': 'seeker',
      'urgent': false,
    });

    testWidgets('upgrade to urgent: 50 after confirmation, then it stays urgent (no second charge)', (tester) async {
      await freshDevice();
      signUp('r01.upgrade', role: 'seeker');
      store.saveRequest(seed('up-1'));
      await pumpApp(tester, '/account/requests');
      expect(find.textContaining('طلب عاجل'), findsWidgets);
      await tapText(tester, 'اجعله طلبًا عاجلًا');
      expect(find.text('طلب عاجل — 50 ريالًا'), findsOneWidget);
      await tapText(tester, 'ادفع 50 ريالًا');
      await tester.pump(const Duration(milliseconds: 300));
      expect(store.requestById('up-1')!.urgent, isTrue);
      expect(find.text('اجعله طلبًا عاجلًا'), findsNothing);
      expect(billing.ledger().length, 1);
    });

    testWidgets('edit: Arabic digits, ranges, and making it urgent from the editor', (tester) async {
      await freshDevice();
      signUp('r01.edit', role: 'seeker');
      store.saveRequest(seed('ed-1'));
      await pumpApp(tester, '/account/requests');
      await tapText(tester, 'تعديل الطلب');
      expect(find.text('تعديل الطلب'), findsWidgets);
      await tester.enterText(_field(0), '٣٠٠٠٠٠٠');
      await tester.enterText(_field(1), '٢٠٠٠٠٠٠');
      await tester.pump();
      await tapText(tester, 'حفظ التعديل');
      expect(find.textContaining('«من» أكبر من «إلى»'), findsOneWidget);
      expect(store.requestById('ed-1')!.priceMin, 1000000, reason: 'not saved');

      await tester.enterText(_field(0), '١٥٠٠٠٠٠');
      await tester.pump();
      await tapText(tester, 'طلب عاجل');
      await tapText(tester, 'حفظ التعديل');
      expect(find.text('طلب عاجل — 50 ريالًا'), findsOneWidget);
      await tapText(tester, 'إلغاء', last: true); // the dialog's button
      expect(store.requestById('ed-1')!.urgent, isFalse, reason: 'cancelled: not urgent');
      expect(store.requestById('ed-1')!.priceMin, 1000000, reason: 'cancelled: nothing saved');
      expect(find.textContaining('لم يُحفظ التعديل بعد'), findsOneWidget);

      await tapText(tester, 'حفظ التعديل');
      await tapText(tester, 'ادفع 50 ريالًا');
      await tester.pump(const Duration(milliseconds: 300));
      final r = store.requestById('ed-1')!;
      expect(r.urgent, isTrue);
      expect(r.priceMin, 1500000);
      expect(r.priceMax, 2000000);
      expect(billing.ledger().single.amount, 50);
    });
  });
}
