// P01 / P03 / P04 — the three plans: short texts, prices from Pricing, no
// defensive paragraphs, subscription through the simulated payment, and the
// owner plan active only after an approved contract AND a published listing.

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/listing_form.dart';
import 'package:zon/features/details/listing_screen.dart';

import 'support/test_env.dart';

/// Phrases removed from the plans page (brief P01).
const List<String> _defensive = <String>['خارج التطبيق', 'لا نبيع', 'لا نحصّل', 'ليس شراء', 'أجر أدوات', 'ثمن عقار', 'ثمن العقار', 'نفس المزايا'];

void expectNoDefensiveText() {
  for (final p in _defensive) {
    expect(find.textContaining(p), findsNothing, reason: 'نص دفاعي: $p');
  }
}

Future<void> _fill(WidgetTester tester, String hint, String value) async {
  final field = find.ancestor(of: find.text(hint), matching: find.byType(TextField));
  expect(field, findsWidgets, reason: 'حقل: $hint');
  await tester.ensureVisible(field.first);
  await tester.enterText(field.first, value);
  await tester.pump();
}

void main() {
  group('P01 — broker plan', () {
    testWidgets('short text: 2,300 yearly, save 1,060 vs 280 monthly, no defensive paragraphs', (tester) async {
      await freshDevice();
      signUp('p01.broker', role: 'broker');
      await pumpApp(tester, '/account/plans');
      for (final t in <String>[
        'باقة الوسيط',
        'سعيك كامل. وأعمالك أكثر تنظيمًا.',
        'وفّر 1,060 ريال مقارنة بالاشتراك الشهري',
        'إعلانات غير محدودة',
        'الاحتفاظ بكامل السعي',
        'الوصول إلى طلبات العملاء في مدينتك',
        'بحث متقدم في العروض والطلبات',
        'تنظيم الملاحظات ومتابعة الملاك',
        'اشترك في باقة الوسيط',
      ]) {
        expect(find.text(t), findsWidgets, reason: t);
      }
      expect(find.text('2,300'), findsOneWidget);
      expect(find.text('سنويًا'), findsOneWidget);
      // 10 % of the commission (السعي), never of the property price
      expect(find.textContaining('بـ10٪ من السعي'), findsWidgets);
      expect(find.textContaining('من قيمة العقار'), findsNothing);
      expectNoDefensiveText();
      // the counters and overage prices are not on the plan card
      expect(find.textContaining('رصيدك'), findsNothing);

      await tapText(tester, 'شهري');
      expect(find.text('280'), findsOneWidget);
      expect(find.text('شهريًا'), findsOneWidget);
      expectNoDefensiveText();
    });

    testWidgets('subscribe: price shown first, cancel changes nothing, pay activates', (tester) async {
      await freshDevice();
      signUp('p01.pay', role: 'broker');
      await pumpApp(tester, '/account/plans');
      await tapText(tester, 'اشترك في باقة الوسيط');
      expect(find.text('ادفع 2,300 ريال'), findsOneWidget);
      expect(find.textContaining('دفع تجريبي'), findsOneWidget);
      await tapText(tester, 'إلغاء');
      expect(billing.subPlan(), isNull);

      await tapText(tester, 'اشترك في باقة الوسيط');
      await tapText(tester, 'ادفع 2,300 ريال');
      await tester.pump(const Duration(milliseconds: 300));
      expect(billing.subPlan(), Plans.brokerYear);
      expect(find.text('الباقة مفعّلة'), findsWidgets);
    });

    testWidgets('the pay button shows the whole amount (360 wide, text ×1.2)', (tester) async {
      await freshDevice();
      signUp('p01.amount', role: 'broker');
      tester.platformDispatcher.textScaleFactorTestValue = 1.2;
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      await pumpApp(tester, '/account/plans', size: const Size(360, 780));
      await tapText(tester, 'اشترك في باقة الوسيط');
      final label = tester.renderObject<RenderParagraph>(find.text('ادفع 2,300 ريال'));
      expect(label.didExceedMaxLines, isFalse, reason: 'the amount must not be cut («ادفع 2,300 ر…»)');
      expect(tester.takeException(), isNull);
    });
  });

  group('P03 — seeker plan', () {
    testWidgets('199 monthly, short features, free line, no «طلب جاد»', (tester) async {
      await freshDevice();
      signUp('p03.seeker', role: 'seeker');
      await pumpApp(tester, '/account/plans');
      for (final t in <String>[
        'باقة الباحث',
        'عروض أقرب إلى رغبتك',
        'مساعد المطابقة',
        'تنبيهات بالعروض المناسبة',
        'بحث عقاري بلغتك',
        'عروض مطابقة وخيارات إضافية',
        'وصول مباشر إلى المطورين والملاك',
        'اشترك في باقة الباحث',
        'تصفح العروض والمشاريع، والفلاتر، والطلبات العادية، والمفضلة والتواصل.',
      ]) {
        expect(find.text(t), findsWidgets, reason: t);
      }
      expect(find.text('199'), findsOneWidget);
      expect(find.text('شهريًا'), findsOneWidget);
      expect(find.textContaining('طلب جاد'), findsNothing);
      expect(find.textContaining('مضمون'), findsNothing);
      expectNoDefensiveText();
    });
  });

  group('P04 — owner plan', () {
    testWidgets('texts; «ابدأ نشر عقارك» opens the add flow and does NOT activate the plan', (tester) async {
      await freshDevice();
      signUp('p04.owner', role: 'owner');
      await pumpApp(tester, '/account/plans');
      for (final t in <String>[
        'باقة المالك',
        'انشر عقارك بسعي واضح',
        'سعي بعد بيع العقار المعلن',
        'عقد وساطة موثّق',
        'ظهور عرضك في البحث والمطابقة',
        'تنسيق وصف العقار قبل النشر',
        'تواصل مباشر مع المهتمين',
        'رسوم الترخيص الإعلاني: 50 ريالًا للهيئة العامة للعقار',
        'تتفعّل الباقة بعد إتمام عقد الوساطة ونشر العرض.',
      ]) {
        expect(find.text(t), findsWidgets, reason: t);
      }
      expect(find.text('0.5٪'), findsOneWidget);
      expect(find.textContaining('اشتراك شهري'), findsNothing);
      expect(find.textContaining('اشتراك سنوي'), findsNothing);
      expectNoDefensiveText();

      await tapText(tester, 'ابدأ نشر عقارك');
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(ListingForm), findsOneWidget);
      expect(billing.subPlan(), isNull);
      expect(ownerCases.planActive(), isFalse);
    });

    test('form, contract and publishing are separate states — both conditions are needed', () async {
      await freshDevice();
      signUp('p04.states', role: 'owner');
      final c = ownerCases.submitContract(deedNo: '310111222333');
      expect(c.stage, 'contract');
      expect(ownerCases.planActive(), isFalse);
      // a simulated fee cannot be paid before the contract is approved
      expect((await ownerCases.payLicenceFee(c.id)).ok, isFalse);
      // publishing without an approved contract does not activate the plan
      publishTestListing('own-early');
      ownerCases.markPublished(c.id, 'own-early');
      expect(ownerCases.planActive(), isFalse);

      final c2 = ownerCases.submitContract(deedNo: '310999');
      ownerCases.approveContract(c2.id, reference: 'TEST');
      expect(ownerCases.planActive(), isFalse, reason: 'approved but not published');
      expect((await ownerCases.payLicenceFee(c2.id)).ok, isTrue);
      publishTestListing('own-2');
      ownerCases.markPublished(c2.id, 'own-2');
      expect(ownerCases.planActive(), isTrue);
      expect(billing.subPlan(), Plans.owner);
      expect(ownerCases.activeCase()!.listingId, 'own-2', reason: 'the commission belongs to this property');
      expect(ownerCases.activeCase()!.commissionPercent, 0.5);
    });

    testWidgets('full path in the app: contract → approval → fee → details → publish → «الباقة مفعّلة»', (tester) async {
      await freshDevice();
      signUp('p04.flow', role: 'owner');
      ownerCases.submitContract(deedNo: '310123456789');
      await pumpApp(tester, '/add?type=listing');
      // resumes at the contract step
      expect(find.text('بانتظار موافقتك'), findsOneWidget);
      expect(ownerCases.planActive(), isFalse);
      await tapText(tester, 'محاكاة موافقة العقد (نسخة تجريبية)');
      expect(find.text('موثّق'), findsOneWidget);
      await tapText(tester, 'ادفع رسوم الترخيص — 50 ريالًا');
      expect(find.textContaining('ليست اشتراكًا'), findsOneWidget);
      await tapText(tester, 'ادفع 50 ريالًا');
      await tester.pump(const Duration(milliseconds: 300));
      expect(ownerCases.current()!.licenceNumber, isNotNull);
      expect(find.textContaining('رقم تجريبي'), findsOneWidget);
      expect(ownerCases.planActive(), isFalse, reason: 'not published yet');

      await tapText(tester, 'متابعة إلى تفاصيل العرض');
      await _fill(tester, 'أدخل عنوان العقار', 'فيلا المالك في الملقا');
      await _fill(tester, 'ر.س', '2400000');
      await _fill(tester, 'أضف وصفًا للعقار', 'فيلا بمدخلين قرب المدارس');
      await tapText(tester, 'متابعة');
      await tapText(tester, 'قبول ونشر');
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(ListingScreen), findsOneWidget);
      expect(ownerCases.planActive(), isTrue);
      final published = store.listingById(ownerCases.activeCase()!.listingId!)!;
      expect(published.license, ownerCases.activeCase()!.licenceNumber);

      await pumpApp(tester, '/account/plans');
      expect(find.text('الباقة مفعّلة'), findsWidgets);
      expect(find.textContaining('فيلا المالك في الملقا'), findsWidgets);
    });

    testWidgets('EDIT-02 in the add form: a negative price is refused; Arabic digits are read', (tester) async {
      await freshDevice();
      signUp('p04.price', role: 'owner');
      final c = ownerCases.submitContract(deedNo: '310123456780');
      ownerCases.approveContract(c.id, reference: 'test');
      await ownerCases.payLicenceFee(c.id);
      await pumpApp(tester, '/add?type=listing'); // licence issued → resumes at the details
      await _fill(tester, 'أدخل عنوان العقار', 'فيلا للبيع في الملقا');
      await _fill(tester, 'ر.س', '-100');
      await _fill(tester, 'أضف وصفًا للعقار', 'فيلا بمدخلين');
      await tapText(tester, 'متابعة');
      expect(find.text('السعر لا يكون سالبًا'), findsOneWidget);
      expect(find.text('قبول ونشر'), findsNothing, reason: 'the review step does not open');
      expect(store.myListings(), isEmpty);

      await _fill(tester, 'ر.س', '٢٤٠٠٠٠٠');
      await tapText(tester, 'متابعة');
      await tapText(tester, 'قبول ونشر');
      await tester.pump(const Duration(milliseconds: 400));
      expect(store.myListings().single.price, 2400000);
    });
  });
}
