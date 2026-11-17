// «البحث الدلالي» with a server (lib/data/server/server_ai.dart; search_view.dart, match_kit.dart): what he chose and
// wrote is what is sent; the results are the server's, in its order — the app ranks nothing and adds no wait —;
// the page says when the order is not a model's and when the server's AI is the simulation; «مطابق» is sent at
// once and «غير مطابق» with its reason, each once, beside what was asked; the plan's refusals are shown as the
// server says them; an answer to a form that changed is not shown; and nothing of another account stays.
// The server's answers are the ones its tests recorded from the whole chain (docs/contracts/fixtures/api:
// ai_search, ai_search_requests — backend/tests/Chain —, ai_quota_exceeded, ai_summary_needs_plan).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/shared/widgets/match_kit.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

/// The recorded answer, said to have been obtained as [ai].
Map<String, Object?> _as(String name, Map<String, Object?> ai) => <String, Object?>{
  ...recorded(name),
  'meta': <String, Object?>{'ai': ai},
};

/// The numbers of a recorded answer's results, in its order.
List<String> _order(String name, String of, String prefix) => <String>[
  for (final row in ((recorded(name)['data']! as Map)['results']! as List).cast<Map<Object?, Object?>>()) '$prefix${(row[of]! as Map)['id']}',
];

Future<void> _settle(WidgetTester tester, [int times = 8]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

/// The page's choices, as the selects give them: 0 buy or rent · 1 city · 2 neighbourhood · 3 type.
Future<void> _choose(WidgetTester tester, int which, String value) async {
  tester.widgetList<SelectBox>(find.byType(SelectBox)).elementAt(which).onChanged(value);
  await tester.pump();
}

Future<void> _form(WidgetTester tester, {String type = 'apartment', String text = ''}) async {
  await _choose(tester, 0, 'sale');
  await _choose(tester, 1, 'الرياض');
  await _choose(tester, 2, 'النرجس');
  await _choose(tester, 3, type);
  if (text.isNotEmpty) {
    await tester.enterText(find.byType(EditableText).first, text);
    await tester.pump();
  }
}

Future<void> _search(WidgetTester tester) async {
  final button = find.widgetWithText(AppButton, 'بحث');
  await Scrollable.ensureVisible(tester.element(button), alignment: 0.5);
  await tester.pump();
  await tester.tap(button, warnIfMissed: false);
  await _settle(tester);
}

/// The results on the page, in its order.
List<String> _shown(WidgetTester tester) => <String>[
  for (final w in tester.widgetList<FolioMini>(find.byType(FolioMini))) (w.key! as ValueKey<String>).value.substring(2),
];

Future<FakeServer> _open(WidgetTester tester, void Function(FakeServer) stubs, {String role = 'seeker'}) async {
  await freshDevice();
  final server = serverDevice();
  stubs(server);
  server.on('POST', 'ai/feedback', 201, ok());
  await serverSignIn(server, account: me(role: role));
  await pumpApp(tester, '/account/search');
  await _settle(tester);
  return server;
}

void main() {
  const need = <String, Object?>{
    'purpose': 'sale',
    'city': 'الرياض',
    'neighborhood': 'النرجس',
    'property_type': 'apartment',
    'query': 'مدخل خاص ومصعد',
  };
  const simulated = 'محاكاة: الترتيب بقواعد ثابتة، لا بنموذج ذكاء.';
  const notRanked = 'تعذّر ترتيب الذكاء الآن، فهذه النتائج بترتيب قاعدة البيانات.';
  final offers = _order('ai_search', 'listing', 'L');

  group('«البحث الدلالي» with a server', () {
    testWidgets('what he chose and wrote is sent; the server\'s results in its order, at once; the simulation said', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search', 200, recorded('ai_search')));
      expect(find.text('فعّل الخدمة'), findsNothing, reason: 'the plan is the server\'s to say, not the device\'s');
      await _form(tester, text: '  مدخل خاص ومصعد ');
      await _search(tester);

      expect(FakeServer.body(server.sent('POST', 'ai/search').single), need);
      expect(offers, hasLength(6));
      expect(_shown(tester), offers, reason: 'the server\'s order (0.4 s after asking: no wait added)');
      expect(find.text(simulated), findsOneWidget, reason: 'the recorded chain is the simulation, and the page says so');
      expect(find.text(notRanked), findsNothing);
      expect(
        find.textContaining('البحث الجاي أدق'),
        findsNothing,
        reason: 'a server keeps the marks for review; it promises nothing about the next search',
      );
    });

    testWidgets('nothing written: no query sent; a model\'s order carries no note; the database\'s order is said', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search', 200, _as('ai_search', <String, Object?>{'status': 'ok'})));
      await _form(tester);
      await _search(tester);
      expect(FakeServer.body(server.sent('POST', 'ai/search').single), <String, Object?>{...need}..remove('query'));
      expect(_shown(tester), offers);
      expect(find.byType(AiNote), findsOneWidget);
      expect(find.text(simulated), findsNothing);
      expect(find.text(notRanked), findsNothing);

      server.on('POST', 'ai/search', 200, _as('ai_search', <String, Object?>{'status': 'degraded'}));
      await _search(tester);
      expect(_shown(tester), offers);
      expect(find.text(notRanked), findsOneWidget);
    });

    testWidgets('«مطابق» is sent at once and once; «غير مطابق» with its reason; each beside what was asked', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search', 200, recorded('ai_search')));
      await _form(tester, text: 'مدخل خاص ومصعد');
      await _search(tester);

      await tapText(tester, 'مطابق');
      await _settle(tester);
      final first = int.parse(offers[0].substring(1));
      expect(FakeServer.body(server.sent('POST', 'ai/feedback').single), <String, Object?>{
        'context': 'search',
        'result_type': 'listing',
        'result_id': first,
        'is_match': true,
        'query': need,
      });
      await tapText(tester, 'غير مطابق');
      await tapText(tester, 'مطابق');
      await _settle(tester);
      expect(server.sent('POST', 'ai/feedback'), hasLength(1), reason: 'a result is rated once: the server keeps the mark as it was sent');

      await tapText(tester, 'غير مطابق', index: 1);
      expect(server.sent('POST', 'ai/feedback'), hasLength(1), reason: 'not sent before its reason');
      expect(find.text('اكتب السبب'), findsOneWidget);
      await tester.enterText(find.byType(EditableText).last, 'بدون مصعد');
      await tester.pump();
      await tapText(tester, 'حفظ السبب');
      await _settle(tester);
      expect(server.sent('POST', 'ai/feedback'), hasLength(2));
      expect(FakeServer.body(server.sent('POST', 'ai/feedback').last), <String, Object?>{
        'context': 'search',
        'result_type': 'listing',
        'result_id': int.parse(offers[1].substring(1)),
        'is_match': false,
        'reason': 'بدون مصعد',
        'query': need,
      });
    });

    testWidgets('a mark the server did not take is said, and can be sent again', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search', 200, recorded('ai_search')));
      server.offline('POST', 'ai/feedback');
      await _form(tester);
      await _search(tester);
      await tapText(tester, 'مطابق');
      await _settle(tester);
      expect(find.byType(FormError), findsOneWidget);

      server.on('POST', 'ai/feedback', 201, ok());
      await tapText(tester, 'مطابق');
      await _settle(tester);
      expect(server.sent('POST', 'ai/feedback'), hasLength(2));
      expect(find.byType(FormError), findsNothing);
    });

    testWidgets('a broker\'s «بحث طلبات»: the requests the server found, in its order, rated as requests', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search/requests', 200, recorded('ai_search_requests')), role: 'broker');
      await tapText(tester, 'بحث طلبات');
      await _form(tester, text: 'عندي شقة فيها مصعد وموقف خاص');
      await _search(tester);

      final asked = <String, Object?>{...need, 'query': 'عندي شقة فيها مصعد وموقف خاص'};
      expect(server.sent('POST', 'ai/search'), isEmpty);
      expect(FakeServer.body(server.sent('POST', 'ai/search/requests').single), asked);
      final requests = _order('ai_search_requests', 'request', 'R');
      expect(requests, hasLength(3));
      expect(_shown(tester), requests);

      await tapText(tester, 'مطابق');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'ai/feedback').single), <String, Object?>{
        'context': 'requests',
        'result_type': 'request',
        'result_id': int.parse(requests[0].substring(1)),
        'is_match': true,
        'query': asked,
      });
    });

    testWidgets('outside his plan: the plans; today\'s share used up: the server\'s words; no answer: said, nothing shown', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search', 429, recorded('ai_quota_exceeded')));
      await _form(tester);
      await _search(tester);
      expect(find.text('تجاوزت الحد المسموح، حاول بعد التجديد.'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'الباقات'), findsOneWidget);
      expect(find.byType(FolioMini), findsNothing);

      server.offline('POST', 'ai/search');
      await _search(tester);
      expect(find.text('تجاوزت الحد المسموح، حاول بعد التجديد.'), findsNothing);
      expect(find.byType(FormError), findsOneWidget);
      expect(find.byType(FolioMini), findsNothing, reason: 'no result made up on the device');
      expect(find.text('لا نتائج في هذه المدينة والحي والنوع.'), findsNothing, reason: 'no answer is not «no results»');

      server.on('POST', 'ai/search', 402, recorded('ai_summary_needs_plan'));
      await _search(tester);
      expect(find.text('فعّل الخدمة'), findsOneWidget);
      expect(find.byType(SelectBox), findsNothing);
    });

    testWidgets('buy or rent is chosen before anything is searched: never assumed', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search', 200, recorded('ai_search')));
      await _choose(tester, 1, 'الرياض');
      await _choose(tester, 2, 'النرجس');
      await _choose(tester, 3, 'apartment');
      expect(tester.widget<AppButton>(find.widgetWithText(AppButton, 'بحث')).onTap, isNull);

      await _choose(tester, 0, 'rent');
      await _search(tester);
      expect(FakeServer.body(server.sent('POST', 'ai/search').single)['purpose'], 'rent');
    });

    testWidgets('an answer to what the form no longer asks is not shown under it', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search', 200, recorded('ai_search')));
      final late = server.hold('POST', 'ai/search');
      await _form(tester);
      await _search(tester);
      await _choose(tester, 3, 'villa');
      late.complete();
      await _settle(tester);
      expect(find.byType(FolioMini), findsNothing);
      expect(find.widgetWithText(AppButton, 'بحث'), findsOneWidget, reason: 'the search ended');
    });

    testWidgets('another account signed in: nothing searched or rated before stays', (tester) async {
      final server = await _open(tester, (s) => s.on('POST', 'ai/search', 200, recorded('ai_search')));
      await _form(tester, text: 'مدخل خاص ومصعد');
      await _search(tester);
      expect(_shown(tester), offers);

      await serverSignIn(
        server,
        account: me(id: 42, role: 'seeker', name: 'حساب آخر'),
        token: '7|second',
      );
      await _settle(tester);
      expect(find.byType(FolioMini), findsNothing);
      expect(find.text('مدخل خاص ومصعد'), findsNothing);
      expect(tester.widgetList<SelectBox>(find.byType(SelectBox)).map((s) => s.value), <String>['', '', '', '']);
    });
  });
}
