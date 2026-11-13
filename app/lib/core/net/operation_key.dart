// «Idempotency-Key» (docs/api.md): one key per operation of a form, the same on every try of it. A publish sent
// again after its answer was lost carries the same key, so the server runs it once; once the form changes, or the
// operation is done ([OperationKey.done]: its answer came), the next send is another operation with another key —
// the same wish made again after it was deleted is a new wish, not the old answer.

import 'dart:convert';

import 'package:zon/core/net/app_identity.dart';

/// The key of a form's current operation.
class OperationKey {
  OperationKey(this.prefix);

  /// What the operation is (`req`, `wish`…), at the head of its key.
  final String prefix;

  String? _key;
  String? _body;

  /// The operation's answer came: the next send is another operation, whatever it sends.
  void done() {
    _key = null;
    _body = null;
  }

  /// The key for sending [body]: the same as the last one while [body] is the same.
  String forBody(Object? body) {
    final sent = jsonEncode(body);
    if (sent != _body || _key == null) {
      _body = sent;
      _key = '$prefix-${newId(24)}';
    }
    return _key!;
  }
}
