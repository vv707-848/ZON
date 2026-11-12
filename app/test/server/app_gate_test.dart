// The gate over the whole app (lib/core/net/app_gate.dart, lib/app/gate_overlay.dart): read from GET /app/config
// at the start and on every return to the screen. Below the minimum the app stops at «حدّث زون»; maintenance
// stops everyone but testers; a newer version in the store is offered, never forced.

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/app_gate.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> _config({String min = '1.2.1', String latest = '1.2.1', String? store, Map<String, Object?>? maintenance}) =>
    ok(<String, Object?>{
      'platform': <String, Object?>{'min_version': min, 'latest_version': latest, 'store_url': store, 'maintenance': maintenance},
      'deep_link_base': 'https://zon.sa',
    });

void main() {
  testWidgets('below the minimum the app stops at «حدّث زون», with the way to the store', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'app/config', 200, _config(min: '1.3.0', latest: '1.3.0', store: 'https://apps.apple.com/app/id1'));

    await AppGate.check(Api.client!);
    await pumpApp(tester, '/');

    expect(find.text('حدّث زون للمتابعة'), findsOneWidget);
    expect(find.textContaining('1.3.0'), findsOneWidget);
    expect(find.text('تحديث من المتجر'), findsOneWidget);
    expect(server.sent('GET', 'app/config').single.headers['X-App-Version'], '1.2.1');
  });

  testWidgets('without a store link yet, the screen says where to look', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'app/config', 200, _config(min: '2.0.0'));

    await AppGate.check(Api.client!);
    await pumpApp(tester, '/');

    expect(find.text('حدّث زون للمتابعة'), findsOneWidget);
    expect(find.text('تحديث من المتجر'), findsNothing);
    expect(find.text('ابحث عن «زون» في المتجر وحدّثه.'), findsOneWidget);
  });

  testWidgets('maintenance shows the server\'s message; the retry opens the app once it is over', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'app/config', 200, _config(maintenance: <String, Object?>{'message': 'نحدّث الخوادم، نعود خلال ساعة', 'until': null}));

    await AppGate.check(Api.client!);
    await pumpApp(tester, '/');
    expect(find.text('زون في الصيانة'), findsOneWidget);
    expect(find.text('نحدّث الخوادم، نعود خلال ساعة'), findsOneWidget);

    server.on('GET', 'app/config', 200, _config());
    // the gate's own button: it lies over the app, whose home may offer a retry of its own under it
    await tapText(tester, 'أعد المحاولة', last: true);
    await tester.pump();

    expect(find.text('زون في الصيانة'), findsNothing);
    expect(AppGate.state.value, isA<GateOpen>());
  });

  test('a tester passes maintenance: the gate follows what the server answers this account', () async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server);
    server.on('GET', 'app/config', 200, _config(maintenance: <String, Object?>{'message': 'صيانة', 'until': null}));
    server.on('GET', 'me', 200, ok(me()));

    await AppGate.check(Api.client!);
    expect(AppGate.state.value, isA<GateOpen>(), reason: 'the server let this account through');

    server.on('GET', 'me', 503, apiFail('MAINTENANCE_MODE', 'صيانة'));
    await AppGate.check(Api.client!);
    expect(AppGate.state.value, isA<GateMaintenance>(), reason: 'an ordinary account is stopped');
  });

  test('at the minimum the app is open; a newer store version is offered, not forced', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'app/config', 200, _config(latest: '1.3.0', store: 'https://play.google.com/store/apps/details?id=com.zon.app'));

    await AppGate.check(Api.client!);

    expect(AppGate.state.value, isA<GateOpen>());
    expect(AppGate.newer.value?.version, '1.3.0');
    expect(AppGate.newer.value?.storeUrl, 'https://play.google.com/store/apps/details?id=com.zon.app');
  });

  test('offline, the check keeps what was known', () async {
    await freshDevice();
    final server = serverDevice();
    AppGate.state.value = const GateUpdate(minVersion: '1.3.0');
    server.offline('GET', 'app/config');

    await AppGate.check(Api.client!);

    expect(AppGate.state.value, isA<GateUpdate>());
  });
}
