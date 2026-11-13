// «المحادثات» with a server (docs/api.md «المحادثات»):
//
//   GET  /conversations                      his conversations, newest first, with what is unread in each
//   POST /conversations                      the first message to an account (by username), about a listing, a
//                                            project or a request, or none: it opens the conversation — the same
//                                            one again for the same two and the same subject
//   GET  /conversations/{id}/messages        the newest 50; `before` an id → the 50 before it
//   POST /conversations/{id}/messages        a reply
//   POST /conversations/{id}/read            read up to the last message he was shown (`up_to`)
//
// A message is sent once however often tried (Idempotency-Key); nothing goes out once another account is signed
// in (Store.stillAccount). A block refuses with a general message: the blocked side is not told why.

import 'package:zon/core/net/api.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/store.dart';

/// One message as the server tells it.
class ServerMsg {
  const ServerMsg({required this.id, required this.body, required this.mine, this.at});

  factory ServerMsg.fromJson(Map<String, Object?> j) => ServerMsg(
    id: j['id'] is int ? j['id']! as int : 0,
    body: '${j['body'] ?? ''}',
    mine: j['mine'] == true,
    at: DateTime.tryParse('${j['at'] ?? ''}')?.toLocal(),
  );

  final int id;
  final String body;
  final bool mine;
  final DateTime? at;

  Msg toMsg() => Msg(me: mine, t: body);
}

/// A page of a conversation: its messages, oldest first, and whether older ones remain.
class MsgPage {
  const MsgPage(this.messages, {required this.hasMore});

  final List<ServerMsg> messages;
  final bool hasMore;
}

abstract final class ServerChats {
  /// The conversation's id in the app (`C<id>`), and back.
  static String appId(int id) => 'C$id';
  static int? rawId(String appId) => appId.startsWith('C') ? int.tryParse(appId.substring(1)) : null;

  /// His conversations, newest first (five pages of 30 at most).
  static Future<List<Chat>> list() async {
    final rows = <Chat>[];
    for (var page = 1; page <= 5; page++) {
      final r = await Api.client!.get('conversations', query: <String, String>{'page': '$page'});
      rows.addAll(<Chat>[for (final row in r.list) fromJson(row)]);
      final meta = r.meta['page'];
      final last = meta is Map && meta['last'] is int ? meta['last']! as int : page;
      if (last <= page) break;
    }
    return rows;
  }

  /// A conversation of the list as the app shows it.
  static Chat fromJson(Map<String, Object?> j) {
    final other = j['with'] is Map ? (j['with']! as Map).cast<String, Object?>() : const <String, Object?>{};
    final subject = j['subject'] is Map ? (j['subject']! as Map).cast<String, Object?>() : null;
    final last = j['last_message'] is Map ? (j['last_message']! as Map).cast<String, Object?>() : null;
    final sid = subject?['id'];
    final type = subject?['type'];
    return Chat(
      id: appId(j['id'] is int ? j['id']! as int : 0),
      name: '${other['name'] ?? ''}',
      role: '${other['role'] ?? 'seeker'}',
      username: other['username'] is String ? other['username']! as String : null,
      last: last == null ? '' : '${last['mine'] == true ? 'أنت: ' : ''}${last['body'] ?? ''}',
      listingId: sid is int && type == 'listing'
          ? ServerListings.listingId(sid)
          : (sid is int && type == 'project' ? ServerListings.projectId(sid) : null),
      requestId: sid is int && type == 'request' ? 'R$sid' : null,
      subjectTitle: subject?['title'] is String ? subject!['title']! as String : null,
      at: last == null ? null : _ago(DateTime.tryParse('${last['at'] ?? ''}')),
      unread: j['unread'] is int ? j['unread']! as int : 0,
      blocked: j['blocked'] == true,
    );
  }

  /// The newest messages of [conversation], or the 50 before [before].
  static Future<MsgPage> messages(int conversation, {int? before}) async {
    final r = await Api.client!.get('conversations/$conversation/messages', query: <String, String>{'before': ?before?.toString()});
    return MsgPage(<ServerMsg>[for (final row in r.list) ServerMsg.fromJson(row)], hasMore: r.meta['has_more'] == true);
  }

  /// The first message to [username] about [subject] (`L12` · `P3` · `R7`, or none): its conversation's id and
  /// the message.
  static Future<(int, ServerMsg)> open(String username, String body, {String? subject, required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final key = switch (subject?.substring(0, 1)) {
      'L' => 'listing_id',
      'P' => 'project_id',
      'R' => 'request_id',
      _ => null,
    };
    final id = subject == null || key == null ? null : int.tryParse(subject.substring(1));
    final r = await Api.client!.post('conversations', <String, Object?>{
      'to': username,
      'body': body,
      if (key != null && id != null) key: id,
    }, operation);
    final m = r.map['message'];
    return (
      r.map['conversation_id'] is int ? r.map['conversation_id']! as int : 0,
      ServerMsg.fromJson(m is Map ? m.cast<String, Object?>() : const <String, Object?>{}),
    );
  }

  static Future<ServerMsg> send(int conversation, String body, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    return ServerMsg.fromJson((await Api.client!.post('conversations/$conversation/messages', <String, Object?>{'body': body}, operation)).map);
  }

  /// Read up to [upTo], the last message he was shown.
  static Future<void> read(int conversation, int upTo) => Api.client!.post('conversations/$conversation/read', <String, Object?>{'up_to': upTo});

  /// Where «محادثة» with [username] about [about] goes: the conversation, or its first message.
  static String startPath(String username, {String? about, required String name, required String role}) =>
      Uri(path: '/chats/new', queryParameters: <String, String>{'to': username, 'about': ?about, 'name': name, 'role': role}).toString();

  /// «الآن» · «منذ 5 د» · «منذ 3 س» · «أمس» · the date.
  static String? _ago(DateTime? at) {
    if (at == null) return null;
    final d = DateTime.now().difference(at.toLocal());
    if (d.inMinutes < 1) return 'الآن';
    if (d.inHours < 1) return 'منذ ${d.inMinutes} د';
    if (d.inDays < 1) return 'منذ ${d.inHours} س';
    if (d.inDays < 2) return 'أمس';
    final l = at.toLocal();
    return '${l.year}/${l.month}/${l.day}';
  }
}
