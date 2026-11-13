// «المحادثات» with a server (lib/data/server/server_chats.dart; chats_screen.dart, server_chat_thread.dart,
// advertiser_card.dart): the list with what is unread and what each is about, the thread and its older page,
// the read mark up to what he was shown, a reply sent once, a block, and the first message from a listing —
// or the conversation they already have. The server's answers are the ones its tests recorded
// (docs/contracts/fixtures/api: conversations, messages, messages_older, message_sent, conversation_opened, listing).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/features/chats/server_chat_thread.dart';
import 'package:zon/shared/widgets/talk.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _first(String name) => ((recorded(name)['data']! as List).first as Map).cast<String, Object?>();

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

/// Leaves the page: its timers stop.
Future<void> _leave(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox());
  await tester.pump(ServerChatThread.every);
}

void main() {
  final chat = _first('conversations');
  final id = chat['id']! as int;
  final other = (chat['with']! as Map)['name']! as String;
  final none = <String, Object?>{
    'success': true,
    'data': <Object?>[],
    'meta': <String, Object?>{
      'page': <String, Object?>{'current': 1, 'per_page': 30, 'total': 0, 'last': 1},
    },
  };

  Future<FakeServer> signedIn(WidgetTester tester, String path, void Function(FakeServer) stubs) async {
    await freshDevice();
    final server = serverDevice();
    stubs(server);
    await serverSignIn(server, account: me(id: 41, role: 'broker', name: 'مكتب الإتقان'));
    await pumpApp(tester, path);
    await _settle(tester);
    return server;
  }

  testWidgets('the list: with whom, what about, what is unread; a conversation opens', (tester) async {
    await signedIn(tester, '/chats', (s) => s.on('GET', 'conversations', 200, recorded('conversations')));
    expect(find.text(other), findsOneWidget);
    expect(find.text('بخصوص: ${(chat['subject']! as Map)['title']}'), findsOneWidget);
    expect(find.byWidgetPredicate((w) => w is Semantics && w.properties.label == '${chat['unread']} غير مقروءة'), findsOneWidget);
    await tapText(tester, other);
    await _settle(tester);
    expect(_path(), '/chats/C$id');
    await _leave(tester);
  });

  testWidgets('the thread: his messages, read up to what he was shown, a reply sent once', (tester) async {
    final server = await signedIn(tester, '/chats/C$id', (s) {
      s.on('GET', 'conversations', 200, recorded('conversations'));
      s.on('GET', 'conversations/$id/messages', 200, recorded('messages'));
      s.on('POST', 'conversations/$id/read', 200, <String, Object?>{'success': true, 'data': null});
    });
    final messages = (recorded('messages')['data']! as List).cast<Map<String, Object?>>();
    for (final m in messages) {
      expect(find.text(m['body']! as String), findsOneWidget);
    }
    expect(FakeServer.body(server.sent('POST', 'conversations/$id/read').single), <String, Object?>{'up_to': messages.last['id']});
    expect(find.byType(TalkBar), findsOneWidget);

    server.on('POST', 'conversations/$id/messages', 201, recorded('message_sent'));
    await tester.enterText(find.descendant(of: find.byType(TalkBar), matching: find.byType(EditableText)), 'متاح، تفضل بزيارة.');
    await tester.testTextInput.receiveAction(TextInputAction.go);
    await _settle(tester);
    final sent = server.sent('POST', 'conversations/$id/messages').single;
    expect(FakeServer.body(sent), <String, Object?>{'body': 'متاح، تفضل بزيارة.'});
    expect(sent.headers['Idempotency-Key'], isNotNull, reason: 'a reply sent once however often tried');
    expect(find.text('متاح، تفضل بزيارة.'), findsOneWidget);
    expect(find.textContaining('تم، أراجع وأردّك'), findsNothing, reason: "no simulated reply with a server");
    await _leave(tester);
  });

  testWidgets('a refused reply: said, and his text back in the bar', (tester) async {
    final server = await signedIn(tester, '/chats/C$id', (s) {
      s.on('GET', 'conversations', 200, recorded('conversations'));
      s.on('GET', 'conversations/$id/messages', 200, recorded('messages'));
      s.on('POST', 'conversations/$id/read', 200, <String, Object?>{'success': true, 'data': null});
    });
    server.on('POST', 'conversations/$id/messages', 403, <String, Object?>{
      'success': false,
      'error': <String, Object?>{'code': 'USER_BLOCKED', 'message': 'لا يمكن إرسال الرسالة.', 'details': <String, Object?>{}},
    });
    final bar = find.descendant(of: find.byType(TalkBar), matching: find.byType(EditableText));
    await tester.enterText(bar, 'هل وصلتك رسالتي؟');
    await tester.testTextInput.receiveAction(TextInputAction.go);
    await _settle(tester);
    expect(find.text('لا يمكن إرسال رسائل في هذه المحادثة.'), findsOneWidget);
    expect(tester.widget<EditableText>(bar).controller.text, 'هل وصلتك رسالتي؟');
    await _leave(tester);
  });

  testWidgets('older messages: the page before the first shown', (tester) async {
    final page = <String, Object?>{...recorded('messages'), 'meta': <String, Object?>{'has_more': true}};
    final server = await signedIn(tester, '/chats/C$id', (s) {
      s.on('GET', 'conversations', 200, recorded('conversations'));
      s.on('GET', 'conversations/$id/messages', 200, page);
      s.on('POST', 'conversations/$id/read', 200, <String, Object?>{'success': true, 'data': null});
    });
    final firstShown = ((recorded('messages')['data']! as List).first as Map)['id'];
    server.onQuery('GET', 'conversations/$id/messages', <String, String>{'before': '$firstShown'}, 200, recorded('messages_older'));
    await tapText(tester, 'رسائل أقدم');
    await _settle(tester);
    expect(server.sent('GET', 'conversations/$id/messages').where((r) => r.url.queryParameters['before'] == '$firstShown'), hasLength(1));
    expect(find.text('رسالة 1'), findsOneWidget);
    expect(find.text('رسائل أقدم'), findsNothing);
    await _leave(tester);
  });

  testWidgets("«محادثة» on a listing: its first message opens the conversation about it", (tester) async {
    final listing = (recorded('listing')['data']! as Map).cast<String, Object?>();
    final advertiser = (listing['advertiser']! as Map).cast<String, Object?>();
    final server = await signedIn(tester, '/listings/L${listing['id']}', (s) {
      s.on('GET', 'listings/${listing['id']}', 200, recorded('listing'));
      s.on('GET', 'conversations', 200, none);
    });
    await tapText(tester, 'محادثة');
    await _settle(tester);
    expect(_path(), startsWith('/chats/new?'));
    expect(find.text('اكتب رسالتك الأولى.'), findsOneWidget);

    server.on('POST', 'conversations', 201, recorded('conversation_opened'));
    server.on('GET', 'conversations/${_firstOpened()}/messages', 200, recorded('messages'));
    server.on('POST', 'conversations/${_firstOpened()}/read', 200, <String, Object?>{'success': true, 'data': null});
    await tester.enterText(find.descendant(of: find.byType(TalkBar), matching: find.byType(EditableText)), 'هل العرض متاح؟');
    await tester.testTextInput.receiveAction(TextInputAction.go);
    await _settle(tester);
    final opened = server.sent('POST', 'conversations').single;
    expect(FakeServer.body(opened), <String, Object?>{'to': advertiser['username'], 'body': 'هل العرض متاح؟', 'listing_id': listing['id']});
    expect(opened.headers['Idempotency-Key'], isNotNull);
    expect(_path(), '/chats/C${_firstOpened()}');
    await _leave(tester);
  });

  testWidgets('«محادثة» when they already talk about it: that conversation opens', (tester) async {
    final about = (chat['subject']! as Map)['id'];
    final username = (chat['with']! as Map)['username'];
    await signedIn(tester, '/chats/new?to=$username&about=L$about&name=x&role=seeker', (s) {
      s.on('GET', 'conversations', 200, recorded('conversations'));
      s.on('GET', 'conversations/$id/messages', 200, recorded('messages'));
      s.on('POST', 'conversations/$id/read', 200, <String, Object?>{'success': true, 'data': null});
    });
    expect(_path(), '/chats/C$id');
    await _leave(tester);
  });

  testWidgets("with a server, the phone's simulated conversations are not shown", (tester) async {
    await signedIn(tester, '/chats/some-name', (s) => s.on('GET', 'conversations', 200, none));
    expect(find.text('اكتب رسالتك الأولى.'), findsNothing);
    expect(find.textContaining('تم، أراجع وأردّك'), findsNothing);
    expect(find.text('لا صفقات بعد. المحادثة تُفتح من عرض أو طلب.'), findsOneWidget);
  });
}

int _firstOpened() => ((recorded('conversation_opened')['data']! as Map)['conversation_id']! as int);
