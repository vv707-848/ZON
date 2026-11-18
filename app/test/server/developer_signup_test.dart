// A developer account is opened with Zon's team, never signed up for (the server refuses `role: developer` at
// POST /auth/register): «مطوّر» in the sign-up opens the WhatsApp number the panel set (`developer_whatsapp` of
// GET /app/config), and with none set sends the visitor to «فريق زون» — no form, no code.

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/app_gate.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> _config(String? whatsapp) => ok(<String, Object?>{'platform': null, 'developer_whatsapp': whatsapp});

void main() {
  final opened = <Uri>[];
  setUp(() {
    opened.clear();
    openOutside = (uri) async => opened.add(uri);
  });

  testWidgets('«مطوّر» opens WhatsApp to Zon\'s team, with no form and nothing sent', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'app/config', 200, _config('+966559998877'));
    await AppGate.check(Api.client!);

    await pumpApp(tester, '/login');
    await tapText(tester, 'حساب جديد');
    await tapText(tester, 'مطوّر عقاري');
    await tapText(tester, 'متابعة');

    expect(find.text('حساب المطوّر'), findsOneWidget);
    expect(find.text('تحقق من الجوال'), findsNothing, reason: 'no sign-up form for a developer');
    await tapText(tester, 'تواصل معنا عبر واتساب');
    expect(opened.single.host, 'wa.me');
    expect(opened.single.path, '/966559998877');
    expect(opened.single.queryParameters['text'], contains('مطوّر'));
    expect(server.sent('POST', 'auth/register'), isEmpty);
  });

  testWidgets('with no number set, the visitor is sent to «فريق زون», never to a made-up number', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'app/config', 200, _config(null));
    await AppGate.check(Api.client!);

    await pumpApp(tester, '/login');
    await tapText(tester, 'حساب جديد');
    await tapText(tester, 'مطوّر عقاري');
    await tapText(tester, 'متابعة');

    expect(find.text('تواصل معنا عبر واتساب'), findsNothing);
    expect(find.text('راسل فريق زون'), findsOneWidget);
    expect(opened, isEmpty);
  });
}
