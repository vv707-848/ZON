// Chats and their messages.

import 'package:zon/data/models/types.dart';

/// A conversation in the chats list.
class Chat {
  const Chat({required this.id, required this.name, required this.role, required this.last, this.listingId, this.requestId, this.at});

  final String id;
  final String name;
  final Role role;
  final String last;
  final String? listingId;
  final String? requestId;
  final String? at;

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'name': name,
    'role': role,
    'last': last,
    if (listingId != null) 'listingId': listingId,
    if (requestId != null) 'requestId': requestId,
    if (at != null) 'at': at,
  };

  factory Chat.fromJson(Map<String, Object?> j) => Chat(
    id: j['id'] as String? ?? '',
    name: j['name'] as String? ?? '',
    role: j['role'] as String? ?? 'broker',
    last: j['last'] as String? ?? '',
    listingId: j['listingId'] as String?,
    requestId: j['requestId'] as String?,
    at: j['at'] as String?,
  );
}

/// One message; [me] is true for the user's own messages.
class Msg {
  const Msg({this.me, required this.t});

  final bool? me;
  final String t;

  Map<String, Object?> toJson() => <String, Object?>{if (me != null) 'me': me, 't': t};

  factory Msg.fromJson(Map<String, Object?> j) => Msg(me: j['me'] as bool?, t: j['t'] as String? ?? '');
}
