// A server for the tests: the app's ApiClient talks to a scripted one (package:http/testing), as a build with
// ZON_API_URL talks to api.zon.sa. Every request is kept, to check what the app sent.

import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_client.dart';
import 'package:zon/core/net/app_gate.dart';
import 'package:zon/core/net/app_identity.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/push/push.dart';

/// The server's success envelope.
Map<String, Object?> ok([Object? data, Map<String, Object?> meta = const <String, Object?>{}]) => <String, Object?>{
  'success': true,
  'data': data,
  'meta': meta,
};

/// The server's error envelope.
Map<String, Object?> apiFail(
  String code,
  String message, {
  Map<String, Object?> details = const <String, Object?>{},
  String trace = 'srv-trace-0001',
}) => <String, Object?>{
  'success': false,
  'error': <String, Object?>{'code': code, 'message': message, 'details': details, 'trace_id': trace},
};

/// The account as `me` describes it.
Map<String, Object?> me({int id = 41, String role = 'broker', String name = 'فيصل العتيبي', Map<String, bool>? prefs}) => <String, Object?>{
  'id': id,
  'role': role,
  'status': 'active',
  'name': name,
  'username': 'faisal',
  'phone': '0551234567',
  'city': 'الرياض',
  'notification_prefs':
      prefs ??
      <String, bool>{
        'messages': true,
        'followed_posts': true,
        'area_requests': true,
        'listing_status': true,
        'ratings': true,
        'matching': true,
        'marketing': false,
      },
};

/// A scripted server: an answer per `METHOD path` (the path under /api/v1), every request kept.
class FakeServer {
  final List<http.Request> requests = <http.Request>[];
  final Map<String, (int, Object?)> _answers = <String, (int, Object?)>{};

  /// Answers `METHOD path` with [status] and [body] (a JSON value) from now on.
  void on(String method, String path, int status, Object? body) => _answers['$method $path'] = (status, body);

  /// No answer at all for `METHOD path` (offline).
  void offline(String method, String path) => _answers['$method $path'] = (0, null);

  /// Answers `METHOD path` asked with exactly [query] (a page, say) — before the answer of the path alone.
  void onQuery(String method, String path, Map<String, String> query, int status, Object? body) =>
      _answers['$method $path?${_query(query)}'] = (status, body);

  /// Answers `METHOD path` asked with the session [token] — before the answers for anyone (one account's answer,
  /// another's for another).
  void onFor(String token, String method, String path, int status, Object? body) => _answers['$method $path@$token'] = (status, body);

  final Map<String, Completer<void>> _held = <String, Completer<void>>{};

  /// Holds the answers of `METHOD path` asked with [token] only, until the returned completer completes.
  Completer<void> holdFor(String token, String method, String path) => _held['$method $path@$token'] = Completer<void>();

  /// Holds the answers of `METHOD path` until the returned completer completes (an answer that comes late).
  Completer<void> hold(String method, String path) => _held['$method $path'] = Completer<void>();

  /// The requests to `METHOD path`.
  List<http.Request> sent(String method, String path) => <http.Request>[
    for (final r in requests)
      if (r.method == method && _path(r.url) == path) r,
  ];

  /// The JSON body of a request.
  static Map<String, Object?> body(http.Request r) => (jsonDecode(r.body) as Map).cast<String, Object?>();

  static String _path(Uri url) => url.path.replaceFirst('/api/v1/', '');

  static String _query(Map<String, String> q) => (q.keys.toList()..sort()).map((k) => '$k=${q[k]}').join('&');

  late final MockClient client = MockClient((request) async {
    requests.add(request);
    final key = '${request.method} ${_path(request.url)}';
    final token = (request.headers['Authorization'] ?? '').replaceFirst('Bearer ', '');
    final held = _held['$key@$token'] ?? _held[key];
    if (held != null) await held.future;
    final answer = _answers['$key@$token'] ?? _answers['$key?${_query(request.url.queryParameters)}'] ?? _answers[key];
    if (answer == null) {
      return _json(404, apiFail('NOT_FOUND', 'العنصر غير موجود.'));
    }
    final (status, body) = answer;
    if (status == 0) throw http.ClientException('offline', request.url);
    return _json(status, body);
  });

  static http.Response _json(int status, Object? body) =>
      http.Response.bytes(utf8.encode(jsonEncode(body)), status, headers: <String, String>{'content-type': 'application/json'});
}

/// This phone, as the tests know it.
const AppIdentity testIdentity = AppIdentity(platform: 'ios', version: '1.2.1', build: '4', deviceId: 'dev-test-0001', deviceName: 'iPhone 16 Pro');

/// The app with a server: [server] answers, nobody signed in, the gate open. Undone after the test.
FakeServer serverDevice([FakeServer? server]) {
  final s = server ?? FakeServer();
  AppIdentity.current = testIdentity;
  ServerSession.reset();
  Push.reset();
  AppGate.state.value = const GateOpen();
  AppGate.newer.value = null;
  Api.client = ApiClient(
    base: Uri.parse('https://api.test/api/v1'),
    identity: () => AppIdentity.current,
    token: () => ServerSession.token,
    onUnauthenticated: () => unawaited(ServerSession.expire()),
    client: s.client,
  );
  addTearDown(() {
    Api.client = null;
    ServerSession.reset();
    Push.reset();
    AppGate.state.value = const GateOpen();
    AppGate.newer.value = null;
  });
  return s;
}

/// Signs in on the scripted server (POST auth/login) as [account].
Future<void> serverSignIn(FakeServer server, {Map<String, Object?>? account, String token = '7|server-token'}) async {
  server.on('POST', 'auth/login', 200, ok(<String, Object?>{'token': token, 'me': account ?? me()}));
  final result = await ServerAuth.signIn('faisal', 'a-good-secret');
  expect(result.ok, isTrue, reason: result.error);
}

/// FCM for the tests: the test sets its answers and feeds its streams. [register] gives [current], as FCM
/// gives the installation's id (FID) on its registration stream.
class FakePush implements PushProvider {
  bool allowed = true;
  String? current = 'fid-0001';
  int asked = 0;
  final StreamController<String> registered = StreamController<String>.broadcast();
  final StreamController<PushMessage> tapped = StreamController<PushMessage>.broadcast();
  final StreamController<PushMessage> arrived = StreamController<PushMessage>.broadcast();
  PushMessage? launch;

  @override
  Future<bool> requestPermission() async {
    asked++;
    return allowed;
  }

  @override
  Future<void> register() async {
    final id = current;
    if (id != null) registered.add(id);
  }

  @override
  Stream<String> get registrations => registered.stream;

  @override
  Stream<PushMessage> get opened => tapped.stream;

  @override
  Stream<PushMessage> get received => arrived.stream;

  @override
  Future<PushMessage?> initial() async => launch;
}
