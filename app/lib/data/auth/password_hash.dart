// Salted password hashing for the local account registry.
//
// Only the on-device simulation of sign-in uses this (see local_auth.dart).
// The device keeps `salt + hash`, never the password itself. A real server
// keeps its own hashes and issues its own session tokens; this file is then
// no longer needed.

import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';

/// PBKDF2 rounds used for new passwords.
const int kPasswordIterations = 12000;

/// A fresh random salt (16 bytes, base64).
String newSalt([Random? random]) {
  final r = random ?? Random.secure();
  return base64Encode(List<int>.generate(16, (_) => r.nextInt(256)));
}

/// PBKDF2-HMAC-SHA256 of [password] with the base64 [salt] (base64 result).
String hashPassword(String password, String salt, {int iterations = kPasswordIterations}) {
  return base64Encode(_pbkdf2(utf8.encode(password), base64Decode(salt), iterations, 32));
}

/// Whether [password] matches the stored [salt] / [hash] (constant-time compare).
bool verifyPassword(String password, {required String salt, required String hash, required int iterations}) {
  if (salt.isEmpty || hash.isEmpty || iterations <= 0) return false;
  final List<int> expected;
  try {
    expected = base64Decode(hash);
  } catch (_) {
    return false;
  }
  final actual = _pbkdf2(utf8.encode(password), base64Decode(salt), iterations, expected.length);
  var diff = 0;
  for (var i = 0; i < expected.length; i++) {
    diff |= expected[i] ^ actual[i];
  }
  return diff == 0;
}

Uint8List _pbkdf2(List<int> password, List<int> salt, int iterations, int length) {
  final hmac = Hmac(sha256, password);
  final out = BytesBuilder(copy: false);
  var block = 1;
  while (out.length < length) {
    final first = Uint8List(salt.length + 4)..setAll(0, salt);
    ByteData.sublistView(first).setUint32(salt.length, block);
    var u = hmac.convert(first).bytes;
    final t = Uint8List.fromList(u);
    for (var i = 1; i < iterations; i++) {
      u = hmac.convert(u).bytes;
      for (var j = 0; j < t.length; j++) {
        t[j] ^= u[j];
      }
    }
    out.add(t);
    block++;
  }
  return Uint8List.sublistView(out.takeBytes(), 0, length);
}
