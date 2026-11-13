// «مهامي» and «إدارة العملاء» with a server (lib/data/server/server_desk.dart; tasks_view.dart, clients_view.dart,
// client_kit.dart): his tasks and clients as the server keeps them — nothing sampled —, each change sent and shown
// once taken, a new one once however often tried, «تم» saying which, a note added and removed, «تلخيص» from the
// server's model (or the plans when his has it not), a client removed, and nothing of another account. The
// server's answers are the ones its tests recorded (docs/contracts/fixtures/api: tasks, task_created, task_updated,
// task_done, task_removed, clients, client, client_created, client_updated, client_notes, client_note_added,
// client_note_removed, client_removed, ai_summary, ai_summary_needs_plan).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

List<Map<String, Object?>> _rows(String name) => (recorded(name)['data']! as List).cast<Map<String, Object?>>();

Future<void> _settle(WidgetTester tester, [int times = 8]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Finder _semantic(String label) => find.byWidgetPredicate((w) => w is Semantics && w.properties.label == label);

Future<FakeServer> _open(WidgetTester tester, String path, void Function(FakeServer) stubs, {int id = 41}) async {
  await freshDevice();
  final server = serverDevice();
  stubs(server);
  await serverSignIn(server, account: me(id: id));
  await pumpApp(tester, path);
  await _settle(tester);
  return server;
}

void main() {
  group('«مهامي» with a server', () {
    final first = _rows('tasks').first;

    testWidgets('his tasks as the server keeps them, by priority — no sample task', (tester) async {
      await _open(tester, '/account/tasks', (s) => s.on('GET', 'tasks', 200, recorded('tasks')));
      for (final t in _rows('tasks')) {
        expect(find.text(t['title']! as String), findsOneWidget);
      }
      expect(find.text('عن فيلا النرجس'), findsOneWidget);
      expect(find.text('مهم'), findsWidgets);
      expect(find.textContaining('روح أكلم خالد'), findsNothing, reason: 'no sample task with a server');
    });

    testWidgets('a new task: sent once however often tried, shown once taken', (tester) async {
      final server = await _open(tester, '/account/tasks', (s) {
        s.on('GET', 'tasks', 200, <String, Object?>{'success': true, 'data': <Object?>[]});
        s.offline('POST', 'tasks');
      });
      await tapText(tester, 'مهمة جديدة');
      await tester.enterText(find.byType(EditableText).first, 'اتصال بسلمان');
      await tester.pump();
      await tapText(tester, 'حفظ المهمة');
      await _settle(tester);
      expect(find.text('اتصال بسلمان'), findsOneWidget, reason: 'still in the form');
      server.on('POST', 'tasks', 201, recorded('task_created'));
      await tapText(tester, 'حفظ المهمة');
      await _settle(tester);

      final sent = server.sent('POST', 'tasks');
      expect(sent, hasLength(2));
      expect(sent.first.headers['Idempotency-Key'], isNotNull);
      expect(sent.last.headers['Idempotency-Key'], sent.first.headers['Idempotency-Key'], reason: 'the same task tried again');
      expect(FakeServer.body(sent.last), <String, Object?>{'title': 'اتصال بسلمان', 'body': null, 'priority': 'high'});
      expect(find.text('حفظ المهمة'), findsNothing, reason: 'the form closes once taken');
      expect(find.text(_one('task_created')['title']! as String), findsOneWidget);
    });

    testWidgets('edited sends only what changed; removed after asking', (tester) async {
      final server = await _open(tester, '/account/tasks', (s) {
        s.on('GET', 'tasks', 200, <String, Object?>{
          'success': true,
          'data': <Object?>[_one('task_updated')],
        });
        s.on('PATCH', 'tasks/${_one('task_updated')['id']}', 200, recorded('task_updated'));
        s.on('DELETE', 'tasks/${_one('task_updated')['id']}', 200, recorded('task_removed'));
      });
      final t = _one('task_updated')['id'];
      await tapText(tester, 'تعديل');
      await _settle(tester);
      await tester.enterText(find.byType(EditableText).first, 'زيارة العقار مع العميل والمالك');
      await tester.pump();
      await tapText(tester, 'حفظ التعديل');
      await _settle(tester);
      expect(FakeServer.body(server.sent('PATCH', 'tasks/$t').single), <String, Object?>{
        'title': 'زيارة العقار مع العميل والمالك',
      }, reason: 'only what changed: a reminder not touched is not sent again');

      await tapText(tester, 'حذف');
      await tapText(tester, 'حذف', last: true);
      await _settle(tester);
      expect(server.sent('DELETE', 'tasks/$t'), hasLength(1));
      expect(find.text('زيارة العقار مع العميل'), findsNothing);
    });

    testWidgets('«تم» sends which it is: done, then not done', (tester) async {
      final server = await _open(tester, '/account/tasks', (s) {
        s.on('GET', 'tasks', 200, <String, Object?>{
          'success': true,
          'data': <Object?>[_one('task_updated')],
        });
        s.on('POST', 'tasks/${_one('task_updated')['id']}/done', 200, recorded('task_done'));
      });
      final t = _one('task_updated')['id'];
      await tester.tap(_semantic('إنجاز المهمة').first);
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'tasks/$t/done').single), <String, Object?>{'done': true});
      expect(find.text('منجزة'), findsOneWidget);

      server.on('POST', 'tasks/$t/done', 200, recorded('task_updated'));
      await tester.tap(_semantic('إعادة المهمة').first);
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'tasks/$t/done').last), <String, Object?>{'done': false});
      expect(find.text('منجزة'), findsNothing);
    });

    testWidgets('«لخّص يومي»: the server\'s model; without the plan, the plans', (tester) async {
      final server = await _open(tester, '/account/tasks', (s) {
        s.on('GET', 'tasks', 200, recorded('tasks'));
        s.on('POST', 'ai/summaries', 200, recorded('ai_summary'));
      });
      await tapText(tester, 'لخّص يومي بالذكاء');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'ai/summaries').single), <String, Object?>{'scope': 'tasks'});
      expect(find.textContaining(_one('ai_summary')['summary']! as String), findsOneWidget);
      expect(find.textContaining('ابدأ بـ'), findsNothing, reason: 'no simulated summary with a server');

      server.on('POST', 'ai/summaries', 402, recorded('ai_summary_needs_plan'));
      await tapText(tester, 'لخّص يومي بالذكاء');
      await _settle(tester);
      expect(find.text('تلخيص الذكاء ضمن الخدمة'), findsOneWidget);
    });

    testWidgets('another account signed in: its own tasks, nothing of the previous one', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.onFor('7|first', 'GET', 'tasks', 200, recorded('tasks'));
      server.onFor('7|second', 'GET', 'tasks', 200, <String, Object?>{'success': true, 'data': <Object?>[]});
      await serverSignIn(server, account: me(id: 41), token: '7|first');
      await pumpApp(tester, '/account/tasks');
      await _settle(tester);
      expect(find.text(first['title']! as String), findsOneWidget);
      await serverSignIn(
        server,
        account: me(id: 42, name: 'مكتب آخر'),
        token: '7|second',
      );
      await _settle(tester);
      expect(find.text(first['title']! as String), findsNothing);
      expect(server.sent('GET', 'tasks'), hasLength(2), reason: 'asked again for the next account');
    });
  });

  group('«إدارة العملاء» with a server', () {
    final client = _rows('clients').first;
    final cid = client['id'];

    Future<FakeServer> book(WidgetTester tester, [void Function(FakeServer)? more]) => _open(tester, '/account/clients', (s) {
      s.on('GET', 'clients', 200, recorded('clients'));
      s.on('GET', 'clients/$cid/notes', 200, recorded('client_notes'));
      more?.call(s);
    });

    testWidgets('his book as the server keeps it — his notes counted there — no sample client', (tester) async {
      await book(tester);
      expect(find.text(client['name']! as String), findsOneWidget);
      expect(find.text('فيلا · شقة · الرياض · النرجس · الياسمين'), findsOneWidget);
      expect(find.text('1 سجلات'), findsOneWidget);
      expect(find.text('فيصل الدوسري'), findsNothing, reason: 'no sample client with a server');
    });

    testWidgets('a new client: what the server keeps, nothing chosen for him, once however often tried', (tester) async {
      final server = await _open(tester, '/account/clients', (s) {
        s.on('GET', 'clients', 200, <String, Object?>{
          'success': true,
          'data': <Object?>[],
          'meta': <String, Object?>{
            'page': <String, Object?>{'current': 1, 'last': 1, 'total': 0},
          },
        });
        s.offline('POST', 'clients');
      });
      await tapText(tester, 'عميل جديد');
      await _settle(tester);
      expect(find.text('إلى'), findsOneWidget, reason: 'only the budget has a range: the server keeps the least area and rooms');
      final fields = find.byType(EditableText); // 0 is the book's search
      await tester.enterText(fields.at(1), 'سلمان العتيبي');
      await tester.enterText(fields.at(2), '0557776665');
      await tester.pump();
      await tapText(tester, 'حفظ العميل');
      await _settle(tester);
      server.on('POST', 'clients', 201, recorded('client_created'));
      server.on('GET', 'clients', 200, recorded('clients'));
      await tapText(tester, 'حفظ العميل');
      await _settle(tester);

      final sent = server.sent('POST', 'clients');
      expect(sent, hasLength(2));
      expect(sent.last.headers['Idempotency-Key'], sent.first.headers['Idempotency-Key']);
      expect(FakeServer.body(sent.last), <String, Object?>{
        'name': 'سلمان العتيبي',
        'phone': '0557776665',
        'purpose': 'sale',
        'city': 'الرياض',
        'neighborhoods': <Object?>[],
        'property_types': <Object?>[],
        'budget_min': null,
        'budget_max': null,
        'area_min': null,
        'rooms_min': null,
        'description': null,
      });
      expect(find.text(client['name']! as String), findsOneWidget, reason: 'his book asked again');
    });

    testWidgets('his page: a note added and removed on the server — kept as written, not edited', (tester) async {
      final server = await book(tester, (s) {
        s.on('POST', 'clients/$cid/notes', 201, recorded('client_note_added'));
        s.on('DELETE', 'clients/$cid/notes/${_rows('client_notes').first['id']}', 200, recorded('client_note_removed'));
      });
      await tapText(tester, client['name']! as String);
      await _settle(tester);
      final kept = _rows('client_notes').first;
      expect(find.text(kept['body']! as String), findsOneWidget);
      expect(find.text('قبول'), findsWidgets, reason: '«${kept['category']}» as the app names it');
      expect(_semantic('تعديل الملاحظة'), findsNothing, reason: 'the server keeps a note as written');

      await tester.enterText(find.byType(EditableText).last, 'يريد زيارة يوم الخميس');
      await tester.pump();
      await tapText(tester, 'حفظ');
      await _settle(tester);
      final added = server.sent('POST', 'clients/$cid/notes').single;
      expect(FakeServer.body(added), <String, Object?>{'category': 'followup', 'body': 'يريد زيارة يوم الخميس'});
      expect(added.headers['Idempotency-Key'], isNotNull);
      expect(find.text(_one('client_note_added')['body']! as String), findsOneWidget, reason: 'the note as the server kept it');

      await tester.tap(_semantic('حذف الملاحظة').last);
      await _settle(tester);
      expect(server.sent('DELETE', 'clients/$cid/notes/${kept['id']}'), hasLength(1));
      expect(find.text(kept['body']! as String), findsNothing);
    });

    testWidgets('«تلخيص»: the server\'s model of his notes; without the plan, «فعّل»', (tester) async {
      final server = await book(tester, (s) => s.on('POST', 'ai/summaries', 200, recorded('ai_summary')));
      await tapText(tester, client['name']! as String);
      await _settle(tester);
      await tapText(tester, 'تلخيص');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'ai/summaries').single), <String, Object?>{'scope': 'client', 'id': cid});
      expect(find.textContaining(_one('ai_summary')['summary']! as String), findsOneWidget);
      expect(find.textContaining('الخطوة التالية: ${_one('ai_summary')['recommended_action']}'), findsOneWidget);

      server.on('POST', 'ai/summaries', 402, recorded('ai_summary_needs_plan'));
      await tapText(tester, 'حدّث');
      await _settle(tester);
      expect(find.text('فعّل'), findsOneWidget);
    });

    testWidgets('a client removed after asking', (tester) async {
      final server = await book(tester, (s) => s.on('DELETE', 'clients/$cid', 200, recorded('client_removed')));
      await tapText(tester, client['name']! as String);
      await _settle(tester);
      await tapText(tester, 'حذف العميل');
      await tapText(tester, 'حذف', last: true);
      await _settle(tester);
      expect(server.sent('DELETE', 'clients/$cid'), hasLength(1));
      expect(find.text('حذف العميل'), findsNothing, reason: 'back to his book');
    });
  });
}
