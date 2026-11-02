// The signed-in user as the screens see it.

import 'package:zon/data/models/types.dart';

/// The signed-in account (stored on the device as the current session).
///
/// The session never holds the password: signing in is checked by
/// `LocalAuth` against the salted hash kept in the account record
/// (see `data/auth/`). [id] is the account id; everything private to the
/// account (tasks, clients, subscription, quotas, notifications…) is saved
/// under this id, so switching accounts never shows another account's data.
class Session {
  const Session({required this.id, required this.name, required this.username, required this.phone, required this.role, required this.city});

  /// Account id (`acc-…`): the key of the account's private data.
  final String id;
  final String name;
  final String username;
  final String phone;
  final Role role;
  final String city;

  Map<String, Object?> toJson() => <String, Object?>{'id': id, 'name': name, 'username': username, 'phone': phone, 'role': role, 'city': city};

  /// Reads a saved session. A session saved by version 1.1 has no `id` (and
  /// still carries a plain password); `Store.init` migrates it to an account
  /// before any screen reads it, so [id] is only empty for corrupt data.
  factory Session.fromJson(Map<String, Object?> j) => Session(
    id: j['id'] as String? ?? '',
    name: j['name'] as String? ?? '',
    username: j['username'] as String? ?? '',
    phone: j['phone'] as String? ?? '',
    role: j['role'] as String? ?? 'seeker',
    city: j['city'] as String? ?? '',
  );

  /// The same session with an edited profile (name, phone, city).
  Session copyWith({String? name, String? phone, String? city}) =>
      Session(id: id, name: name ?? this.name, username: username, phone: phone ?? this.phone, role: role, city: city ?? this.city);
}
