// «مساعد المطابقة» with a server (lib/data/server/server_ai.dart; matching_view.dart): the wish's results are the
// server's three lists in its order — asked once the wish is known and again when it is saved, nothing ranked on the
// phone —; the simulation and the database's order are said; marks go as «matching»; the plan's refusals are shown;
// a developer markets only a published project of his and sees the seekers and brokers the server found, each
// reached by a conversation; and nothing of another account stays.
// The answers are the ones the server's tests recorded from the whole chain (docs/contracts/fixtures/api:
// ai_matching, ai_project_searchers, project_brokers — backend/tests/Chain —, ai_quota_exceeded).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/shared/widgets/listing_card.dart';
import 'package:zon/shared/widgets/match_kit.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

List<Map<String, Object?>> _rows(Object? v) => <Map<String, Object?>>[for (final r in v! as List) (r as Map).cast<String, Object?>()];

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

/// The offers on the page, in its order.
List<String> _shown(WidgetTester tester) => <String>[
  for (final w in tester.widgetList<FolioMini>(find.byType(FolioMini))) (w.key! as ValueKey<String>).value.substring(2),
];

List<String> _ids(String list) => <String>[for (final r in _rows(_one('ai_matching')[list])) 'L${(r['listing']! as Map)['id']}'];

const simulated = 'محاكاة: الترتيب بقواعد ثابتة، لا بنموذج ذكاء.';

void main() {
  group('a seeker\'s «مساعد المطابقة» with a server', () {
    Future<FakeServer> open(WidgetTester tester, void Function(FakeServer) stubs) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'seeker'));
      server.on('GET', 'preferences', 200, ok(<Object?>[_one('preference')]));
      server.on('POST', 'ai/feedback', 201, ok());
      stubs(server);
      await pumpApp(tester, '/account/matching');
      await _settle(tester);
      return server;
    }

    testWidgets('the wish known: the server\'s three lists in its order, at once; the simulation said; marks go as «matching»', (tester) async {
      final server = await open(tester, (s) => s.on('POST', 'ai/matching', 200, recorded('ai_matching')));
      final matched = _ids('matched');
      final broaden = _ids('broaden');
      final project = (_rows(_one('ai_matching')['projects']).single['project']! as Map).cast<String, Object?>();
      final unit = ((_rows(_rows(_one('ai_matching')['projects']).single['units']).single)['unit']! as Map).cast<String, Object?>();

      expect(server.sent('POST', 'ai/matching'), hasLength(1), reason: 'the wish is the one the server keeps');
      expect(matched, hasLength(6));
      expect(_shown(tester), matched);
      expect(find.text('عروض مطابقة · 6'), findsOneWidget);
      expect(find.text('عروض أخرى · 1'), findsOneWidget);
      expect(find.text('مشاريع · 1'), findsOneWidget);
      expect(find.text(simulated), findsOneWidget);

      await tapText(tester, 'مطابق');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'ai/feedback').single), <String, Object?>{
        'context': 'matching',
        'result_type': 'listing',
        'result_id': int.parse(matched.first.substring(1)),
        'is_match': true,
      });

      await tapText(tester, 'عروض أخرى · 1');
      expect(_shown(tester), broaden);

      await tapText(tester, 'مشاريع · 1');
      expect(find.byType(FolioMini), findsNothing);
      expect(find.byKey(ValueKey<String>('m-P${project['id']}')), findsOneWidget);
      expect(find.textContaining('نموذج ${unit['model_name']}'), findsOneWidget);
      expect(find.textContaining('870,000 ريال'), findsOneWidget, reason: 'the model\'s price as the server gave it');
      expect(server.sent('POST', 'ai/matching'), hasLength(1), reason: 'asked once');
    });

    testWidgets('a saved wish is asked about again; only the last answer is shown', (tester) async {
      final id = _one('preference')['id'];
      final server = await open(tester, (s) {
        s.on('POST', 'ai/matching', 200, recorded('ai_matching'));
        s.on('PUT', 'preferences/$id', 200, ok(<String, Object?>{..._one('preference'), 'notes': 'فيلا قرب الحديقة'}));
      });
      final one = <String, Object?>{
        ..._one('ai_matching'),
        'matched': <Object?>[_rows(_one('ai_matching')['matched']).last],
        'broaden': <Object?>[],
        'projects': <Object?>[],
      };
      server.on('POST', 'ai/matching', 200, ok(one, <String, Object?>{'ai': <String, Object?>{'status': 'degraded'}}));
      await tapText(tester, 'تعديل الرغبة');
      await tester.enterText(find.byType(EditableText).last, 'فيلا قرب الحديقة');
      await tester.pump();
      await tapText(tester, 'حفظ الرغبة');
      await _settle(tester);

      expect(server.sent('POST', 'ai/matching'), hasLength(2));
      expect(_shown(tester), <String>[_ids('matched').last]);
      expect(find.text('تعذّر ترتيب الذكاء الآن، فهذه النتائج بترتيب قاعدة البيانات.'), findsOneWidget);
      expect(find.text(simulated), findsNothing);
    });

    testWidgets('nothing fits: a request is suggested; today\'s share used up: the server\'s words; no answer: said', (tester) async {
      final none = <String, Object?>{'matched': <Object?>[], 'broaden': <Object?>[], 'projects': <Object?>[], 'suggest_request': true};
      final server = await open(tester, (s) => s.on('POST', 'ai/matching', 200, ok(none, <String, Object?>{'ai': <String, Object?>{'status': 'skipped'}})));
      expect(find.text('لا عروض تطابق رغبتك الآن. انشر طلباً ليصل إلى أصحاب العروض.'), findsOneWidget);
      expect(find.byType(FolioMini), findsNothing);
      await tapText(tester, 'طلباتي');
      await _settle(tester);
      expect(_path(), '/account/requests');

      server.on('POST', 'ai/matching', 429, recorded('ai_quota_exceeded'));
      await pumpApp(tester, '/account/matching');
      await _settle(tester);
      expect(find.text('تجاوزت الحد المسموح، حاول بعد التجديد.'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'الباقات'), findsOneWidget);
      expect(find.textContaining('عروض مطابقة'), findsNothing, reason: 'no answer: no list, not an empty one');

      server.offline('POST', 'ai/matching');
      await pumpApp(tester, '/login');
      await pumpApp(tester, '/account/matching');
      await _settle(tester);
      expect(find.byType(FormError), findsOneWidget);
      expect(find.textContaining('عروض مطابقة'), findsNothing);
      expect(find.byType(FolioMini), findsNothing, reason: 'nothing ranked on the phone');
    });

    testWidgets('another account signed in: the results of the one before do not stay', (tester) async {
      final server = await open(tester, (s) => s.on('POST', 'ai/matching', 200, recorded('ai_matching')));
      expect(_shown(tester), _ids('matched'));
      server.onFor('7|second', 'GET', 'preferences', 200, ok(<Object?>[]));
      await serverSignIn(server, account: me(id: 42, role: 'seeker', name: 'حساب آخر'), token: '7|second');
      await _settle(tester);
      expect(find.byType(FolioMini), findsNothing);
      expect(find.textContaining('عروض مطابقة'), findsNothing);
      expect(find.text('حفظ الرغبة'), findsOneWidget);
    });
  });

  group('a developer\'s «تسويق المشاريع» with a server', () {
    final published = _one('project_published');
    final draft = <String, Object?>{..._rows(recorded('projects_mine')['data']).single, 'id': 2, 'title': 'مشروع لم يُنشر'};
    final seekers = _rows(recorded('ai_project_searchers')['data']);
    final broker = (_rows(recorded('project_brokers')['data']).single['broker']! as Map).cast<String, Object?>();

    Future<FakeServer> open(WidgetTester tester, void Function(FakeServer) stubs) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'developer'));
      server.on('GET', 'preferences', 200, ok(<Object?>[]));
      server.on('GET', 'projects/mine', 200, <String, Object?>{...recorded('projects_mine'), 'data': <Object?>[published, draft]});
      stubs(server);
      await pumpApp(tester, '/account/matching');
      await _settle(tester);
      await tapText(tester, 'تسويق المشاريع');
      await _settle(tester);
      return server;
    }

    Future<void> pick(WidgetTester tester) async {
      tester.widget<ListingCard>(find.byType(ListingCard)).onSelect!();
      await _settle(tester);
    }

    testWidgets('only his published project; its seekers in the server\'s order with the closest model; its brokers by their clients', (tester) async {
      final server = await open(tester, (s) {
        s.on('POST', 'ai/matching/projects/${published['id']}/searchers', 200, recorded('ai_project_searchers'));
        s.on('GET', 'ai/matching/projects/${published['id']}/brokers', 200, recorded('project_brokers'));
      });
      expect(find.byType(ListingCard), findsOneWidget, reason: 'a draft is not marketed');
      expect(find.text('مشروع لم يُنشر'), findsNothing);
      await pick(tester);

      expect(server.sent('POST', 'ai/matching/projects/${published['id']}/searchers'), hasLength(1));
      final names = <String>[for (final s in seekers) '${(s['seeker']! as Map)['name']}'];
      expect(names, hasLength(2));
      final at = <double>[for (final n in names) tester.getTopLeft(find.text(n)).dy];
      expect(at[0], lessThan(at[1]), reason: 'the server\'s order');
      for (final s in seekers) {
        expect(find.textContaining('الأقرب: نموذج ${(s['unit']! as Map)['model_name']}'), findsOneWidget);
        expect(find.text('${(s['wish']! as Map)['notes']}'), findsOneWidget, reason: 'what he wants, as the server masked it');
      }
      expect(find.text(simulated), findsOneWidget);
      expect(find.text('إرسال المشروع للكل'), findsNothing, reason: 'the server sends nothing to all: not offered');
      expect(find.text('سارة القحطاني'), findsNothing, reason: 'no sample person');

      await tapText(tester, 'عملاء وسطاء');
      expect(find.text('${broker['name']}'), findsOneWidget);
      expect(find.text('لديه 2 عملاء مطابقون — تواصل مع الوسيط'), findsOneWidget);

      await tester.tap(
        find.descendant(of: find.byKey(ValueKey<String>('b-${broker['username']}')), matching: find.byType(Tap)).last,
        warnIfMissed: false,
      );
      await _settle(tester);
      expect(_path(), startsWith('/chats/new?'));
      expect(_path(), contains('to=${broker['username']}'));
    });

    testWidgets('outside his plan: the plans, and no person shown; another account: nothing stays', (tester) async {
      final server = await open(tester, (s) {
        s.on('POST', 'ai/matching/projects/${published['id']}/searchers', 402, recorded('ai_summary_needs_plan'));
        s.on('GET', 'ai/matching/projects/${published['id']}/brokers', 200, ok(<Object?>[]));
      });
      await pick(tester);
      expect(find.text('تسويق المشاريع ضمن الباقات.'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'الباقات'), findsOneWidget);
      await tapText(tester, 'عملاء وسطاء');
      expect(find.text('لا وسيط في مدينة المشروع لديه عملاء مطابقون الآن.'), findsOneWidget);

      server.onFor('7|second', 'GET', 'projects/mine', 200, <String, Object?>{...recorded('projects_mine'), 'data': <Object?>[]});
      await serverSignIn(server, account: me(id: 42, role: 'developer', name: 'حساب آخر'), token: '7|second');
      await _settle(tester);
      expect(find.text('تسويق المشاريع ضمن الباقات.'), findsNothing);
      expect(find.byType(ListingCard), findsNothing);
    });
  });
}
