// The server's gate (docs/api.md, CheckAppVersion): a version below the minimum set in the admin panel stops
// until the person updates it (426 UPDATE_REQUIRED), and maintenance stops everyone but the testers
// (503 MAINTENANCE_MODE). Closed by any answer of the server (ApiClient), and by [AppGate.check] at the start
// and whenever the app comes back to the screen. lib/app/gate_overlay.dart draws it.

import 'package:flutter/foundation.dart';

import 'package:zon/core/net/api_client.dart';
import 'package:zon/core/net/api_error.dart';

/// Open, update required, or maintenance.
@immutable
sealed class Gate {
  const Gate();
}

/// The app may be used.
class GateOpen extends Gate {
  const GateOpen();
}

/// This version may no longer be used: [minVersion] or newer, from [storeUrl].
class GateUpdate extends Gate {
  const GateUpdate({required this.minVersion, this.storeUrl});

  final String minVersion;
  final String? storeUrl;
}

/// The platform is under maintenance until [until] (unknown when null).
class GateMaintenance extends Gate {
  const GateMaintenance({this.message, this.until});

  final String? message;
  final DateTime? until;
}

/// A newer version in the store: offered, never forced.
@immutable
class NewerVersion {
  const NewerVersion({required this.version, this.storeUrl});

  final String version;
  final String? storeUrl;
}

/// The gate of this app.
abstract final class AppGate {
  static final ValueNotifier<Gate> state = ValueNotifier<Gate>(const GateOpen());

  /// The store's latest version when it is newer than this app's.
  static final ValueNotifier<NewerVersion?> newer = ValueNotifier<NewerVersion?>(null);

  /// Reads `GET /app/config` (always answered, even below the minimum or during maintenance) and sets the gate.
  /// Maintenance lets testers in: for a signed-in account an ordinary request decides (its 503 closes the gate).
  static Future<void> check(ApiClient api) async {
    final ApiResult result;
    try {
      result = await api.get('app/config');
    } on ApiError {
      return; // offline: what we knew stays
    }
    final platform = result.map['platform'];
    if (platform is! Map) {
      state.value = const GateOpen();
      newer.value = null;
      return;
    }
    final config = platform.cast<String, Object?>();
    final version = api.identity().version;
    final minimum = _text(config['min_version']);
    final latest = _text(config['latest_version']);
    final storeUrl = _text(config['store_url']);
    newer.value = latest != null && compareVersions(version, latest) < 0 ? NewerVersion(version: latest, storeUrl: storeUrl) : null;
    if (minimum != null && compareVersions(version, minimum) < 0) {
      state.value = GateUpdate(minVersion: minimum, storeUrl: storeUrl);
      return;
    }
    final maintenance = config['maintenance'];
    if (maintenance is! Map) {
      state.value = const GateOpen();
      return;
    }
    if (api.token() != null) {
      try {
        await api.get('me');
        state.value = const GateOpen(); // a tester: maintenance does not stop this account
      } on ApiError {
        // 503 closed the gate in ApiClient; offline keeps what we knew.
      }
      return;
    }
    final m = maintenance.cast<String, Object?>();
    state.value = GateMaintenance(message: _text(m['message']), until: DateTime.tryParse('${m['until'] ?? ''}')?.toLocal());
  }

  static String? _text(Object? value) => value is String && value.isNotEmpty ? value : null;
}

/// Compares two versions number by number ("1.2.10" is newer than "1.2.9"), as the server's version_compare
/// does for ours: negative when [a] is older than [b], 0 when equal.
int compareVersions(String a, String b) {
  List<int> parts(String v) => <int>[for (final p in v.split(RegExp(r'[.+-]'))) int.tryParse(p) ?? 0];
  final x = parts(a);
  final y = parts(b);
  for (var i = 0; i < x.length || i < y.length; i++) {
    final d = (i < x.length ? x[i] : 0) - (i < y.length ? y[i] : 0);
    if (d != 0) return d.sign;
  }
  return 0;
}
