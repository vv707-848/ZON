// «برق» with a server (lib/data/server/server_ai.dart; barq_chat_screen.dart): the earlier turns are the ones the
// server kept; a question is answered by the server only — with the documents its answer rests on, where it leads,
// and the word «محاكاة» when its AI is the simulation —; a question it did not answer goes back to the box with
// why, and nothing is answered on the phone; another account sees nothing of the one before.
// The answers are the ones the server's tests recorded from the whole chain (docs/contracts/fixtures/api: ai_chat,
// ai_chat_history — backend/tests/Chain —, ai_quota_exceeded).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/shared/widgets/talk.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

String _guidance(String lang) {
  final shared = jsonDecode(File('../docs/contracts/fixtures/chat/plans_guidance.json').readAsStringSync()) as Map;
  return shared[lang]! as String;
}

String _path() {
  String? name;
  Nav.key.currentState!.popUntil((route) {
    name = route.settings.name;
    return true;
  });
  return name ?? '';
}

Future<void> _settle(WidgetTester tester, [int times = 8]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Finder get _box => find.descendant(of: find.byType(TalkBar), matching: find.byType(EditableText));

Future<void> _say(WidgetTester tester, String text) async {
  await tester.enterText(_box, text);
  await tester.testTextInput.receiveAction(TextInputAction.go);
  await _settle(tester);
}

Future<FakeServer> _open(WidgetTester tester, void Function(FakeServer) stubs) async {
  await freshDevice();
  final server = serverDevice();
  await serverSignIn(server, account: me(role: 'seeker'));
  server.on('GET', 'ai/chat', 200, ok(<Object?>[]));
  stubs(server);
  await pumpApp(tester, '/chats/barq');
  await _settle(tester);
  return server;
}

void main() {
  final reply = (recorded('ai_chat')['data']! as Map).cast<String, Object?>();
  final source = ((reply['sources']! as List).single as Map).cast<String, Object?>();
  final turns = <Map<String, Object?>>[for (final t in recorded('ai_chat_history')['data']! as List) (t as Map).cast<String, Object?>()];
  const question = 'متى ينتهي الترخيص الإعلاني؟';
  const simulated = 'محاكاة: الإجابة بقواعد ثابتة، لا بنموذج ذكاء.';

  group('«برق» with a server', () {
    testWidgets('the turns the server kept, oldest first; none of the phone\'s sample questions', (tester) async {
      await _open(tester, (s) => s.on('GET', 'ai/chat', 200, recorded('ai_chat_history')));
      expect(turns, hasLength(3));
      for (final t in turns) {
        expect(find.text('${t['question']}'), findsOneWidget);
        expect(find.text('${t['answer']}'), findsOneWidget);
      }
      expect(tester.getTopLeft(find.text('${turns.first['question']}')).dy, lessThan(tester.getTopLeft(find.text('${turns.last['question']}')).dy));
      expect(find.text('اسأل مثلاً'), findsNothing);
      expect(find.text('What is Fal license in Saudi real estate?'), findsNothing);
    });

    testWidgets('a question is answered by the server: its answer, its document, the simulation said', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/chat', 200, recorded('ai_chat')));
      expect(find.text('أجيب من مكتبة زون عن الباقات والتراخيص والنشر والبحث والمطابقة.'), findsOneWidget);
      await _say(tester, '  $question ');

      expect(FakeServer.body(server.sent('POST', 'ai/chat').single), <String, Object?>{'message': question});
      expect(find.text(question), findsOneWidget);
      expect(find.text('${reply['answer']}'), findsOneWidget);
      expect(find.text('المصدر: ${source['title']} · ${source['effective_date']}'), findsOneWidget);
      expect(find.text(simulated), findsOneWidget);
      expect(find.text('برق يفكّر…'), findsNothing);
      expect(find.widgetWithText(AppButton, 'افتح'), findsNothing, reason: 'this answer leads nowhere');
    });

    testWidgets('an answer in his language carries the source\'s own Arabic words under it; «قد يخطئ زون» is said', (tester) async {
      const french = 'La licence publicitaire est obligatoire pour publier une annonce immobilière.';
      final server = await _open(
        tester,
        (s) => s.on('POST', 'ai/chat', 200, <String, Object?>{
          ...recorded('ai_chat'),
          'data': <String, Object?>{...reply, 'answer': french, 'lang': 'xx'},
        }),
      );
      expect(find.text('أجيب بلغتك. قد يخطئ زون؛ المرجع هو النص الرسمي المذكور تحت كل جواب.'), findsOneWidget);
      await _say(tester, 'Quand expire la licence publicitaire ?');

      expect(server.sent('POST', 'ai/chat'), hasLength(1));
      expect(find.text(french), findsOneWidget);
      expect(find.text('«${source['quote_ar']}»'), findsOneWidget, reason: 'the Arabic original under the translated answer');

      server.on('POST', 'ai/chat', 200, recorded('ai_chat'));
      await _say(tester, question);
      expect(find.text('«${source['quote_ar']}»'), findsNothing, reason: 'an Arabic answer is its own source text');
    });

    // Owner, 21 Nov 2026: no plan price in the chat. The server's pointer to the plans page, in the asker's language
    // (docs/contracts/fixtures/chat/plans_guidance.json), and its button opens the page itself, with today's prices.
    for (final lang in <String>['ar', 'tr']) {
      testWidgets('a plan question: the pointer in his language, and its button opens the plans page ($lang)', (tester) async {
        final guidance = _guidance(lang);
        const noSource = <Object?>[];
        const skipped = <String, Object?>{'status': 'skipped'};
        final plans = <String, Object?>{...reply, 'answer': guidance, 'sources': noSource, 'intent': 'packages', 'action': '/account/plans'};
        await _open(tester, (s) {
          s.on('POST', 'ai/chat', 200, ok(plans, <String, Object?>{'ai': skipped}));
          s.on('GET', 'packages', 200, recorded('packages'));
          s.on('GET', 'subscription', 200, recorded('subscription_free'));
          s.on('GET', 'invoices', 200, ok(<Object?>[]));
        });
        await _say(tester, lang == 'ar' ? 'كم سعر الباقة؟' : 'Arayan paketi aylık ne kadar?');
        expect(find.text(guidance), findsOneWidget);
        expect(find.textContaining('199'), findsNothing, reason: 'no price said in the chat');
        expect(find.text(simulated), findsNothing);
        expect(find.textContaining('المصدر:'), findsNothing);
        await tapText(tester, 'افتح');
        await _settle(tester);
        expect(_path(), '/account/plans');
        expect(find.text('الباحث — شهري'), findsOneWidget, reason: 'the plans page itself, with the server\'s plans');
      });
    }

    testWidgets('not answered: the question goes back to the box with why; nothing is answered on the phone', (tester) async {
      final server = await _open(tester, (s) => s.offline('POST', 'ai/chat'));
      await _say(tester, 'وش هي رخصة فال؟');
      expect(find.byType(FormError), findsOneWidget);
      expect(find.byType(TalkBubble), findsNothing, reason: 'not left looking sent, and no reply made up here');
      expect(tester.widget<EditableText>(_box).controller.text, 'وش هي رخصة فال؟');

      server.on('POST', 'ai/chat', 429, recorded('ai_quota_exceeded'));
      await _say(tester, 'وش هي رخصة فال؟');
      expect(find.text('تجاوزت الحد المسموح، حاول بعد التجديد.'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'الباقات'), findsOneWidget);
      expect(find.byType(TalkBubble), findsNothing);

      server.on('POST', 'ai/chat', 200, recorded('ai_chat'));
      await _say(tester, 'وش هي رخصة فال؟');
      expect(find.text('${reply['answer']}'), findsOneWidget);
      expect(find.text('تجاوزت الحد المسموح، حاول بعد التجديد.'), findsNothing);
      expect(server.sent('POST', 'ai/chat'), hasLength(3));
    });

    testWidgets('another account signed in: its own turns, nothing of the one before', (tester) async {
      final server = await _open(tester, (s) => s.on('GET', 'ai/chat', 200, recorded('ai_chat_history')));
      expect(find.text('${turns.first['question']}'), findsOneWidget);
      server.onFor('7|second', 'GET', 'ai/chat', 200, ok(<Object?>[]));
      await serverSignIn(
        server,
        account: me(id: 42, role: 'seeker', name: 'حساب آخر'),
        token: '7|second',
      );
      await _settle(tester);
      expect(find.byType(TalkBubble), findsNothing);
    });
  });
}
