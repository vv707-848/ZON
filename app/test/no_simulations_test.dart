// The build with the simulations OFF must not pretend: no simulated payment,
// contract approval or licence number, and nothing made up on screen: no
// sample listing, request, chat, client, owner, follower or task, no made-up
// count or name, no number to call that nobody gave. These tests need the
// define, so they are skipped in the normal run. Run them with:
//
//   flutter test --dart-define=ZON_SIMULATIONS=false test/no_simulations_test.dart

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/store.dart';

import 'support/test_env.dart';

const bool _defined = bool.hasEnvironment('ZON_SIMULATIONS');
const String _how = 'run with --dart-define=ZON_SIMULATIONS=false';

void main() {
  group('simulations off', skip: _defined && !kSimulations ? null : _how, () {
    test('a charge reports «unavailable» and nothing is granted', () async {
      await freshDevice();
      signUp('nosim.broker', role: 'broker');
      final receipt = await billing.buyPlan(Plans.brokerYear);
      expect(receipt.status, PaymentStatus.unavailable);
      expect(billing.activeSubscription(), isNull);
      expect(billing.ledger(), isEmpty);
    });

    testWidgets('the pay dialog says payment is not available and its pay button is off', (tester) async {
      await freshDevice();
      signUp('nosim.dialog', role: 'broker');
      await pumpApp(tester, '/account/plans');
      await tapText(tester, 'اشترك في باقة الوسيط');
      expect(find.text('الدفع غير متاح في هذه النسخة حتى ربط بوابة الدفع.'), findsOneWidget);
      expect(find.textContaining('دفع تجريبي'), findsNothing);
      await tapText(tester, 'ادفع 2,300 ريال');
      await tester.pump(const Duration(milliseconds: 300));
      expect(billing.activeSubscription(), isNull);
    });

    testWidgets('owner path: no simulated contract approval, fee or licence number', (tester) async {
      await freshDevice();
      signUp('nosim.owner', role: 'owner');
      final c = ownerCases.submitContract(deedNo: '310123456789');
      await pumpApp(tester, '/add?type=listing');
      expect(find.text('بانتظار موافقتك'), findsOneWidget);
      expect(find.textContaining('محاكاة موافقة العقد'), findsNothing);
      final receipt = await ownerCases.payLicenceFee(c.id);
      expect(receipt.ok, isFalse);
      expect(ownerCases.byId(c.id)!.licenceNumber, isNull);
      expect(ownerCases.planActive(), isFalse);
    });

    test('a new account sees only what it made: no sample of any kind', () async {
      await freshDevice();
      signUp('nosim.empty', role: 'broker');
      expect(store.allListings(), isEmpty);
      expect(store.allRequests(), isEmpty);
      expect(store.chats(), isEmpty);
      expect(store.clients(), isEmpty);
      expect(store.owners(), isEmpty);
      expect(store.inventory(), isEmpty);
      expect(store.follows().followers, isEmpty);
      expect(store.follows().following, isEmpty);
      expect(store.tasks(), isEmpty);
    });

    testWidgets('a public profile shows no made-up counts and no rating that saves nothing', (tester) async {
      await freshDevice();
      signUp('nosim.profile', role: 'seeker');
      await pumpApp(tester, '/users/${Uri.encodeComponent('فهد العتيبي')}');
      expect(find.text('المتابِعون'), findsNothing);
      expect(find.text('تقييم'), findsNothing);
      expect(find.text('متابعة'), findsOneWidget);
    });

    testWidgets('the main screens open empty without an error', (tester) async {
      await freshDevice();
      signUp('nosim.screens', role: 'broker');
      for (final route in <String>['/', '/requests', '/chats', '/account']) {
        await pumpApp(tester, route);
        expect(tester.takeException(), isNull, reason: route);
      }
    });
  });
}
