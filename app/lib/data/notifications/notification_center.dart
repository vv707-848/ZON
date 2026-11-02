// Notifications of the signed-in account (the bell and the notifications
// page). Stored on the device under `zon.notifs.v1@<accountId>`.
//
// Where they come from in this local build (no push server yet):
// * events of the app itself — a quota used up, the owner plan activated;
// * conditions read from the data — new offers matching the saved wish
//   (seeker / developer), new requests in a broker's city published by
//   other accounts on this device, a boost that ended on one of the
//   account's listings.
// The "new message" type and its destination (the conversation) are ready
// for the server; this build has no incoming messages to report, so it
// creates none (the screenshot harness and the tests add sample rows,
// flagged `demo`). Real push notifications (server / APNs / FCM) are not
// part of this build.

import 'package:zon/core/utils/clock.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';

export 'package:zon/data/models/notification.dart';

/// Notifications of the signed-in account.
final NotificationCenter notifications = NotificationCenter._();

class NotificationCenter {
  NotificationCenter._();

  static const String _key = 'zon.notifs.v1';

  /// Roles that have the matching assistant (the only match notifications).
  static const Set<String> _matchRoles = <String>{'seeker', 'developer'};

  // ---------------------------------------------------------------- storage

  List<AppNotification> _stored({String? accountId}) {
    final raw = store.readAccountJson(_key, accountId: accountId);
    if (raw is! List) return <AppNotification>[];
    return raw.whereType<Map<String, Object?>>().map(AppNotification.fromJson).toList();
  }

  void _save(List<AppNotification> rows, {String? accountId}) =>
      store.writeAccountJson(_key, rows.map((e) => e.toJson()).toList(), accountId: accountId);

  // ---------------------------------------------------------------- reading

  /// Every notification, newest first, including the ones computed from the
  /// data that are not saved yet. Pure: safe to call while building.
  List<AppNotification> list() {
    if (store.accountId == null) return const <AppNotification>[];
    final rows = _merge(_stored(), _computed());
    rows.sort((a, b) => b.time.compareTo(a.time));
    return rows;
  }

  /// Unread count shown on the bell.
  int unreadCount() => list().where((n) => !n.read).length;

  /// Saves the computed notifications (call outside `build`, e.g. when the
  /// page opens) so their time and read state stay fixed.
  void sync() {
    if (store.accountId == null) return;
    final stored = _stored();
    final merged = _merge(stored, _computed());
    if (_same(stored, merged)) return;
    _save(merged);
  }

  // ---------------------------------------------------------------- writing

  /// Adds a notification for an app event — for the signed-in account, or
  /// for [accountId] (e.g. the account that started a payment whose answer
  /// arrived after it signed out). A [key] already used is ignored (one
  /// notification per event).
  void add({
    required String kind,
    required String title,
    required String body,
    required String target,
    String? key,
    bool demo = false,
    String? accountId,
  }) {
    final acc = accountId ?? store.accountId;
    if (acc == null) return;
    final rows = _stored(accountId: acc);
    if (key != null && rows.any((n) => n.key == key)) return;
    final now = appNow();
    rows.add(
      AppNotification(
        id: key != null ? 'k:$key' : 'n-${now.microsecondsSinceEpoch}',
        kind: kind,
        title: title,
        body: body,
        target: target,
        at: now.toIso8601String(),
        key: key,
        demo: demo,
      ),
    );
    _save(rows, accountId: acc);
  }

  /// Marks one notification read (the bell count drops).
  void markRead(String id) {
    sync();
    final rows = _stored();
    final i = rows.indexWhere((n) => n.id == id);
    if (i < 0 || rows[i].read) return;
    rows[i] = rows[i].copyWith(read: true);
    _save(rows);
  }

  /// Marks every notification of [kind] read (e.g. the matching page was
  /// opened, so its results were seen).
  void markKindRead(String kind) {
    final rows = _stored();
    if (!rows.any((n) => n.kind == kind && !n.read)) return;
    _save(<AppNotification>[for (final n in rows) n.kind == kind ? n.copyWith(read: true) : n]);
  }

  // ---------------------------------------------------------------- computed

  /// Stored rows + computed rows whose key is new. A match notification that
  /// is no longer current (its offers were seen) is shown as read.
  List<AppNotification> _merge(List<AppNotification> stored, List<AppNotification> computed) {
    final keys = stored.map((n) => n.key).whereType<String>().toSet();
    final out = <AppNotification>[...stored, ...computed.where((n) => !keys.contains(n.key))];
    final currentMatch = computed.where((n) => n.kind == NoteKind.match).map((n) => n.key).toSet();
    return <AppNotification>[for (final n in out) n.kind == NoteKind.match && !n.read && !currentMatch.contains(n.key) ? n.copyWith(read: true) : n];
  }

  List<AppNotification> _computed() {
    final s = store.readSession();
    if (s == null) return const <AppNotification>[];
    final now = appNow();
    final out = <AppNotification>[];
    AppNotification note(String kind, String key, String title, String body, String target) =>
        AppNotification(id: 'k:$key', kind: kind, title: title, body: body, target: target, at: now.toIso8601String(), key: key);

    // 1. offers matching the saved wish (matching assistant)
    if (_matchRoles.contains(s.role)) {
      final wish = store.loadWish();
      if (wish != null && wish.text.isNotEmpty) {
        final fresh = store.freshMatches()..sort();
        final pinged = store.radarPinged();
        if (fresh.isNotEmpty || pinged) {
          final where = wish.hoods.isNotEmpty ? wish.hoods.first : wish.city;
          out.add(
            note(
              NoteKind.match,
              'match:${fresh.join(',')}${pinged ? ':ping' : ''}',
              'عروض مطابقة لرغبتك',
              fresh.isEmpty ? 'شيك على العروض المطابقة لرغبتك' : '${fresh.length} ${fresh.length == 1 ? 'عرض جديد' : 'عروض جديدة'} في $where',
              '/account/matching',
            ),
          );
        }
      }
    }

    // 2. new requests in the broker's city (published by other accounts)
    if (s.role == 'broker' && s.city.isNotEmpty) {
      final joined = store.accountById(s.id)?.createdAt;
      final since = joined == null ? null : DateTime.tryParse(joined);
      final fresh = store.allRequests().where((r) {
        if (r.ownerId == null || r.ownerId == s.id || r.city != s.city) return false;
        final at = r.createdAt == null ? null : DateTime.tryParse(r.createdAt!);
        return at != null && (since == null || at.isAfter(since));
      }).toList();
      for (final r in fresh) {
        final types = r.types.map((t) => kTypeLabel[t] ?? t).join('، ');
        out.add(
          note(
            NoteKind.request,
            'area:${r.id}',
            'طلب جديد في منطقتك',
            '${r.purpose == 'rent' ? 'إيجار' : 'شراء'} ${types.isEmpty ? 'عقار' : types} — ${r.neighborhoods.isNotEmpty ? r.neighborhoods.first : r.city}',
            '/requests/${r.id}',
          ),
        );
      }
    }

    // 3. a boost ended on one of the account's listings
    for (final l in store.myListings()) {
      final f = l.premiumUntil == null ? null : DateTime.tryParse(l.premiumUntil!);
      if (f != null && !now.isBefore(f)) {
        out.add(note(NoteKind.listing, 'ended:featured:${l.id}:${l.premiumUntil}', 'انتهى تمييز عرضك', l.title, '/listings/${l.id}'));
      }
      final u = l.urgentUntil == null ? null : DateTime.tryParse(l.urgentUntil!);
      if (u != null && !now.isBefore(u)) {
        out.add(note(NoteKind.listing, 'ended:urgent:${l.id}:${l.urgentUntil}', 'انتهت مدة «عاجل» لعرضك', l.title, '/listings/${l.id}'));
      }
    }

    return out;
  }

  bool _same(List<AppNotification> a, List<AppNotification> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i].id != b[i].id || a[i].read != b[i].read) return false;
    }
    return true;
  }
}
