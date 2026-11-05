// The screens' one door to the account: the server when the app has one (ServerAuth), else the device
// (LocalAuth, the approved prototype). The sign-up's steps differ between the two and are chosen in its screen.

import 'package:zon/core/net/api.dart';
import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/auth/server_auth.dart';

abstract final class Auth {
  /// Whether accounts live on the server.
  static bool get server => Api.on;

  static Future<AuthResult> signIn(String login, String password) async {
    if (server) return ServerAuth.signIn(login, password);
    return LocalAuth.signIn(login, password);
  }

  /// Closes this phone's session (the server's too).
  static Future<void> signOut() async {
    if (server) {
      await ServerAuth.signOut();
    } else {
      LocalAuth.signOut();
    }
  }
}
