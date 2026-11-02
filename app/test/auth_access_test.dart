// Q01 — accounts, sessions and per-account isolation (AUTH-02, AUTH-03,
// ACCESS-02, ACCESS-03).

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';

import 'support/test_env.dart';

void main() {
  group('AUTH-02 — the account is separate from the session', () {
    test('sign up → sign out → sign in with the same details works', () async {
      await freshDevice();
      signUp('fahad.b', password: 'Strong-pass-1');
      expect(store.readSession(), isNotNull);
      LocalAuth.signOut();
      expect(store.readSession(), isNull);
      final again = LocalAuth.signIn('fahad.b', 'Strong-pass-1');
      expect(again.ok, isTrue, reason: again.error);
      expect(again.session!.username, 'fahad.b');
    });

    test('a wrong password is refused with a clear message', () async {
      await freshDevice();
      signUp('noura.s', password: 'Strong-pass-1');
      LocalAuth.signOut();
      final r = LocalAuth.signIn('noura.s', 'wrong-password');
      expect(r.ok, isFalse);
      expect(r.error, 'اسم المستخدم أو كلمة السر غير صحيحة');
      expect(store.readSession(), isNull);
    });

    test('the password is never stored in plain text', () async {
      await freshDevice();
      signUp('salem.k', password: 'Plain-text-check-9');
      final everything = <String>[
        for (final k in <String>['zon.accounts.v1', 'zon.session.v1']) store.getItem(k) ?? '',
      ].join();
      expect(everything.contains('Plain-text-check-9'), isFalse);
      final account = store.accountByUsername('salem.k')!;
      expect(account.hash, isNotEmpty);
      expect(account.salt, isNotEmpty);
      expect(jsonDecode(store.getItem('zon.session.v1')!) as Map, isNot(contains('password')));
    });

    test('the same username cannot be taken twice', () async {
      await freshDevice();
      signUp('majed');
      LocalAuth.signOut();
      final r = LocalAuth.signUp(name: 'آخر', phone: '0559998877', username: 'MAJED', password: 'another-pass', role: 'seeker', city: 'جدة');
      expect(r.ok, isFalse);
      expect(r.error, contains('مستخدم'));
    });

    test('a session saved by version 1.1 becomes an account without a plain password', () async {
      await freshDevice(<String, Object>{
        'zon.session.v1': jsonEncode(<String, Object>{
          'name': 'مستخدم قديم',
          'username': 'old.user',
          'password': 'old-password-1',
          'phone': '0550001111',
          'role': 'broker',
          'city': 'الرياض',
        }),
        'zon.tasks.v1': jsonEncode(<Object>[
          <String, Object>{'id': 'tx', 'title': 'مهمة قديمة', 'pri': 'مهم'},
        ]),
        'zon.sub.v1': jsonEncode('broker-year'),
      });
      final s = store.readSession()!;
      expect(s.id, startsWith('acc-'));
      expect(store.getItem('zon.session.v1')!.contains('old-password-1'), isFalse);
      // the 1.1 tasks belong to this account now
      expect(store.tasks().map((t) => t.title), contains('مهمة قديمة'));
      // the 1.1 broker plan became one yearly period
      expect(billing.subPlan(), Plans.brokerYear);
      // and the old password still signs in
      LocalAuth.signOut();
      expect(LocalAuth.signIn('old.user', 'old-password-1').ok, isTrue);
    });
  });

  group('AUTH-03 — phone format before the code step', () {
    test('letters are refused, Saudi mobile formats are accepted', () async {
      await freshDevice();
      expect(LocalAuth.phoneError('abc'), isNotNull);
      expect(LocalAuth.phoneError('12345'), isNotNull);
      expect(LocalAuth.phoneError('0551234567'), isNull);
      expect(LocalAuth.phoneError('+966 55 123 4567'), isNull);
      expect(LocalAuth.phoneError('٠٥٥١٢٣٤٥٦٧'), isNull);
      final r = LocalAuth.signUp(name: 'س', phone: 'abc', username: 'phone.test', password: 'secret-123', role: 'seeker', city: 'الرياض');
      expect(r.ok, isFalse);
      expect(store.accountByUsername('phone.test'), isNull);
    });
  });

  group('ACCESS-03 — private data stays with its account', () {
    test('tasks, subscription and quota of account A are not loaded for account B', () async {
      await freshDevice();
      signUp('account.a');
      store.saveTasks(const <Task>[Task(id: 'a1', title: 'مهمة الحساب أ', pri: 'مهم')]);
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      final l = publishTestListing('la-1');
      expect(billing.useQuota(BoostKind.featured, l.id), BoostOutcome.applied);
      expect(billing.remaining(BoostKind.featured), 14);

      LocalAuth.signOut();
      signUp('account.b');
      expect(store.tasks().map((t) => t.title), isNot(contains('مهمة الحساب أ')));
      expect(billing.subPlan(), isNull);
      expect(billing.isSubscribed(), isFalse);
      expect(billing.remaining(BoostKind.featured), 0);

      // back to A after a restart: everything is still there
      LocalAuth.signOut();
      await restartApp();
      expect(LocalAuth.signIn('account.a', 'secret-123').ok, isTrue);
      expect(store.tasks().map((t) => t.title), contains('مهمة الحساب أ'));
      expect(billing.subPlan(), Plans.brokerYear);
      expect(billing.remaining(BoostKind.featured), 14);
    });
  });

  group('ACCESS-02 — ownership', () {
    test('only the publishing account may edit a listing', () async {
      await freshDevice();
      signUp('owner.one');
      publishTestListing('own-1');
      expect(store.canEditListing('own-1'), isTrue);
      // sample listings belong to nobody
      expect(store.canEditListing('l1'), isFalse);
      LocalAuth.signOut();
      expect(store.canEditListing('own-1'), isFalse, reason: 'guest');
      signUp('other.two');
      expect(store.canEditListing('own-1'), isFalse, reason: 'another account');
      expect(store.myListings().map((l) => l.id), isNot(contains('own-1')));
    });
  });
}
