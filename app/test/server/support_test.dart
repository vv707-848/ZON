// «الدعم» with a server (lib/data/server/server_support.dart; server_support_view.dart in «الإعدادات»,
// server_support_thread.dart): his tickets as the server keeps them, a new ticket sent once with its reference and
// the promised time as the server says them, the thread with «فريق زون», an answer sent once, a closed ticket and
// what was done, and nothing of another account. The server's answers are the ones its tests recorded
// (docs/contracts/fixtures/api: support_tickets, support_ticket, support_ticket_opened, support_ticket_replied).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/features/support/server_support_thread.dart';
import 'package:zon/shared/widgets/talk.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _data(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

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

Finder _semantic(String label) => find.byWidgetPredicate((w) => w is Semantics && w.properties.label == label);

/// Leaves the page: its timers stop.
Future<void> _leave(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox());
  await tester.pump(ServerSupportThread.every);
}

Finder get _bar => find.descendant(of: find.byType(TalkBar), matching: find.byType(EditableText));

void main() {
  final ticket = _data('support_ticket');
  final reference = ticket['reference']! as String;
  const home = '/account/settings?tab=support';
  final none = <String, Object?>{
    'success': true,
    'data': <Object?>[],
    'meta': <String, Object?>{
      'page': <String, Object?>{'current': 1, 'per_page': 30, 'total': 0, 'last': 1},
    },
  };

  Future<FakeServer> open(WidgetTester tester, String path, void Function(FakeServer) stubs) async {
    await freshDevice();
    final server = serverDevice();
    stubs(server);
    await serverSignIn(
      server,
      account: me(id: 41, role: 'seeker', name: 'سلمان القحطاني'),
    );
    await pumpApp(tester, path);
    await _settle(tester);
    return server;
  }

  group('«الدعم» in «الإعدادات»', () {
    testWidgets('his tickets as the server keeps them, what is unread — and no ticket said sent that was not', (tester) async {
      await open(tester, home, (s) => s.on('GET', 'support-tickets', 200, recorded('support_tickets')));
      expect(find.text(ticket['subject']! as String), findsOneWidget);
      expect(find.text('تم الرد'), findsOneWidget);
      expect(find.textContaining(reference), findsOneWidget);
      expect(find.text('فريق زون: تواصلنا مع المعلن.'), findsOneWidget);
      expect(_semantic('غير مقروءة'), findsOneWidget);
      expect(find.textContaining('يصدر رقم قيد'), findsNothing, reason: 'nothing said sent that the server did not take');
      expect(find.text('الجوال'), findsNothing, reason: 'his account is who wrote it: no name or number asked again');

      await tapText(tester, ticket['subject']! as String);
      await _settle(tester);
      expect(_path(), '/support/$reference');
    });

    testWidgets('a new ticket: what he chose, sent once however often tried, its number and time as the server says', (tester) async {
      final server = await open(tester, home, (s) {
        s.on('GET', 'support-tickets', 200, none);
        s.offline('POST', 'support-tickets');
      });
      expect(find.text('لا تذاكر بعد.'), findsOneWidget);
      await tapText(tester, 'شكوى');
      await tester.enterText(find.byType(EditableText).at(0), 'إعلان مضلل');
      await tester.enterText(find.byType(EditableText).at(1), 'السعر في العرض غير صحيح');
      await tester.pump();
      await tapText(tester, 'إرسال');
      await _settle(tester);
      expect(find.text('السعر في العرض غير صحيح'), findsOneWidget, reason: 'not sent: his words stay');

      server.on('POST', 'support-tickets', 201, recorded('support_ticket_opened'));
      server.on('GET', 'support-tickets', 200, recorded('support_tickets'));
      await tapText(tester, 'إرسال');
      await _settle(tester);
      final sent = server.sent('POST', 'support-tickets');
      expect(sent, hasLength(2));
      expect(sent.first.headers['Idempotency-Key'], isNotNull);
      expect(sent.last.headers['Idempotency-Key'], sent.first.headers['Idempotency-Key'], reason: 'the same ticket tried again');
      expect(FakeServer.body(sent.last), <String, Object?>{'type': 'complaint', 'subject': 'إعلان مضلل', 'body': 'السعر في العرض غير صحيح'});
      final opened = _data('support_ticket_opened');
      expect(find.text('وصلت تذكرتك رقم ${opened['reference']}، ويرد فريق زون خلال ${opened['response_hours']} ساعة.'), findsOneWidget);
      expect(find.text('السعر في العرض غير صحيح'), findsNothing, reason: 'the form is empty for the next one');
      expect(server.sent('GET', 'support-tickets'), hasLength(2), reason: 'his tickets asked again: the new one at their head');
      expect(find.text(ticket['subject']! as String), findsOneWidget);
    });

    testWidgets('a subject left empty is not sent, and too short a ticket is not sent at all', (tester) async {
      final server = await open(tester, home, (s) {
        s.on('GET', 'support-tickets', 200, none);
        s.on('POST', 'support-tickets', 201, recorded('support_ticket_opened'));
      });
      await tester.enterText(find.byType(EditableText).at(1), 'سؤال');
      await tester.pump();
      await tapText(tester, 'إرسال');
      await _settle(tester);
      expect(server.sent('POST', 'support-tickets'), isEmpty);
      expect(find.text('اكتب تفاصيل التذكرة (5 أحرف على الأقل).'), findsOneWidget);

      await tester.enterText(find.byType(EditableText).at(1), 'كيف أرقّي باقتي؟');
      await tester.pump();
      await tapText(tester, 'إرسال');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'support-tickets').single), <String, Object?>{'type': 'question', 'body': 'كيف أرقّي باقتي؟'});
    });

    testWidgets('another account signed in: its own tickets, and nothing of what the previous one was typing', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.onFor('7|first', 'GET', 'support-tickets', 200, recorded('support_tickets'));
      server.onFor('7|second', 'GET', 'support-tickets', 200, none);
      await serverSignIn(server, account: me(id: 41), token: '7|first');
      await pumpApp(tester, home);
      await _settle(tester);
      expect(find.text(ticket['subject']! as String), findsOneWidget);
      await tester.enterText(find.byType(EditableText).at(1), 'رقم جوالي 0551234567');
      await tester.pump();

      await serverSignIn(
        server,
        account: me(id: 42, name: 'مكتب آخر'),
        token: '7|second',
      );
      await _settle(tester);
      expect(find.text(ticket['subject']! as String), findsNothing);
      expect(find.text('رقم جوالي 0551234567'), findsNothing);
      expect(server.sent('GET', 'support-tickets'), hasLength(2), reason: 'asked again for the next account');
    });
  });

  testWidgets('a visitor: from «حسابي», his name and number with it, its number — and no list to follow', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('POST', 'support-tickets', 201, recorded('support_ticket_opened'));
    await pumpApp(tester, '/account');
    await _settle(tester);
    await tapText(tester, 'تواصل مع الدعم');
    await _settle(tester);
    expect(_path(), '/support');

    final fields = find.byType(EditableText);
    await tester.enterText(fields.at(0), 'سلمان');
    await tester.enterText(fields.at(1), '055 123 4567');
    await tester.enterText(fields.at(3), 'كيف أضيف عقاري؟');
    await tester.pump();
    await tapText(tester, 'إرسال');
    await _settle(tester);
    expect(FakeServer.body(server.sent('POST', 'support-tickets').single), <String, Object?>{
      'type': 'question',
      'body': 'كيف أضيف عقاري؟',
      'name': 'سلمان',
      'phone': '0551234567',
    });
    expect(find.textContaining('وصلت تذكرتك رقم ${_data('support_ticket_opened')['reference']}'), findsOneWidget);
    expect(server.sent('GET', 'support-tickets'), isEmpty, reason: 'a visitor has no tickets to list');
    expect(find.text('تذاكري'), findsNothing);
  });

  testWidgets('a visitor without a number: said, nothing sent', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await pumpApp(tester, '/support');
    await _settle(tester);
    final fields = find.byType(EditableText);
    await tester.enterText(fields.at(0), 'سلمان');
    await tester.enterText(fields.at(1), '12345');
    await tester.enterText(fields.at(3), 'كيف أضيف عقاري؟');
    await tester.pump();
    await tapText(tester, 'إرسال');
    await _settle(tester);
    expect(find.text('أدخل رقم جوال سعودي صحيح يبدأ بـ 05.'), findsOneWidget);
    expect(server.sent('POST', 'support-tickets'), isEmpty);
  });

  group('a support thread', () {
    final messages = (ticket['messages']! as List).cast<Map<String, Object?>>();

    testWidgets('every message, «فريق زون» never a name; what it is about opens', (tester) async {
      await open(tester, '/support/$reference', (s) => s.on('GET', 'support-tickets/$reference', 200, recorded('support_ticket')));
      expect(find.text('فريق زون'), findsOneWidget);
      for (final m in messages) {
        expect(find.text(m['body']! as String), findsOneWidget);
      }
      final about = ticket['about']! as Map;
      await tapText(tester, 'بخصوص: ${about['title']}');
      await _settle(tester);
      expect(_path(), '/listings/${about['id']}');
      await _leave(tester);
    });

    testWidgets('his answer: sent once, shown as the server keeps the thread', (tester) async {
      final server = await open(tester, '/support/$reference', (s) {
        s.on('GET', 'support-tickets/$reference', 200, recorded('support_ticket'));
        s.offline('POST', 'support-tickets/$reference/messages');
      });
      await tester.enterText(_bar, 'شكراً');
      await tester.testTextInput.receiveAction(TextInputAction.go);
      await _settle(tester);
      expect(find.text('شكراً'), findsOneWidget, reason: 'not sent: his text back in the bar');

      server.on('POST', 'support-tickets/$reference/messages', 200, recorded('support_ticket_replied'));
      await tester.testTextInput.receiveAction(TextInputAction.go);
      await _settle(tester);
      final sent = server.sent('POST', 'support-tickets/$reference/messages');
      expect(sent, hasLength(2));
      expect(sent.last.headers['Idempotency-Key'], sent.first.headers['Idempotency-Key'], reason: 'the same answer tried again');
      expect(FakeServer.body(sent.last), <String, Object?>{'body': 'شكراً'});
      expect(find.text('شكراً'), findsOneWidget);
      expect(find.textContaining('مفتوحة'), findsOneWidget, reason: 'answered by him, it is open again');
      await _leave(tester);
    });

    testWidgets('closed: what was done under the thread, and that an answer opens it again', (tester) async {
      await open(
        tester,
        '/support/$reference',
        (s) => s.on('GET', 'support-tickets/$reference', 200, <String, Object?>{
          'success': true,
          'data': <String, Object?>{...ticket, 'status': 'closed', 'resolution': 'صُحّح السعر في العرض.'},
        }),
      );
      expect(find.text('أُغلقت التذكرة'), findsOneWidget);
      expect(find.text('صُحّح السعر في العرض.'), findsOneWidget);
      expect(find.text('إن بقي ما يحتاج متابعة فاكتب هنا، وتُفتح التذكرة من جديد.'), findsOneWidget);
      await _leave(tester);
    });

    testWidgets('another\'s ticket, or none: said, and nothing to write to', (tester) async {
      await open(tester, '/support/ZT-000000-XXXXX', (s) {});
      expect(find.text('العنصر غير موجود.'), findsOneWidget);
      expect(find.byType(TalkBar), findsNothing);
      await _leave(tester);
    });

    testWidgets('another account signed in under it: back to «الدعم», nothing of this thread', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.onFor('7|first', 'GET', 'support-tickets/$reference', 200, recorded('support_ticket'));
      server.on('GET', 'support-tickets', 200, none);
      await serverSignIn(server, account: me(id: 41), token: '7|first');
      await pumpApp(tester, '/support/$reference');
      await _settle(tester);
      expect(find.text(messages.first['body']! as String), findsOneWidget);

      await serverSignIn(
        server,
        account: me(id: 42, name: 'مكتب آخر'),
        token: '7|second',
      );
      await _settle(tester);
      expect(_path(), home);
      expect(find.text(messages.first['body']! as String), findsNothing);
      await _leave(tester);
    });
  });
}
