// A failed call to the server, read from its error envelope (docs/api.md «الخطأ» and «رموز الأخطاء»).

import 'package:flutter/foundation.dart';

/// What the server answered instead of success, or [ApiError.network] when it could not be reached.
@immutable
class ApiError implements Exception {
  const ApiError({
    required this.status,
    required this.code,
    required this.message,
    this.fields = const <String, List<String>>{},
    this.details = const <String, Object?>{},
    this.traceId,
  });

  /// No answer: offline, timed out, or the server unreachable.
  const ApiError.network()
    : status = 0,
      code = networkCode,
      message = 'تعذّر الاتصال بزون. تحقق من الإنترنت وحاول مرة أخرى.',
      fields = const <String, List<String>>{},
      details = const <String, Object?>{},
      traceId = null;

  static const String networkCode = 'NETWORK';

  /// The HTTP status; 0 without an answer.
  final int status;

  /// UNAUTHENTICATED · VALIDATION_FAILED · UPDATE_REQUIRED · …
  final String code;

  /// The server's Arabic message.
  final String message;

  /// The messages of each invalid field (`details.fields`).
  final Map<String, List<String>> fields;

  final Map<String, Object?> details;

  /// The trace id of the request: the same in the server's logs, its errors and «تتبّع طلب».
  final String? traceId;

  /// The first message of a field, else [message]: the line a form shows.
  String get firstMessage {
    for (final messages in fields.values) {
      if (messages.isNotEmpty) return messages.first;
    }
    return message;
  }

  /// The line for the person; an unexpected error adds the trace number support asks for.
  String get forUser => status >= 500 && traceId != null ? '$firstMessage — رقم التتبّع $traceId' : firstMessage;

  @override
  String toString() => 'ApiError($status $code${traceId == null ? '' : ' $traceId'})';
}
