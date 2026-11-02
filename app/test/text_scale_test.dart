// Q01 (accessibility) — the app follows the phone's larger text setting up
// to ×1.2 (see app/zon_app.dart) and the main screens must lay out without
// overflow at the smallest phone width (360) with that setting.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';

import 'support/test_env.dart';

const List<String> _routes = <String>[
  '/',
  '/requests',
  '/chats',
  '/account',
  '/account/plans',
  '/account/search',
  '/account/listings',
  '/account/requests',
  '/account/tasks',
  '/account/settings',
  '/notifications',
  '/listings/l1',
  '/projects/p1',
  '/add?type=request',
  '/add?type=listing',
];

void main() {
  for (final role in <String>['broker', 'seeker', 'owner', 'developer']) {
    testWidgets('no overflow at 360 wide with text ×1.2 — $role', (tester) async {
      await freshDevice();
      signUp('scale.$role', role: role);
      if (role == 'broker') billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      if (role == 'seeker') {
        store.saveWish(const Wish(city: 'الرياض', hoods: <String>['الملقا'], types: <String>[], budget: '', text: 'فيلا قرب المدارس'));
      }
      publishTestListing('scale-$role');
      tester.platformDispatcher.textScaleFactorTestValue = 1.2;
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      final failures = <String>[];
      final extra = <String>[
        if (role == 'seeker' || role == 'developer') '/account/matching',
        if (role == 'broker') ...<String>['/account/clients', '/account/owners', '/account/inventory'],
        if (role == 'developer') '/add?type=project',
      ];
      for (final r in <String>[..._routes, ...extra]) {
        await pumpApp(tester, r, size: const Size(360, 780));
        await tester.pump(const Duration(milliseconds: 300));
        final e = tester.takeException();
        if (e != null) failures.add('$r: ${e.toString().split('\n').first}');
      }
      expect(failures, isEmpty, reason: failures.join('\n'));
    });
  }
}
