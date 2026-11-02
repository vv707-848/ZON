// Q01 TASK-05 (title of spaces), the task date in Arabic, and the listing
// boosts «مميز» / «عاجل» from «عروضي» (quota first, then the confirmed
// price, never twice).

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/tasks_view.dart';

import 'support/test_env.dart';

void main() {
  group('tasks', () {
    testWidgets('TASK-05: a title of spaces is refused with a message; saved titles are trimmed', (tester) async {
      await freshDevice();
      signUp('t05.user', role: 'broker');
      await pumpApp(tester, '/account/tasks');
      await tapText(tester, 'مهمة جديدة');
      final title = find.ancestor(of: find.text('روح أكلم خالد يشتري الفيلا'), matching: find.byType(TextField));
      await tester.enterText(title, '     ');
      await tester.pump();
      await tapText(tester, 'حفظ المهمة');
      expect(find.textContaining('لا يكفي فراغ'), findsOneWidget);
      expect(store.getItem(store.scopedKey('zon.tasks.v1')), isNull, reason: 'nothing saved');

      await tester.enterText(title, '  متابعة عميل النرجس  ');
      await tester.pump();
      await tapText(tester, 'حفظ المهمة');
      expect(store.tasks().first.title, 'متابعة عميل النرجس');
    });

    testWidgets('the reminder is shown in full on the task card (360 wide, text ×1.2)', (tester) async {
      await freshDevice();
      signUp('task.when', role: 'broker');
      store.saveTasks(const <Task>[Task(id: 'w1', title: 'معاينة فيلا الملقا مع العميل', pri: 'مهم', remindAt: '2026-10-12T16:05')]);
      tester.platformDispatcher.textScaleFactorTestValue = 1.2;
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      await pumpApp(tester, '/account/tasks', size: const Size(360, 780));
      final p = tester.renderObject<RenderParagraph>(find.text('12 أكتوبر، 4:05 م'));
      final fits = p.getMaxIntrinsicWidth(double.infinity) <= p.size.width + 0.5;
      final wraps = p.softWrap && p.getMinIntrinsicWidth(double.infinity) <= p.size.width + 0.5;
      expect(!p.didExceedMaxLines && (fits || wraps), isTrue, reason: 'the reminder must not be cut (${p.size.width} wide)');
      expect(tester.takeException(), isNull);
    });

    test('the reminder is shown in Arabic — never English months or "Invalid Date"', () {
      expect(remindLabel('2026-10-12T16:05'), '12 أكتوبر، 4:05 م');
      expect(remindLabel('2026-01-03T09:30'), '3 يناير، 9:30 ص');
      expect(remindLabel('not a date'), isNull);
      expect(remindLabel(''), isNull);
    });
  });

  group('listing boosts in «عروضي»', () {
    testWidgets('yearly broker: «مميز» from the quota after one confirmation, shown with its usage', (tester) async {
      await freshDevice();
      signUp('boost.ui', role: 'broker');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      publishTestListing('bl-1');
      await pumpApp(tester, '/account/listings');
      expect(find.text('المتبقي من رصيدك السنوي: 15 تمييزًا مميزًا'), findsOneWidget);
      expect(find.text('المتبقي من رصيدك السنوي: 10 تمييزات عاجلة'), findsOneWidget);

      await tapText(tester, 'تمييز');
      expect(find.text('استخدم الرصيد'), findsOneWidget);
      expect(find.textContaining('يبقى بعده 14 تمييزًا مميزًا'), findsOneWidget);
      await tapText(tester, 'استخدم الرصيد');
      expect(store.listingById('bl-1')!.featuredAt(DateTime.now()), isTrue);
      expect(find.text('المتبقي من رصيدك السنوي: 14 تمييزًا مميزًا'), findsOneWidget);

      // tapping the featured listing again takes nothing
      await tapText(tester, 'مميّز');
      expect(find.text('العرض مميز حاليًا'), findsOneWidget);
      await tapText(tester, 'حسناً');
      expect(billing.remaining(BoostKind.featured), 14);
    });

    testWidgets('no plan: «عاجل» shows 100 first; cancel → nothing; pay → urgent once', (tester) async {
      await freshDevice();
      signUp('boost.pay', role: 'broker');
      publishTestListing('bl-2');
      await pumpApp(tester, '/account/listings');
      expect(find.text('تمييز «عاجل» — 100 ريال'), findsOneWidget);
      await tapText(tester, 'عاجل');
      expect(find.text('تمييز العرض «عاجل» — 100 ريال'), findsOneWidget);
      await tapText(tester, 'إلغاء', last: true);
      expect(store.listingById('bl-2')!.urgentAt(DateTime.now()), isFalse);
      expect(billing.ledger(), isEmpty);

      await tapText(tester, 'عاجل');
      await tapText(tester, 'ادفع 100 ريال');
      await tester.pump(const Duration(milliseconds: 300));
      expect(store.listingById('bl-2')!.urgentAt(DateTime.now()), isTrue);
      expect(billing.ledger().single.amount, 100);
    });
  });
}
