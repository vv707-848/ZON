// Sign-up / sign-in on the device — a SIMULATION of the real authentication.
//
// What it does now (no server yet):
// * every account is a `LocalAccount` record saved on the device
//   (`zon.accounts.v1`) with a salted PBKDF2 hash — never the password;
// * signing in checks the hash and opens a `Session` (no password inside);
// * signing out closes the session only — the account stays, so the same
//   username and password work again (QA finding AUTH-02);
// * the phone number is checked for a Saudi mobile format before the code
//   step (AUTH-03). The SMS code itself is a fixed test code until the SMS
//   provider is connected.
//
// What the server must take over: account creation, password hashing and
// checking, the SMS code (OTP), FAL licence verification with REGA, session
// tokens, and the limits on accounts per device. Replace the bodies of
// [LocalAuth] with API calls; the screens only use these methods.

import 'package:zon/core/utils/input.dart';
import 'package:zon/data/auth/password_hash.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';

/// Result of [LocalAuth.signUp] / [LocalAuth.signIn]: the opened session,
/// or an Arabic error message for the form.
class AuthResult {
  const AuthResult.ok(Session this.session) : error = null;
  const AuthResult.fail(String this.error) : session = null;

  final Session? session;
  final String? error;

  bool get ok => session != null;
}

/// The SMS code accepted by the test build (shown on the code screen).
const String kTestOtp = '204815';

/// Local account registry and session handling (see the file comment).
abstract final class LocalAuth {
  /// 3–32 Latin letters, digits, dot, dash or underscore.
  static String? usernameError(String v) {
    final t = v.trim();
    if (t.isEmpty) return 'اكتب اسم المستخدم';
    if (!RegExp(r'^[A-Za-z0-9._-]{3,32}$').hasMatch(t)) return 'اسم المستخدم من 3 إلى 32 حرفاً لاتينياً أو رقماً';
    return null;
  }

  static String? passwordError(String v) => v.length < 8 ? 'كلمة السر 8 أحرف على الأقل' : null;

  static String? phoneError(String v) {
    if (v.trim().isEmpty) return 'اكتب رقم الجوال';
    return normalizeSaudiMobile(v) == null ? 'رقم الجوال غير صحيح — مثال: 05XXXXXXXX' : null;
  }

  static String? nameError(String v) => v.trim().isEmpty ? 'اكتب الاسم أو اسم الشركة' : null;

  /// Whether [username] is already used by an account on this device.
  static bool usernameTaken(String username) => store.accountByUsername(username) != null;

  /// Checks the sign-up details (before the SMS code step).
  /// Returns the first error, or null when everything is valid.
  static String? validateSignUp({required String name, required String phone, required String username, required String password}) {
    return nameError(name) ??
        phoneError(phone) ??
        usernameError(username) ??
        (usernameTaken(username) ? 'اسم المستخدم مستخدم من حساب آخر' : null) ??
        passwordError(password);
  }

  /// Creates the account and opens its session.
  static AuthResult signUp({
    required String name,
    required String phone,
    required String username,
    required String password,
    required Role role,
    required String city,
    String? fal,
  }) {
    final error = validateSignUp(name: name, phone: phone, username: username, password: password);
    if (error != null) return AuthResult.fail(error);
    final salt = newSalt();
    final account = LocalAccount(
      id: newAccountId(),
      username: username.trim(),
      name: name.trim(),
      phone: normalizeSaudiMobile(phone)!,
      role: role,
      city: city,
      fal: fal != null && fal.trim().isNotEmpty ? digitsOnly(fal) : null,
      salt: salt,
      hash: hashPassword(password, salt),
      iterations: kPasswordIterations,
      createdAt: DateTime.now().toIso8601String(),
    );
    store.saveAccount(account);
    final session = account.toSession();
    store.writeSession(session);
    return AuthResult.ok(session);
  }

  /// Opens the session of the account with this username and password.
  static AuthResult signIn(String username, String password) {
    final account = store.accountByUsername(username);
    if (account == null || !verifyPassword(password, salt: account.salt, hash: account.hash, iterations: account.iterations)) {
      return const AuthResult.fail('اسم المستخدم أو كلمة السر غير صحيحة');
    }
    final session = account.toSession();
    store.writeSession(session);
    return AuthResult.ok(session);
  }

  /// Closes the session. The account and its private data stay on the device.
  static void signOut() => store.clearSession();

  /// Saves an edited profile to both the account record and the session.
  static void updateProfile({required String name, required String phone, required String city}) {
    final s = store.readSession();
    if (s == null) return;
    final normalized = normalizeSaudiMobile(phone) ?? phone.trim();
    final account = store.accountById(s.id);
    if (account != null) store.saveAccount(account.withProfile(name: name.trim(), phone: normalized, city: city));
    store.writeSession(s.copyWith(name: name.trim(), phone: normalized, city: city));
  }
}
