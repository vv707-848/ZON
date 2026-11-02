// The build with the simulations OFF must not pretend: no simulated payment,
// contract approval or licence number. These tests need the define, so they
// are skipped in the normal run. Run them with:
//
//   flutter test --dart-define=ZON_SIMULATIONS=false test/no_simulations_test.dart

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/billing/owner_cases.dart';

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
  });
}
