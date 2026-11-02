// A local account record — the device-side stand-in for the server's users
// table (see `data/auth/local_auth.dart`).

import 'dart:math';

import 'package:zon/data/models/session.dart';
import 'package:zon/data/models/types.dart';

/// One account created on this device.
///
/// The password is never stored: only a random [salt] and the PBKDF2 [hash]
/// derived from it. When the real server is connected, accounts live there
/// and this record is no longer needed.
class LocalAccount {
  const LocalAccount({
    required this.id,
    required this.username,
    required this.name,
    required this.phone,
    required this.role,
    required this.city,
    this.fal,
    required this.salt,
    required this.hash,
    required this.iterations,
    required this.createdAt,
    this.migratedFrom,
  });

  /// `acc-…` — also the key of the account's private data on the device.
  final String id;
  final String username;
  final String name;

  /// Saudi mobile number, normalised to `05XXXXXXXX`.
  final String phone;
  final Role role;
  final String city;

  /// Broker licence number (رخصة فال) as typed at sign-up; verified by the
  /// server when connected.
  final String? fal;

  /// Base64 salt and PBKDF2-HMAC-SHA256 hash of the password.
  final String salt;
  final String hash;
  final int iterations;

  /// ISO-8601 creation time.
  final String createdAt;

  /// `1.1` when the account was made from the session of version 1.1 on
  /// this device (the only account whose old data can be proven, QA F01).
  final String? migratedFrom;

  /// The session opened by a successful sign-in.
  Session toSession() => Session(id: id, name: name, username: username, phone: phone, role: role, city: city);

  /// The same account with an edited profile.
  LocalAccount withProfile({String? name, String? phone, String? city}) => LocalAccount(
    id: id,
    username: username,
    name: name ?? this.name,
    phone: phone ?? this.phone,
    role: role,
    city: city ?? this.city,
    fal: fal,
    salt: salt,
    hash: hash,
    iterations: iterations,
    createdAt: createdAt,
    migratedFrom: migratedFrom,
  );

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'username': username,
    'name': name,
    'phone': phone,
    'role': role,
    'city': city,
    if (fal != null) 'fal': fal,
    'salt': salt,
    'hash': hash,
    'iterations': iterations,
    'createdAt': createdAt,
    if (migratedFrom != null) 'migratedFrom': migratedFrom,
  };

  factory LocalAccount.fromJson(Map<String, Object?> j) => LocalAccount(
    id: j['id'] as String? ?? '',
    username: j['username'] as String? ?? '',
    name: j['name'] as String? ?? '',
    phone: j['phone'] as String? ?? '',
    role: j['role'] as String? ?? 'seeker',
    city: j['city'] as String? ?? '',
    fal: j['fal'] as String?,
    salt: j['salt'] as String? ?? '',
    hash: j['hash'] as String? ?? '',
    iterations: (numOrNull(j['iterations']) ?? 0).toInt(),
    createdAt: j['createdAt'] as String? ?? '',
    migratedFrom: j['migratedFrom'] as String?,
  );
}

final Random _idRandom = Random.secure();

/// A new random account id (`acc-` + 12 hex characters).
String newAccountId() => 'acc-${List<String>.generate(12, (_) => _idRandom.nextInt(16).toRadixString(16)).join()}';
