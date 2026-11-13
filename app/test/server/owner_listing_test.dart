// An owner's listing with a server (lib/data/server/server_owner_cases.dart, server_payments.dart; listing_form.dart):
// his case from its deed, the contract's answer asked of REGA, the licence fee paid in the app — a server's
// simulation said so, a real provider not payable in this build — then his listing on his case's licence. The
// server's answers are the ones its tests recorded (docs/contracts/fixtures/api: owner_cases_none, owner_case_opened,
// owner_case_waiting, owner_case_fee, owner_cases, checkout_ad_licence, payment_paid, owner_case_listing,
// owner_listing_created, owner_case_refused).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/shared/widgets/billing_flow.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

Finder _field(String placeholder) => find.byWidgetPredicate((w) => w is TextField && w.decoration?.hintText == placeholder);

String _path() {
  String? name;
  Nav.key.currentState!.popUntil((route) {
    name = route.settings.name;
    return true;
  });
  return name ?? '';
}

Future<void> _settle(WidgetTester tester, [int times = 6]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

void main() {
  final caseId = _one('owner_case_opened')['id']! as int;
  final checkout = _one('checkout_ad_licence');
  final paymentId = checkout['payment_id']! as String;
  final fee = riyals(int.parse((_one('owner_case_fee')['next']! as Map)['price']!.toString().split('.').first));

  Future<FakeServer> asOwner(WidgetTester tester, {required Object? cases}) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'owner-cases', 200, cases);
    await serverSignIn(
      server,
      account: me(id: 41, role: 'owner', name: 'عبدالرحمن الدوسري'),
    );
    await pumpApp(tester, '/add?type=listing');
    await _settle(tester);
    return server;
  }

  testWidgets('his deed, the contract asked of REGA, the fee paid (a simulation, said so), then his listing once', (tester) async {
    final server = await asOwner(tester, cases: recorded('owner_cases_none'));
    server.on('POST', 'owner-cases', 201, recorded('owner_case_opened'));
    server.on('GET', 'owner-cases/$caseId', 200, recorded('owner_case_waiting'));
    await tapText(tester, 'استمرار');
    await _settle(tester);
    expect(find.text('رقم جوال المالك'), findsNothing, reason: 'his own account files it: the deed alone');
    await tester.enterText(find.byType(EditableText).first, '310112233445');
    await tester.pump();
    await tapText(tester, 'إرسال عقد الوساطة');
    await _settle(tester);

    final opened = server.sent('POST', 'owner-cases').single;
    expect(FakeServer.body(opened), <String, Object?>{'deed_number': '310112233445'});
    expect(opened.headers['Idempotency-Key'], isNotNull, reason: 'one case, however often sent');
    expect(find.text('بانتظار موافقتك'), findsOneWidget);
    expect(find.textContaining('محاكاة'), findsNothing, reason: 'no simulated approval with a server');

    await tapText(tester, 'تحديث حالة العقد');
    await _settle(tester);
    expect(find.text('لم تصل موافقتك على العقد بعد. وافق عليه عبر نفاذ ثم حدّث الحالة.'), findsOneWidget);

    server.on('GET', 'owner-cases/$caseId', 200, recorded('owner_case_fee'));
    await tapText(tester, 'تحديث حالة العقد');
    await _settle(tester);
    expect(find.text('موثّق'), findsOneWidget);

    server.on('POST', 'checkout', 201, recorded('checkout_ad_licence'));
    server.on('POST', 'payments/$paymentId/confirm', 200, recorded('payment_paid'));
    await tapText(tester, 'ادفع رسوم الترخيص — $fee');
    await _settle(tester);
    expect(FakeServer.body(server.sent('POST', 'checkout').single), <String, Object?>{
      'purpose': 'ad_licence',
      'target': <String, Object?>{'type': 'owner_case', 'id': caseId},
    });
    expect(find.text('دفع تجريبي — لا يُخصم مبلغ فعلي'), findsOneWidget, reason: "the server's payments are its simulation");

    server.on('GET', 'owner-cases/$caseId', 200, recorded('owner_case_listing'));
    await tapText(tester, 'ادفع $fee', last: true);
    await _settle(tester, 10);
    expect(FakeServer.body(server.sent('POST', 'payments/$paymentId/confirm').single), <String, Object?>{
      'reference': (checkout['client']! as Map)['reference'],
    });
    final licence = (_one('owner_case_listing')['licence']! as Map)['number']! as String;
    expect(find.text(licence), findsOneWidget);

    server.on('POST', 'listings', 201, recorded('owner_listing_created'));
    await tapText(tester, 'متابعة إلى تفاصيل العرض');
    await _settle(tester);
    await tester.enterText(_field('أدخل عنوان العقار'), 'فيلا في الملقا');
    await tester.enterText(_field('ر.س'), '2000000');
    await tester.enterText(_field('أضف وصفًا للعقار'), 'فيلا واسعة بحديقة خلفية');
    await tester.pump();
    await tapText(tester, 'متابعة');
    await _settle(tester);

    final listing = server.sent('POST', 'listings').single;
    final body = FakeServer.body(listing);
    expect(body['owner_case_id'], caseId, reason: "on his case's licence");
    expect(body.containsKey('licence_number'), isFalse);
    expect(body['title'], 'فيلا في الملقا');
    expect(listing.headers['Idempotency-Key'], isNotNull);
    expect(_path(), '/account/listings/L${_one('owner_listing_created')['id']}/review');
  });

  testWidgets('a real provider is not payable in this build: said so, nothing confirmed', (tester) async {
    final server = await asOwner(tester, cases: recorded('owner_cases'));
    final real = <String, Object?>{
      ...recorded('checkout_ad_licence'),
      'data': <String, Object?>{
        ...checkout,
        'provider': 'moyasar',
        'client': <String, Object?>{'publishable_key': 'pk_live_x', 'amount': 5000, 'currency': 'SAR'},
      },
    };
    server.on('POST', 'checkout', 201, real);
    expect(find.text('موثّق'), findsOneWidget, reason: 'his case still on its way opens where it stands');
    await tapText(tester, 'ادفع رسوم الترخيص — $fee');
    await _settle(tester);

    expect(find.text('الدفع غير متاح في هذه النسخة حتى ربط بوابة الدفع.'), findsOneWidget);
    expect(find.text('دفع تجريبي — لا يُخصم مبلغ فعلي'), findsNothing);
    expect(server.sent('POST', 'payments/$paymentId/confirm'), isEmpty);
  });

  testWidgets('a contract REGA refused says so, and the deed can be sent again', (tester) async {
    await asOwner(
      tester,
      cases: <String, Object?>{
        'success': true,
        'data': <Object?>[_one('owner_case_refused')],
      },
    );
    expect(find.text('لم يُعتمد'), findsOneWidget);
    expect(find.text('لم يُعتمد عقد الوساطة لدى الهيئة. راجع رقم الصك ثم أرسل العقد من جديد.'), findsOneWidget);
    await tapText(tester, 'إرسال العقد من جديد');
    await _settle(tester);
    expect(find.text('إرسال عقد الوساطة'), findsOneWidget);
  });

  testWidgets('«مباع»: he says the price it sold for, checked before it is sent', (tester) async {
    await freshDevice();
    final server = serverDevice();
    final mine = <String, Object?>{..._one('owner_listing_sold'), 'status': 'published'};
    server.on('GET', 'listings/mine', 200, <String, Object?>{
      'success': true,
      'data': <Object?>[mine],
      'meta': <String, Object?>{'page': 1, 'per_page': 20, 'total': 1, 'last_page': 1},
    });
    final id = mine['id']! as int;
    server.on('POST', 'listings/$id/sold', 200, recorded('owner_listing_sold'));
    await serverSignIn(server, account: me(id: 41, role: 'owner', name: 'عبدالرحمن الدوسري'));
    await pumpApp(tester, '/account/listings');
    await _settle(tester);

    await tapText(tester, 'مباع', last: true);
    await _settle(tester);
    expect(find.textContaining('اكتب سعر البيع الفعلي'), findsOneWidget);
    final price = find.byType(EditableText); // the dialog's is the last
    expect(tester.widget<EditableText>(price.last).controller.text, '2000000', reason: 'the listed price offered first');
    await tester.enterText(price.last, '0');
    await tapText(tester, 'تم البيع');
    await tester.pump();
    expect(find.text('سعر البيع أكبر من صفر'), findsOneWidget);
    await tester.enterText(price.last, '1850000.505');
    await tapText(tester, 'تم البيع');
    await tester.pump();
    expect(find.text('سعر البيع رقم بخانتين عشريتين على الأكثر'), findsOneWidget);
    expect(server.sent('POST', 'listings/$id/sold'), isEmpty, reason: 'nothing sent before a valid price');

    await tester.enterText(price.last, '١٨٥٠٠٠٠');
    await tapText(tester, 'تم البيع');
    await _settle(tester);
    expect(FakeServer.body(server.sent('POST', 'listings/$id/sold').single), <String, Object?>{'sale_price': '1850000'});
  });
}
