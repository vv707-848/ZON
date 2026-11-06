// Whether this build talks to a server, and its client.
//
//   flutter run --dart-define=ZON_API_URL=https://api.zon.sa/api/v1
//
// Without ZON_API_URL the app is the approved local prototype (LocalAuth, Store and the simulations), as before;
// the server takes over one part at a time (docs/ARCHITECTURE.md «ربط خادم»).

import 'package:zon/core/net/api_client.dart';

/// The server's address in this build; empty = no server.
const String kApiUrl = String.fromEnvironment('ZON_API_URL');

/// The server of the running app (set by lib/app/server.dart, or by a test).
abstract final class Api {
  static ApiClient? client;

  /// Whether the app works with the server.
  static bool get on => client != null;
}
