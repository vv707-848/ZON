// The account's credentials and policies on the server (lib/data/auth/server_auth.dart, login_screen.dart,
// settings_view.dart, legal_screen.dart): «نسيت كلمة السر» with a code and a new password; a new number that
// takes effect only with the code sent to it; «كلمة السر» with the current one; the policies as published —
// and nothing of one account left for the next. The server's answers are the ones its tests recorded
// (docs/contracts/fixtures/api: password_code_sent, password_reset, phone_code_sent, phone_changed,
// password_changed, legal_pages, legal_page).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/data/server/server_legal.dart';
import 'package:zon/data/store.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Future<void> _settle(WidgetTester tester, [int times = 8]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

String _path() {
  String? name;
  Nav.key.currentState!.popUntil((route) {
    name = route.settings.name;
    return true;
  });
  return name ?? '';
}

Finder get _fields => find.byType(EditableText);

void main() {
  final otpInvalid = apiFail('OTP_INVALID', 'رمز التحقق غير صحيح أو انتهت صلاحيته.');

  group('«نسيت كلمة السر»', () {
    testWidgets('the phone, then its code and a new password — and the page says nothing of whether it has an account', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('POST', 'auth/password/forgot', 200, recorded('password_code_sent'));
      server.on('POST', 'auth/password/reset', 422, otpInvalid);
      await pumpApp(tester, '/login');
      await tapText(tester, 'تسجيل الدخول');
      await tapText(tester, 'نسيت كلمة السر؟');

      await tester.enterText(_fields.first, '0551234');
      await tapText(tester, 'أرسل الرمز');
      await _settle(tester);
      expect(find.text('رقم الجوال غير صحيح — مثال: 05XXXXXXXX'), findsOneWidget);
      expect(server.sent('POST', 'auth/password/forgot'), isEmpty);

      await tester.enterText(_fields.first, '0551234567');
      await tapText(tester, 'أرسل الرمز');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'auth/password/forgot').single), <String, Object?>{'phone': '0551234567'});
      expect(find.text('إن كان 0551234567 لحساب في زون فقد أرسلنا إليه رمزاً'), findsOneWidget);

      await tester.enterText(_fields.at(0), '123456');
      await tester.enterText(_fields.at(1), 'short');
      await tapText(tester, 'تغيير كلمة السر');
      await _settle(tester);
      expect(find.text('كلمة السر 8 أحرف على الأقل'), findsOneWidget);
      expect(server.sent('POST', 'auth/password/reset'), isEmpty);

      await tester.enterText(_fields.at(1), 'a-new-secret');
      await tapText(tester, 'تغيير كلمة السر');
      await _settle(tester);
      expect(find.text('رمز التحقق غير صحيح أو انتهت صلاحيته.'), findsOneWidget);

      server.on('POST', 'auth/password/reset', 200, recorded('password_reset'));
      await tester.enterText(_fields.at(0), '654321');
      await tapText(tester, 'تغيير كلمة السر');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'auth/password/reset').last), <String, Object?>{
        'phone': '0551234567',
        'code': '654321',
        'password': 'a-new-secret',
      });
      expect(find.text('تغيّرت كلمة السر'), findsOneWidget);
      expect(store.readSession(), isNull, reason: 'it signs nobody in: he signs in with the new password');
    });

    testWidgets('the code again goes to the same number', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('POST', 'auth/password/forgot', 200, recorded('password_code_sent'));
      await pumpApp(tester, '/login');
      await tapText(tester, 'تسجيل الدخول');
      await tapText(tester, 'نسيت كلمة السر؟');
      await tester.enterText(_fields.first, '0551234567');
      await tapText(tester, 'أرسل الرمز');
      await _settle(tester);
      await tapText(tester, 'لم يصلك الرمز؟ أعد الإرسال');
      await _settle(tester);
      final sent = server.sent('POST', 'auth/password/forgot');
      expect(sent, hasLength(2));
      expect(FakeServer.body(sent.last), <String, Object?>{'phone': '0551234567'});
      expect(find.text('أرسلنا رمزاً جديداً.'), findsOneWidget);
    });
  });

  group('a new number', () {
    Future<FakeServer> editPhone(WidgetTester tester, String phone) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server);
      server.on('PATCH', 'me', 200, ok(me()));
      server.on('POST', 'me/phone', 202, recorded('phone_code_sent'));
      await pumpApp(tester, '/account/settings');
      await tapText(tester, 'اضغط للتعديل');
      await tester.enterText(_fields.at(1), phone);
      await tapText(tester, 'حفظ');
      await _settle(tester);
      return server;
    }

    testWidgets('takes effect only with the code sent to it; until then the account keeps its own', (tester) async {
      final server = await editPhone(tester, '0558888888');
      expect(FakeServer.body(server.sent('POST', 'me/phone').single), <String, Object?>{'phone': '0558888888'});
      expect(store.readSession()!.phone, '0551234567', reason: 'not changed by asking for a code');
      expect(find.text('أرسلنا رمزاً إلى 0558888888. يبقى رقم حسابك 0551234567 حتى تكتب الرمز.'), findsOneWidget);

      server.on('POST', 'me/phone/verify', 422, otpInvalid);
      await tester.enterText(_fields.last, '111111');
      await tapText(tester, 'تأكيد الرقم');
      await _settle(tester);
      expect(find.text('رمز التحقق غير صحيح أو انتهت صلاحيته.'), findsOneWidget);
      expect(store.readSession()!.phone, '0551234567');

      // the recorded answer, as his own account (the server answers with the account that asked)
      final changed = (recorded('phone_changed')['data']! as Map).cast<String, Object?>();
      server.on('POST', 'me/phone/verify', 200, ok(<String, Object?>{...changed, 'id': 41}));
      await tester.enterText(_fields.last, '222222');
      await tapText(tester, 'تأكيد الرقم');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'me/phone/verify').last), <String, Object?>{'phone': '0558888888', 'code': '222222'});
      expect(store.readSession()!.phone, '0558888888', reason: 'the number as the server now has it');
      expect(find.text('تغيّر رقم جوالك.'), findsOneWidget);
    });

    testWidgets('a number taken by another account: said, and no code asked for', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server);
      server.on('PATCH', 'me', 200, ok(me()));
      server.on('POST', 'me/phone', 422, apiFail('VALIDATION_FAILED', 'رقم الجوال مستخدم من قبل.'));
      await pumpApp(tester, '/account/settings');
      await tapText(tester, 'اضغط للتعديل');
      await tester.enterText(_fields.at(1), '0557777777');
      await tapText(tester, 'حفظ');
      await _settle(tester);
      expect(find.text('رقم الجوال مستخدم من قبل.'), findsOneWidget);
      expect(find.text('تأكيد الرقم الجديد'), findsNothing);
    });

    testWidgets('the name and the city: what he wrote is sent, the account is as the server answers, no code asked for', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server);
      server.offline('PATCH', 'me');
      await pumpApp(tester, '/account/settings');
      await tapText(tester, 'اضغط للتعديل');
      await tester.enterText(_fields.at(0), '  مكتب العتيبي ');
      await tapText(tester, 'حفظ');
      await _settle(tester);
      expect(find.textContaining('الإنترنت'), findsOneWidget);
      expect(store.readSession()!.name, 'فيصل العتيبي', reason: 'not saved: the account is unchanged');
      expect(find.text('حفظ'), findsOneWidget, reason: 'the form stays open with what he wrote');

      server.on('PATCH', 'me', 200, ok(me(name: 'مكتب العتيبي')));
      await tapText(tester, 'حفظ');
      await _settle(tester);
      expect(FakeServer.body(server.sent('PATCH', 'me').last), <String, Object?>{'name': 'مكتب العتيبي', 'city': 'الرياض'});
      expect(store.readSession()!.name, 'مكتب العتيبي');
      expect(server.sent('POST', 'me/phone'), isEmpty);
      expect(find.text('حفظ'), findsNothing);
    });

    testWidgets('the same number written another way is not a new one', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server);
      server.on('PATCH', 'me', 200, ok(me()));
      await pumpApp(tester, '/account/settings');
      await tapText(tester, 'اضغط للتعديل');
      await tester.enterText(_fields.at(1), '+966551234567');
      await tapText(tester, 'حفظ');
      await _settle(tester);
      expect(server.sent('POST', 'me/phone'), isEmpty);
      expect(server.sent('PATCH', 'me'), hasLength(1));
    });

    testWidgets('another account signed in while the code is awaited: nothing of the previous number stays', (tester) async {
      final server = await editPhone(tester, '0558888888');
      expect(find.text('تأكيد الرقم الجديد'), findsOneWidget);
      await serverSignIn(
        server,
        account: me(id: 42, name: 'مكتب آخر'),
        token: '7|second',
      );
      await _settle(tester);
      expect(find.text('تأكيد الرقم الجديد'), findsNothing);
      expect(find.textContaining('0558888888'), findsNothing);
    });
  });

  testWidgets('«كلمة السر»: the current one and the new; refused, said; changed, the other phones are out', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server);
    server.on('PUT', 'me/password', 422, apiFail('VALIDATION_FAILED', 'كلمة المرور غير صحيحة.'));
    await pumpApp(tester, '/account/settings');
    await tapText(tester, 'تغيير كلمة السر');
    await tester.enterText(_fields.at(0), 'wrong-one');
    await tester.enterText(_fields.at(1), 'short');
    await tapText(tester, 'تغيير');
    await _settle(tester);
    expect(find.text('كلمة السر 8 أحرف على الأقل'), findsOneWidget);
    expect(server.sent('PUT', 'me/password'), isEmpty);

    await tester.enterText(_fields.at(1), 'a-new-secret');
    await tapText(tester, 'تغيير');
    await _settle(tester);
    expect(find.text('كلمة المرور غير صحيحة.'), findsOneWidget);

    server.on('PUT', 'me/password', 200, recorded('password_changed'));
    await tester.enterText(_fields.at(0), 'a-good-secret');
    await tapText(tester, 'تغيير');
    await _settle(tester);
    expect(FakeServer.body(server.sent('PUT', 'me/password').last), <String, Object?>{
      'current_password': 'a-good-secret',
      'password': 'a-new-secret',
    });
    expect(find.text('تغيّرت كلمة السر، وخرج حسابك من أجهزتك الأخرى.'), findsOneWidget);
    expect(store.readSession(), isNotNull, reason: 'this phone stays signed in');
  });

  group('the policies', () {
    testWidgets('the published ones only, each opening the text as published with its version', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server);
      server.on('GET', 'legal', 200, recorded('legal_pages'));
      final page = (recorded('legal_page')['data']! as Map).cast<String, Object?>();
      server.on('GET', 'legal/privacy', 200, recorded('legal_page'));
      await pumpApp(tester, '/account/settings?tab=legal');
      await _settle(tester);
      expect(find.text('سياسة الخصوصية'), findsOneWidget);
      expect(find.text('أحكام وشروط استخدام المنصة'), findsNothing, reason: 'not published: no row');

      await tapText(tester, 'سياسة الخصوصية');
      await _settle(tester);
      expect(_path(), '/legal/privacy');
      expect(find.text(page['body']! as String), findsOneWidget);
      expect(find.textContaining('الإصدار ${page['version']}'), findsOneWidget);
    });

    testWidgets('a page not published: said, no text from the device', (tester) async {
      await freshDevice();
      serverDevice();
      await pumpApp(tester, '/legal/terms');
      await _settle(tester);
      expect(find.text('هذه الصفحة غير منشورة.'), findsOneWidget);
      expect(find.textContaining('استخدام زون يعني'), findsNothing);
    });

    test('the Markdown of the panel: headings, list items and paragraphs, never raw marks', () {
      final blocks = legalBlocks('## البيانات\n\nنجمع **الاسم** والجوال\nفقط.\n\n- لا نبيعها\n* ولا [نشاركها](https://zon.sa)\n1. أولاً');
      expect(blocks.map((b) => '${b.kind}:${b.text}').toList(), <String>[
        'h:البيانات',
        'p:نجمع الاسم والجوال فقط.',
        'li:لا نبيعها',
        'li:ولا نشاركها',
        'li:1. أولاً',
      ]);
    });
  });
}
