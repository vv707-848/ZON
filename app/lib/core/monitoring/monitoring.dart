// Error tracking (Sentry, project «zon-app») — off unless the build has --dart-define=SENTRY_DSN=….
//
// The same contract as the server's (docs/operations.md «تتبّع الأخطاء»): a crash or an unexpected error carries
// its stack, the app's version, the account as a bare id and — for a request that broke — its trace id, the key to
// the same action in the server's errors and logs. Never sent: request or response bodies, screenshots, the screen's
// widgets, what the person typed or tapped. Breadcrumbs of the server calls hold the method, the path and the status.

import 'package:sentry_flutter/sentry_flutter.dart';

/// The project's DSN (Sentry → Settings → Client Keys); empty = nothing is sent.
const String kSentryDsn = String.fromEnvironment('SENTRY_DSN');

const String kSentryEnvironment = String.fromEnvironment('SENTRY_ENVIRONMENT', defaultValue: 'production');

/// The app's link to Sentry; every call is a no-op while [on] is false.
abstract final class Monitoring {
  static bool get on => kSentryDsn.isNotEmpty;

  /// Before anything else in `main`, so the errors of the start are caught too. The release is read from the
  /// app itself (`com.zon.app@1.2.1+4`).
  static Future<void> init() async {
    if (!on) return;
    await SentryFlutter.init((options) {
      options
        ..dsn = kSentryDsn
        ..environment = kSentryEnvironment
        ..sendDefaultPii = false
        ..attachScreenshot = false
        ..attachViewHierarchy = false
        ..enableUserInteractionBreadcrumbs = false
        ..enableUserInteractionTracing = false
        ..enableAutoPerformanceTracing = false
        ..tracesSampleRate = null
        ..maxBreadcrumbs = 60
        ..beforeSend = _beforeSend;
    });
  }

  /// The account on the next errors, as a bare id (`customer:123`); null after signing out.
  static void account(String? id) {
    if (!on) return;
    Sentry.configureScope((scope) => scope.setUser(id == null ? null : SentryUser(id: id)));
  }

  /// One call to the server: method, path, status (0 = no answer) and its trace id — never the body or the query.
  static void request(String method, String path, int status, String requestId) {
    if (!on) return;
    Sentry.addBreadcrumb(
      Breadcrumb(
        type: 'http',
        category: 'http',
        level: status == 0 || status >= 400 ? SentryLevel.warning : SentryLevel.info,
        data: <String, dynamic>{'method': method, 'url': path, 'status_code': status, 'request_id': requestId},
      ),
    );
  }

  /// An answer the app cannot read (a bug of the app or of the server), with the trace id of its request.
  static void contract(String what, String requestId) {
    if (!on) return;
    Sentry.captureMessage('api: $what', level: SentryLevel.error, withScope: (scope) => scope.setTag('request_id', requestId));
  }

  /// The account stays a bare id, and no request is attached.
  static SentryEvent? _beforeSend(SentryEvent event, Hint hint) {
    final id = event.user?.id;
    event.user = id == null ? null : SentryUser(id: id);
    event.request = null;
    return event;
  }
}
