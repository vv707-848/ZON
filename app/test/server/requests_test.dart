// Search requests with a server (lib/data/server/server_requests.dart, requests_board.dart; the requests screens):
// the board, a request, a new one, «طلباتي» — each replaying what the server's own tests recorded
// (docs/contracts/fixtures/api: requests, request, request_created, requests_mine, requests_area).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/requests_board.dart';
import 'package:zon/data/server/server_requests.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/ask_card.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

/// The server's recorded answer [name] (docs/contracts/fixtures/api/[name].json).
Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

List<Map<String, Object?>> _rows(Map<String, Object?> answer) => <Map<String, Object?>>[
  for (final r in answer['data']! as List) (r as Map).cast<String, Object?>(),
];

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

/// [answer] with each row changed by [change].
Map<String, Object?> _each(Map<String, Object?> answer, Map<String, Object?> Function(Map<String, Object?> row) change) => <String, Object?>{
  ...answer,
  'data': <Object?>[for (final r in _rows(answer)) change(r)],
};

/// The name of a recorded request's author (the server's factories name people at random).
String _authorOf(Map<String, Object?> row) => (row['author']! as Map)['name']! as String;

/// A request of [name] answered as a viewer who may not see its author.
Map<String, Object?> _hidden(Map<String, Object?> row) => <String, Object?>{...row, 'author': null, 'can_add_to_clients': false};

/// The route on top.
String _path() {
  String? name;
  Nav.key.currentState!.popUntil((route) {
    name = route.settings.name;
    return true;
  });
  return name ?? '';
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 6; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

void main() {
  group('the recorded answers, read as the app reads them', () {
    test('the board: ids, the author as the server shows him, an empty budget empty', () {
      final rows = _rows(recorded('requests')).map(ServerRequests.fromJson).toList();
      expect(rows.first.id, 'R3');
      expect(rows.first.urgent, isTrue);
      expect(rows.first.author, _authorOf(_rows(recorded('requests')).first));
      expect(rows.first.author, isNotEmpty);
      expect(rows.first.role, 'owner');
      expect(
        rows.first.ownerId,
        'srv-${(_rows(recorded('requests')).first['author']! as Map)['id']}',
        reason: 'his account: what makes it «mine» for him',
      );
      expect(rows.first.priceMin, 2000000);
      expect(rows.first.closed, isFalse);
      final unpriced = rows.firstWhere((r) => r.description == 'أرض تجارية على شارعين');
      expect(<double>[unpriced.priceMin, unpriced.priceMax], <double>[0, 0], reason: 'no budget: nothing invented');

      final hidden = ServerRequests.fromJson(_hidden(_rows(recorded('requests')).first));
      expect(hidden.author, isEmpty);
      expect(hidden.ownerId, isNull);
      expect(hidden.canAddToClients, isFalse);
    });

    test('«طلباتي»: a closed one stays, closed; the area: what the plan opens', () {
      final mine = ServerRequests.fromJson(_rows(recorded('requests_mine')).single);
      expect(mine.closed, isTrue);
      expect(mine.neighborhoods, <String>['الملقا']);
      expect(mine.types, <String>['apartment']);
      expect(mine.roomsMin, 3);

      final area = _rows(recorded('requests_area')).map(ServerRequests.fromJson).toList();
      expect(area.first.canAddToClients, isTrue);
      expect((recorded('requests_area')['meta']! as Map)['access'], 'full');
    });

    test('the form as the server reads it: an empty number is no number, not zero', () {
      const draft = RequestItem(
        id: 'r-1',
        purpose: 'rent',
        city: 'الرياض',
        neighborhoods: <String>['الملقا'],
        types: <String>['apartment'],
        priceMin: 0,
        priceMax: 90000,
        areaMin: 0,
        areaMax: 0,
        roomsMin: 2,
        roomsMax: 0,
        description: 'شقة عوائل',
        author: 'فيصل',
        role: 'seeker',
      );
      expect(ServerRequests.form(draft), <String, Object?>{
        'purpose': 'rent',
        'city': 'الرياض',
        'neighborhoods': <String>['الملقا'],
        'property_types': <String>['apartment'],
        'budget_min': null,
        'budget_max': 90000,
        'area_min': null,
        'area_max': null,
        'rooms_min': 2,
        'rooms_max': null,
        'description': 'شقة عوائل',
      });
      expect(ServerRequests.riyals('50.00'), '50');
      expect(ServerRequests.riyals('49.50'), '49.50');
    });
  });

  group('every page, and only for the account that asked', () {
    test('a broker\'s area on two pages comes whole (21 requests)', () async {
      await freshDevice();
      final server = serverDevice();
      final row = _rows(recorded('requests_area')).first;
      Map<String, Object?> page(int current, Iterable<int> ids) => <String, Object?>{
        'data': <Object?>[for (final id in ids) <String, Object?>{...row, 'id': id}],
        'meta': <String, Object?>{
          'access': 'full',
          'page': <String, Object?>{'current': current, 'per_page': 20, 'total': 21, 'last': 2},
        },
      };
      server.onQuery('GET', 'requests/area', <String, String>{'page': '1'}, 200, page(1, <int>[for (var i = 1; i <= 20; i++) i]));
      server.onQuery('GET', 'requests/area', <String, String>{'page': '2'}, 200, page(2, <int>[21]));

      final area = await ServerRequests.area();
      expect(area.items, hasLength(21));
      expect(area.items.last.id, 'R21');
      expect(area.access, 'full');
      expect(server.sent('GET', 'requests/area'), hasLength(2));
    });

    test('an answer that comes after signing out is not kept', () async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      server.on('GET', 'requests', 200, recorded('requests'));
      final late = server.hold('GET', 'requests');
      final board = RequestsBoard();
      final loading = board.show(const RequestQuery());

      await ServerAuth.signOut();
      late.complete();
      await loading;

      expect(board.items, isEmpty, reason: 'its authors were shown as the signed-in plan let them be');
      expect(store.allRequests(), isEmpty);
      board.dispose();
    });

    test('an answer for one account is not kept for the next, and the board asks again for him', () async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      server.on('GET', 'requests', 200, recorded('requests'));
      final late = server.hold('GET', 'requests');
      final board = RequestsBoard();
      final loading = board.show(const RequestQuery());

      await serverSignIn(server, account: me(id: 77, name: 'سارة القحطاني'), token: '8|other-token');
      late.complete();
      await loading;
      expect(board.items, isEmpty);
      expect(store.allRequests(), isEmpty);
      expect(board.stale, isTrue, reason: 'the screen asks again, as him');

      await board.show(const RequestQuery());
      expect(board.items, hasLength(_rows(recorded('requests')).length));
      expect(server.sent('GET', 'requests').last.headers['Authorization'], 'Bearer 8|other-token');
      board.dispose();
    });

    testWidgets('a request open when the account changes is fetched again for the new one', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'requests/3', 200, recorded('request'));
      await serverSignIn(server, account: me(role: 'owner'));
      await pumpApp(tester, '/requests/3');
      await _settle(tester);
      expect(find.text(_authorOf(_one('request'))), findsOneWidget);

      server.on('GET', 'requests/3', 200, ok(_hidden(_one('request'))));
      await ServerAuth.signOut();
      await _settle(tester);

      expect(server.sent('GET', 'requests/3'), hasLength(2));
      expect(find.text(_authorOf(_one('request'))), findsNothing, reason: 'a guest does not see who posted it');
    });
  });

  group('«الطلبات» with a server', () {
    testWidgets('shows the server\'s board, asks again for a filter, and nothing of the samples', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'requests', 200, recorded('requests'));

      await pumpApp(tester, '/requests');
      await _settle(tester);

      expect(find.byType(AskCard), findsNWidgets(_rows(recorded('requests')).length));
      expect(find.text('أرض تجارية على شارعين'), findsOneWidget);
      expect(server.sent('GET', 'requests').last.url.queryParameters, <String, String>{'sort': 'new', 'page': '1', 'per_page': '20'});

      final before = server.sent('GET', 'requests').length;
      await tapText(tester, 'عاجلة');
      await _settle(tester);
      expect(server.sent('GET', 'requests'), hasLength(before + 1));
      expect(server.sent('GET', 'requests').last.url.queryParameters, <String, String>{'sort': 'urgent', 'page': '1', 'per_page': '20'});

      await tapText(tester, 'وسيط');
      await _settle(tester);
      expect(server.sent('GET', 'requests').last.url.queryParameters['role'], 'broker');
    });

    testWidgets('without a connection it says so, and the retry brings the board', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.offline('GET', 'requests');
      await pumpApp(tester, '/requests');
      await _settle(tester);

      expect(find.text('تعذّر تحميل الطلبات'), findsOneWidget);
      expect(find.byType(AskCard), findsNothing);

      server.on('GET', 'requests', 200, recorded('requests'));
      await tapText(tester, 'أعد المحاولة');
      await _settle(tester);
      expect(find.byType(AskCard), findsWidgets);
    });
  });

  group('a request of the server', () {
    testWidgets('opened from a link (/requests/3 is R3), fetched fresh, its author shown as the server allows', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'requests/3', 200, recorded('request'));
      await serverSignIn(server, account: me(role: 'owner'));

      await pumpApp(tester, '/requests/3');
      await _settle(tester);

      expect(server.sent('GET', 'requests/3'), hasLength(1));
      expect(find.text(_authorOf(_one('request'))), findsOneWidget);
      expect(find.text('أضف إلى إدارة العملاء'), findsNothing);
    });

    testWidgets('a broker without the service sees no author and is offered the plans', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'requests/3', 200, ok(_hidden(_one('request'))));
      await serverSignIn(server);

      await pumpApp(tester, '/requests/3');
      await _settle(tester);

      expect(find.text(_authorOf(_one('request'))), findsNothing);
      expect(find.text('فعّل الخدمة لفتح التواصل'), findsOneWidget);
    });

    testWidgets('«أضف إلى إدارة العملاء» is the server\'s', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'requests/3', 200, ok(<String, Object?>{..._one('request'), 'can_add_to_clients': true}));
      server.on('POST', 'requests/3/to-client', 201, ok(<String, Object?>{'client_id': 12}));
      await serverSignIn(server);

      await pumpApp(tester, '/requests/3');
      await _settle(tester);
      await tapText(tester, 'أضف إلى إدارة العملاء');
      await _settle(tester);

      expect(server.sent('POST', 'requests/3/to-client'), hasLength(1));
      expect(find.text('أُضيف إلى العملاء'), findsOneWidget);
      expect(store.clients().where((c) => c.id == 'c-R3'), isEmpty, reason: 'no copy kept on this phone');
    });

    testWidgets('one the server no longer shows says so', (tester) async {
      await freshDevice();
      serverDevice();
      await pumpApp(tester, '/requests/99');
      await _settle(tester);
      expect(find.text('الطلب غير موجود.'), findsOneWidget);
    });
  });

  group('a new request', () {
    Future<void> fill(WidgetTester tester) async {
      final description = find.byType(EditableText).last;
      await tester.ensureVisible(description);
      await tester.enterText(description, 'شقة عوائل قرب المدارس');
      await tester.pump();
    }

    testWidgets('is published on the server, then opened', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      server.on('POST', 'requests', 201, <String, Object?>{...recorded('request_created'), 'meta': <String, Object?>{}});
      final id = _one('request_created')['id'];
      server.on('GET', 'requests/$id', 200, recorded('request_created'));

      await pumpApp(tester, '/add?type=request');
      await fill(tester);
      await tapText(tester, 'نشر الطلب');
      await _settle(tester);

      final body = FakeServer.body(server.sent('POST', 'requests').single);
      expect(body['description'], 'شقة عوائل قرب المدارس');
      expect(body['urgent'], isFalse);
      expect(body['budget_min'], isNull, reason: 'left empty, not zero');
      expect(_path(), '/requests/R$id');
      expect(store.allRequests().where((r) => !r.id.startsWith('R')), isEmpty, reason: 'nothing kept on this phone');
    });

    testWidgets('urgent without balance: published normal, and said so with its price', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      server.on('POST', 'requests', 201, recorded('request_created'));
      final id = _one('request_created')['id'];
      server.on('GET', 'requests/$id', 200, recorded('request_created'));

      await pumpApp(tester, '/add?type=request');
      await fill(tester);
      await tapText(tester, 'طلب عاجل');
      await tapText(tester, 'نشر الطلب العاجل');
      await _settle(tester);

      expect(FakeServer.body(server.sent('POST', 'requests').single)['urgent'], isTrue);
      expect(find.text('نُشر طلبك عاديًا'), findsOneWidget);
      expect(find.textContaining('سعر «عاجل» 50 ريال'), findsOneWidget);
      await tapText(tester, 'حسناً');
      await _settle(tester);
      expect(_path(), '/requests/R$id');
    });

    testWidgets('tried again after a lost answer, it is the same operation (the server publishes it once)', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      server.offline('POST', 'requests'); // sent, and its answer lost
      final id = _one('request_created')['id'];
      server.on('GET', 'requests/$id', 200, recorded('request_created'));

      await pumpApp(tester, '/add?type=request');
      await fill(tester);
      await tapText(tester, 'نشر الطلب');
      await _settle(tester);
      expect(_path(), startsWith('/add'));

      server.on('POST', 'requests', 201, <String, Object?>{...recorded('request_created'), 'meta': <String, Object?>{}});
      await tapText(tester, 'نشر الطلب');
      await _settle(tester);

      final keys = <String?>[for (final r in server.sent('POST', 'requests')) r.headers['Idempotency-Key']];
      expect(keys, hasLength(2));
      expect(keys.first, matches(RegExp(r'^[A-Za-z0-9_-]{8,100}$')));
      expect(keys.last, keys.first);
      expect(_path(), '/requests/R$id');
    });

    testWidgets('changed before it is sent again, it is another operation', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      server.offline('POST', 'requests');

      await pumpApp(tester, '/add?type=request');
      await fill(tester);
      await tapText(tester, 'نشر الطلب');
      await _settle(tester);
      await tester.enterText(find.byType(EditableText).last, 'شقة عوائل قرب المدارس والمستشفى');
      await tester.pump();
      await tapText(tester, 'نشر الطلب');
      await _settle(tester);

      final keys = <String?>[for (final r in server.sent('POST', 'requests')) r.headers['Idempotency-Key']];
      expect(keys, hasLength(2));
      expect(keys.last, isNot(keys.first));
    });

    testWidgets('a refusal of the server stays on the form with its words', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      server.on(
        'POST',
        'requests',
        422,
        apiFail(
          'VALIDATION_FAILED',
          'البيانات غير صحيحة.',
          details: <String, Object?>{
            'fields': <String, Object?>{
              'neighborhoods': <String>['حي غير معروف.'],
            },
          },
        ),
      );

      await pumpApp(tester, '/add?type=request');
      await fill(tester);
      await tapText(tester, 'نشر الطلب');
      await _settle(tester);

      expect(find.text('حي غير معروف.'), findsOneWidget);
      expect(_path(), startsWith('/add'));
    });
  });

  group('«طلباتي» with a server', () {
    Map<String, Object?> open(Map<String, Object?> row) => <String, Object?>{
      ...row,
      'status': 'published',
      'author': <String, Object?>{...(row['author']! as Map).cast<String, Object?>(), 'id': 41},
    };

    testWidgets('shows mine from the server; closing one keeps it here, closed', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      final mineAnswer = _each(recorded('requests_mine'), open);
      server.on('GET', 'requests/mine', 200, mineAnswer);
      final row = _rows(mineAnswer).single;
      server.on('POST', 'requests/${row['id']}/close', 200, ok(<String, Object?>{...row, 'status': 'closed'}));

      await pumpApp(tester, '/account/requests');
      await _settle(tester);
      expect(find.byType(AskCard), findsOneWidget);
      expect(find.text('شقة عوائل قرب المدارس'), findsOneWidget);

      await tapText(tester, 'أغلق الطلب');
      await _settle(tester);
      await tapText(tester, 'أغلق الطلب', last: true); // the dialog's button
      await _settle(tester);

      expect(server.sent('POST', 'requests/${row['id']}/close'), hasLength(1));
      expect(find.text('طلب مغلق — لا يظهر في «الطلبات»'), findsOneWidget);
      expect(find.text('تعديل الطلب'), findsNothing);
    });

    testWidgets('«عاجل» without balance: nothing changes, and the price is said', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      final mineAnswer = _each(recorded('requests_mine'), open);
      server.on('GET', 'requests/mine', 200, mineAnswer);
      final id = _rows(mineAnswer).single['id'];
      server.on(
        'POST',
        'requests/$id/urgent',
        402,
        apiFail(
          'PAYMENT_REQUIRED',
          'يتطلب دفعًا.',
          details: <String, Object?>{
            'purpose': 'urgent_request',
            'price': '50.00',
            'target': <String, Object?>{'type': 'request', 'id': id},
          },
        ),
      );

      await pumpApp(tester, '/account/requests');
      await _settle(tester);
      await tapText(tester, 'اجعله طلبًا عاجلًا');
      await _settle(tester);

      expect(server.sent('POST', 'requests/$id/urgent'), hasLength(1));
      expect(find.text('لا رصيد للطلبات العاجلة'), findsOneWidget);
      expect(find.textContaining('سعر «عاجل» 50 ريال'), findsOneWidget);
      expect(store.requestById('R$id')!.urgent, isFalse);
    });

    testWidgets('without a connection it says so', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'owner'));
      server.offline('GET', 'requests/mine');

      await pumpApp(tester, '/account/requests');
      await _settle(tester);
      expect(find.text('تعذّر تحميل طلباتك'), findsOneWidget);
    });
  });
}
