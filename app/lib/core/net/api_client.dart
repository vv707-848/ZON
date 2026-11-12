// The one way the app reaches the server (docs/api.md). Every request carries the app's platform and version —
// so the server's version gate applies to every call — this installation's id, a fresh trace id and the session
// token; every answer is read from the envelope { success, data, meta } or { success: false, error }.

import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'package:zon/core/monitoring/monitoring.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/app_gate.dart';
import 'package:zon/core/net/app_identity.dart';

/// A successful answer: the envelope's `data` and `meta`, and the trace id of the request.
class ApiResult {
  const ApiResult(this.data, this.meta, this.requestId);

  final Object? data;
  final Map<String, Object?> meta;
  final String requestId;

  /// [data] as an object (empty when it is not one).
  Map<String, Object?> get map {
    final d = data;
    return d is Map ? d.cast<String, Object?>() : const <String, Object?>{};
  }

  /// [data] as a list of objects.
  List<Map<String, Object?>> get list {
    final d = data;
    if (d is! List) return const <Map<String, Object?>>[];
    return <Map<String, Object?>>[
      for (final e in d)
        if (e is Map) e.cast<String, Object?>(),
    ];
  }
}

/// The server's client: [Api.client] (lib/core/net/api.dart) once the build names a server.
class ApiClient {
  ApiClient({
    required this.base,
    required this.identity,
    required this.token,
    this.onUnauthenticated,
    http.Client? client,
    this.timeout = const Duration(seconds: 20),
  }) : _http = client ?? http.Client();

  /// `https://api.zon.sa/api/v1`.
  final Uri base;

  /// The app and the phone ([AppIdentity.current]).
  final AppIdentity Function() identity;

  /// The session token, null when signed out.
  final String? Function() token;

  /// The server no longer knows the session (ended from «أجهزتي» or the panel, expired, password changed).
  final void Function()? onUnauthenticated;

  /// The server cuts its own work at 20 s.
  final Duration timeout;

  final http.Client _http;

  Future<ApiResult> get(String path, {Map<String, String>? query}) => send('GET', path, query: query);

  /// A POST; with [idempotencyKey] (docs/api.md «Idempotency-Key») the server runs it once however often it is
  /// sent: the same key on a retry gets the first answer.
  Future<ApiResult> post(String path, [Map<String, Object?>? body, String? idempotencyKey]) =>
      send('POST', path, body: body, idempotencyKey: idempotencyKey);

  Future<ApiResult> patch(String path, [Map<String, Object?>? body]) => send('PATCH', path, body: body);

  Future<ApiResult> put(String path, [Map<String, Object?>? body]) => send('PUT', path, body: body);

  Future<ApiResult> delete(String path) => send('DELETE', path);

  /// The headers of every request (docs/api.md «ترويسات يرسلها التطبيق»), with the session token [t].
  Map<String, String> headers(String requestId, String? t) {
    final id = identity();
    return <String, String>{
      'Accept': 'application/json',
      'Accept-Language': 'ar',
      'X-App-Platform': id.platform,
      'X-App-Version': id.version,
      'X-Device-Id': id.deviceId,
      'X-Request-Id': requestId,
      if (t != null && t.isNotEmpty) 'Authorization': 'Bearer $t',
    };
  }

  /// [path] under [base]: `listings/12` → `https://api.zon.sa/api/v1/listings/12`.
  Uri resolve(String path, [Map<String, String>? query]) {
    final relative = path.startsWith('/') ? path.substring(1) : path;
    final prefix = base.path.endsWith('/') ? base.path : '${base.path}/';
    return base.replace(path: '$prefix$relative', queryParameters: query == null || query.isEmpty ? null : query);
  }

  Future<ApiResult> send(String method, String path, {Map<String, Object?>? body, Map<String, String>? query, String? idempotencyKey}) async {
    final requestId = 'app${newId(12)}';
    final uri = resolve(path, query);
    final sent = token(); // the session this request belongs to
    final request = http.Request(method, uri)..headers.addAll(headers(requestId, sent));
    if (idempotencyKey != null) request.headers['Idempotency-Key'] = idempotencyKey;
    if (body != null) {
      request.bodyBytes = utf8.encode(jsonEncode(body));
      request.headers['Content-Type'] = 'application/json';
    }

    final http.Response response;
    try {
      final streamed = await _http.send(request).timeout(timeout);
      response = await http.Response.fromStream(streamed).timeout(timeout);
    } on Exception {
      // Offline, timed out, refused, a broken TLS handshake: there is no answer to read.
      Monitoring.request(method, uri.path, 0, requestId);
      throw const ApiError.network();
    }
    Monitoring.request(method, uri.path, response.statusCode, requestId);

    final status = response.statusCode;
    final envelope = _decode(response.bodyBytes);
    if (status >= 200 && status < 300 && envelope != null && envelope['success'] == true) {
      final meta = envelope['meta'];
      return ApiResult(envelope['data'], meta is Map ? meta.cast<String, Object?>() : const <String, Object?>{}, requestId);
    }
    final error = _error(status, envelope, requestId);
    _react(error, sent);
    throw error;
  }

  /// The JSON object of an answer; null when it is not one (an empty 204, a proxy's HTML page).
  static Map<String, Object?>? _decode(List<int> bytes) {
    if (bytes.isEmpty) return null;
    try {
      final json = jsonDecode(utf8.decode(bytes));
      return json is Map ? json.cast<String, Object?>() : null;
    } on FormatException {
      return null;
    }
  }

  ApiError _error(int status, Map<String, Object?>? envelope, String requestId) {
    final raw = envelope?['error'];
    if (raw is! Map) {
      // Not the server's envelope (a proxy, a crash before Laravel answered): a bug to look at under this id.
      Monitoring.contract('HTTP $status without the error envelope', requestId);
      return ApiError(status: status, code: 'UNEXPECTED', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.', traceId: requestId);
    }
    final error = raw.cast<String, Object?>();
    final details = error['details'] is Map ? (error['details']! as Map).cast<String, Object?>() : const <String, Object?>{};
    final fields = <String, List<String>>{};
    final invalid = details['fields'];
    if (invalid is Map) {
      for (final entry in invalid.entries) {
        final messages = entry.value;
        fields['${entry.key}'] = messages is List ? <String>[for (final m in messages) '$m'] : <String>['$messages'];
      }
    }
    final trace = error['trace_id'];
    return ApiError(
      status: status,
      code: error['code'] is String ? error['code']! as String : 'UNEXPECTED',
      message: error['message'] is String ? error['message']! as String : 'حدث خطأ غير متوقع. حاول مرة أخرى.',
      fields: fields,
      details: details,
      traceId: trace is String && trace.isNotEmpty ? trace : requestId,
    );
  }

  /// What an error means for the whole app: the gate closes, or the session ended elsewhere — the session that
  /// sent the request [sent], and only while it is still this phone's: a late 401 of a signed-out session ends
  /// nothing of the next one.
  void _react(ApiError error, String? sent) {
    switch (error.code) {
      case 'UPDATE_REQUIRED':
        final store = error.details['store_url'];
        AppGate.state.value = GateUpdate(
          minVersion: '${error.details['min_version'] ?? ''}',
          storeUrl: store is String && store.isNotEmpty ? store : null,
        );
      case 'MAINTENANCE_MODE':
        AppGate.state.value = GateMaintenance(message: error.message, until: DateTime.tryParse('${error.details['until'] ?? ''}')?.toLocal());
      case 'UNAUTHENTICATED':
        if (sent != null && token() == sent) onUnauthenticated?.call();
    }
  }
}
