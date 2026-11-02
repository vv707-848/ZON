// In-app notifications (the bell on the home page).

/// Notification types and where a tap leads (see [AppNotification.target]).
abstract final class NoteKind {
  /// Offers matching the saved wish → matching assistant.
  static const String match = 'match';

  /// New chat message → that conversation.
  static const String message = 'message';

  /// A listing / project changed (published, boost ended…) → its page.
  static const String listing = 'listing';

  /// A search request (e.g. new in the broker's area) → request details.
  static const String request = 'request';

  /// Subscription or quota → the plan or the related service.
  static const String plan = 'plan';
}

/// One notification of the signed-in account.
class AppNotification {
  const AppNotification({
    required this.id,
    required this.kind,
    required this.title,
    required this.body,
    required this.target,
    required this.at,
    this.read = false,
    this.key,
    this.demo = false,
  });

  final String id;

  /// One of [NoteKind].
  final String kind;
  final String title;
  final String body;

  /// Route opened by a tap, e.g. `/account/matching`, `/chats/fahd`,
  /// `/listings/l1`, `/requests/r3`, `/account/plans`.
  final String target;

  /// ISO-8601 time.
  final String at;
  final bool read;

  /// De-duplication key of notifications computed from the data (one
  /// notification per event).
  final String? key;

  /// Sample row shown for preview in this local build (documented in
  /// docs/ARCHITECTURE.md); real ones come from the server.
  final bool demo;

  DateTime get time => DateTime.tryParse(at) ?? DateTime(2000);

  AppNotification copyWith({bool? read, String? title, String? body, String? target, String? at, String? key}) => AppNotification(
    id: id,
    kind: kind,
    title: title ?? this.title,
    body: body ?? this.body,
    target: target ?? this.target,
    at: at ?? this.at,
    read: read ?? this.read,
    key: key ?? this.key,
    demo: demo,
  );

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'kind': kind,
    'title': title,
    'body': body,
    'target': target,
    'at': at,
    'read': read,
    if (key != null) 'key': key,
    if (demo) 'demo': true,
  };

  factory AppNotification.fromJson(Map<String, Object?> j) => AppNotification(
    id: j['id'] as String? ?? '',
    kind: j['kind'] as String? ?? NoteKind.plan,
    title: j['title'] as String? ?? '',
    body: j['body'] as String? ?? '',
    target: j['target'] as String? ?? '/',
    at: j['at'] as String? ?? '',
    read: j['read'] == true,
    key: j['key'] as String?,
    demo: j['demo'] == true,
  );
}
