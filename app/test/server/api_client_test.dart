// The one way to the server (lib/core/net/api_client.dart): every call names the app's platform and store
// version — so the server's version gate applies to every call — this phone and a fresh trace id; every answer
// is read from the envelope. 426 and 503 close the gate over the whole app; a 401 ends the session on the phone.

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/app_gate.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/store.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

void main() {
  test('every request names the platform, the store version, this phone and its own trace id', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'catalog', 200, ok(<String, Object?>{'cities': <Object?>[]}));
    server.on('POST', 'support-tickets', 201, ok(<String, Object?>{'number': 'ZT-1'}));

    await Api.client!.get('catalog');
    await Api.client!.post('support-tickets', <String, Object?>{'body': 'شقة في النرجس'});
    await serverSignIn(server);
    await Api.client!.get('catalog');

    expect(server.requests, hasLength(4));
    for (final r in server.requests) {
      expect(r.url.toString(), startsWith('https://api.test/api/v1/'));
      expect(r.headers['X-App-Platform'], 'ios');
      expect(r.headers['X-App-Version'], '1.2.1', reason: 'the store version, without the build number');
      expect(r.headers['X-Device-Id'], 'dev-test-0001');
      expect(r.headers['Accept'], 'application/json');
      expect(r.headers['Accept-Language'], 'ar');
      expect(r.headers['X-Request-Id'], matches(RegExp(r'^[A-Za-z0-9._-]{8,64}$')), reason: 'the server keeps it as the trace id');
    }
    expect(server.requests.map((r) => r.headers['X-Request-Id']).toSet(), hasLength(4), reason: 'one trace per request');
    expect(server.requests.first.headers.containsKey('Authorization'), isFalse);
    expect(server.requests.last.headers['Authorization'], 'Bearer 7|server-token');
    expect(FakeServer.body(server.requests[1]), <String, Object?>{'body': 'شقة في النرجس'});
  });

  test('426 closes the gate over the app with the minimum version and the store link', () async {
    await freshDevice();
    final server = serverDevice();
    server.on(
      'GET',
      'catalog',
      426,
      apiFail(
        'UPDATE_REQUIRED',
        'حدّث التطبيق للمتابعة.',
        details: <String, Object?>{'min_version': '1.3.0', 'latest_version': '1.3.2', 'store_url': 'https://apps.apple.com/app/id1'},
      ),
    );

    await expectLater(Api.client!.get('catalog'), throwsA(isA<ApiError>().having((e) => e.code, 'code', 'UPDATE_REQUIRED')));

    final gate = AppGate.state.value;
    expect(gate, isA<GateUpdate>());
    expect((gate as GateUpdate).minVersion, '1.3.0');
    expect(gate.storeUrl, 'https://apps.apple.com/app/id1');
  });

  test('503 closes it for maintenance, with the server\'s message and the expected time', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'catalog', 503, apiFail('MAINTENANCE_MODE', 'نحدّث الخوادم', details: <String, Object?>{'until': '2026-11-06T03:00:00+03:00'}));

    await expectLater(Api.client!.get('catalog'), throwsA(isA<ApiError>()));

    final gate = AppGate.state.value as GateMaintenance;
    expect(gate.message, 'نحدّث الخوادم');
    expect(gate.until!.isAtSameMomentAs(DateTime.utc(2026, 11, 6)), isTrue);
  });

  test('a 401 on a signed-in phone: the session was ended elsewhere, so it ends here too', () async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server);
    var ended = 0;
    ServerSession.onEnded = () => ended++;
    server.on('GET', 'me/sessions', 401, apiFail('UNAUTHENTICATED', 'سجّل الدخول للمتابعة.'));

    await expectLater(ServerAuth.sessions(), throwsA(isA<ApiError>()));
    await Future<void>.delayed(const Duration(milliseconds: 1));

    expect(ServerSession.token, isNull);
    expect(store.readSession(), isNull);
    expect(ended, 1);
  });

  test('no answer is a network error in Arabic; the gate and the session stay as they were', () async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server);
    server.offline('GET', 'me/sessions');

    final error = await ServerAuth.sessions().then<ApiError?>((_) => null, onError: (Object e) => e as ApiError);

    expect(error!.code, ApiError.networkCode);
    expect(error.forUser, contains('الإنترنت'));
    expect(AppGate.state.value, isA<GateOpen>());
    expect(ServerSession.token, isNotNull);
  });

  test('a field error reaches the form as the field\'s first message; a 5xx adds the trace number', () async {
    await freshDevice();
    final server = serverDevice();
    server.on(
      'POST',
      'auth/register',
      422,
      apiFail(
        'VALIDATION_FAILED',
        'تحقق من الحقول المطلوبة.',
        details: <String, Object?>{
          'fields': <String, Object?>{
            'username': <String>['اسم المستخدم مستخدم من حساب آخر.'],
          },
        },
      ),
    );
    server.on('POST', 'auth/otp/resend', 500, apiFail('SERVER_ERROR', 'حدث خطأ غير متوقع.', trace: 'srv-trace-9'));

    final taken = await ServerAuth.register(
      role: 'seeker',
      name: 'فيصل',
      phone: '0551234567',
      city: 'الرياض',
      username: 'faisal',
      password: 'a-good-secret',
    );
    final broken = await ServerAuth.resend('0551234567');

    expect(taken, 'اسم المستخدم مستخدم من حساب آخر.');
    expect(broken, 'حدث خطأ غير متوقع. — رقم التتبّع srv-trace-9');
  });

  test('an answer outside the envelope is UNEXPECTED, under the request\'s own trace id', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'catalog', 502, '<html>Bad Gateway</html>');

    final error = await Api.client!.get('catalog').then<ApiError?>((_) => null, onError: (Object e) => e as ApiError);

    expect(error!.code, 'UNEXPECTED');
    expect(error.traceId, server.requests.single.headers['X-Request-Id']);
  });

  test('versions compare number by number, as the server does', () {
    expect(compareVersions('1.2.1', '1.2.1'), 0);
    expect(compareVersions('1.2.1', '1.3.0'), lessThan(0));
    expect(compareVersions('1.2.10', '1.2.9'), greaterThan(0));
    expect(compareVersions('2.0.0', '1.99.99'), greaterThan(0));
    expect(compareVersions('1.2', '1.2.0'), 0);
  });
}
