// The bell with a server (docs/api.md «الإشعارات»):
//
//   GET  /notifications        his notices, newest first, 30 a page; `meta.unread` counts what he has not read
//   POST /notifications/read   read: the ones named (`ids`), or all; answers how many remain unread
//
// A notice names where it leads (`target`) as the app's own path: `/listings/12`, `/chats/5`,
// `/account/listings/12/review`… (docs/contracts/fixtures/api/notice_targets.json, opened one by one in
// test/server/notices_test.dart).

import 'package:zon/core/net/api.dart';
import 'package:zon/data/models/notification.dart';

/// A page of notices, how many are unread in all, and whether older ones remain.
class NoticePage {
  const NoticePage(this.rows, {required this.unread, required this.hasMore});

  final List<AppNotification> rows;
  final int unread;
  final bool hasMore;
}

abstract final class ServerNotices {
  static Future<NoticePage> page({int page = 1}) async {
    final r = await Api.client!.get('notifications', query: <String, String>{'page': '$page'});
    final meta = r.meta['page'];
    final last = meta is Map && meta['last'] is int ? meta['last']! as int : page;
    return NoticePage(
      <AppNotification>[for (final row in r.list) fromJson(row)],
      unread: r.meta['unread'] is int ? r.meta['unread']! as int : 0,
      hasMore: page < last,
    );
  }

  /// Marks [ids] read (all when null); how many remain unread.
  static Future<int> read({List<String>? ids}) async {
    final r = await Api.client!.post('notifications/read', <String, Object?>{'ids': ?ids});
    return r.map['unread'] is int ? r.map['unread']! as int : 0;
  }

  static AppNotification fromJson(Map<String, Object?> j) => AppNotification(
    id: '${j['id'] ?? ''}',
    kind: '${j['kind'] ?? NoteKind.plan}',
    title: '${j['title'] ?? ''}',
    body: '${j['body'] ?? ''}',
    target: j['target'] is String && (j['target']! as String).startsWith('/') ? j['target']! as String : '/notifications',
    at: '${j['at'] ?? ''}',
    read: j['read'] == true,
    key: j['key'] is String ? j['key']! as String : null,
  );
}
