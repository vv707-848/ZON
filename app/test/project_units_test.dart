// QA F03 / F04 / F07 — the units of a published project are written only
// after their checks: a unit added in the editor is a draft until it is
// saved, «حفظ الوحدة» refuses a ready unit without a verified licence and a
// price that is negative or not a number (keeping the published values),
// every write checks the owner, and saving never re-publishes a hidden
// project. Also the unit prices of the add form.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/project_editor.dart';
import 'package:zon/features/add/widgets/add_kit.dart';
import 'package:zon/features/details/project_screen.dart';

import 'support/test_env.dart';

Map<String, Object?> _unit(String id, {String name = 'A1', num price = 900000, String? license = '7200000001', bool sold = false}) =>
    <String, Object?>{
      'id': id,
      'name': name,
      'price': price,
      'image': '/listings/apt-1.jpg',
      'description': '',
      'sold': sold,
      'license': ?license,
      if (license != null) 'licenseVerified': true,
    };

/// A project of the signed-in developer.
Listing _project(String id, {String delivery = 'ready', bool active = true, List<Map<String, Object?>>? units}) {
  final rows = units ?? <Map<String, Object?>>[_unit('u-a')];
  final sold = rows.where((u) => u['sold'] == true).length;
  store.saveListing(
    Listing.fromJson(<String, Object?>{
      'id': id,
      'kind': 'project',
      'title': 'مشروع الاختبار',
      'city': 'الرياض',
      'neighborhood': 'الملقا',
      'propertyType': 'apartment',
      'source': 'developer',
      'delivery': delivery,
      'description': 'وصف المشروع',
      'advertiser': 'مطور',
      'advertiserRole': 'developer',
      'license': '',
      'active': active,
      'available': rows.length - sold,
      'sold': sold,
      'units': rows,
    }),
  );
  return store.listingById(id)!;
}

String _stored(String id) => jsonEncode(store.listingById(id)!.toJson());

List<UnitModel> _units(String id) => store.listingById(id)!.units!;

Finder _inField(String label) => find.descendant(
  of: find.ancestor(of: find.text(label), matching: find.byType(ProjField)).first,
  matching: find.byType(EditableText),
);

/// The price field of the [i]th open unit form.
Finder _price(int i) => find.descendant(
  of: find.ancestor(of: find.text('السعر الثابت').at(i), matching: find.byType(ProjField)),
  matching: find.byType(EditableText),
);

Finder get _licenceInputs => find.descendant(of: find.byType(UnitLicenceField), matching: find.byType(EditableText));

Future<void> _enter(WidgetTester tester, Finder f, String text) async {
  await tester.ensureVisible(f);
  await tester.enterText(f, text);
  await tester.pump();
}

void main() {
  group('F03 — a unit is written only after its checks', () {
    testWidgets('«إضافة وحدة» then back: the published project is unchanged', (tester) async {
      await freshDevice();
      signUp('f03.back', role: 'developer');
      _project('pf-1');
      final before = _stored('pf-1');
      await pumpApp(tester, '/account/listings/pf-1');
      await tapText(tester, 'إضافة وحدة');
      expect(find.byType(ProjectUnitEditor), findsNWidgets(2), reason: 'the draft is in the editor');
      expect(_stored('pf-1'), before, reason: 'and nowhere else');
      expect(store.listingById('pf-1')!.available, 1);

      await tester.ensureVisible(find.byType(BackCircle));
      await tester.pump();
      await tester.tap(find.byType(BackCircle));
      // the page transition back to «عروضي»
      for (var i = 0; i < 20 && find.byType(ProjectEditor).evaluate().isNotEmpty; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      expect(find.byType(ProjectEditor), findsNothing);
      expect(_stored('pf-1'), before);
      await pumpApp(tester, '/projects/pf-1');
      expect(find.byType(ProjectScreen), findsOneWidget);
      expect(_units('pf-1'), hasLength(1));
    });

    testWidgets('clearing a ready unit’s licence and saving it is refused; the number is kept', (tester) async {
      await freshDevice();
      signUp('f03.licence', role: 'developer');
      _project('pf-2');
      final before = _stored('pf-2');
      await pumpApp(tester, '/account/listings/pf-2');
      await tapText(tester, 'تعديل');
      await _enter(tester, _licenceInputs.first, '');
      await tapText(tester, 'حفظ الوحدة');
      expect(find.text('أكمل رقم الترخيص الإعلاني لهذه الوحدة وتحقق منه'), findsOneWidget);
      expect(_stored('pf-2'), before, reason: 'the published unit is unchanged');
      expect(_units('pf-2').single.license, '7200000001');
      expect(_units('pf-2').single.licenseVerified, isTrue);

      // a new number that was not verified is refused as well
      await _enter(tester, _licenceInputs.first, '7200009999');
      await tapText(tester, 'حفظ الوحدة');
      expect(_units('pf-2').single.license, '7200000001');
      // verified → saved
      await tapText(tester, 'تحقق');
      await tapText(tester, 'حفظ الوحدة');
      expect(_units('pf-2').single.license, '7200009999');
      expect(find.text('الترخيص 7200009999'), findsOneWidget);
    });

    testWidgets('a new unit of a ready project: refused without a licence, written once verified', (tester) async {
      await freshDevice();
      signUp('f03.new', role: 'developer');
      _project('pf-3');
      await pumpApp(tester, '/account/listings/pf-3');
      await tapText(tester, 'إضافة وحدة');
      await tapText(tester, 'حفظ الوحدة');
      expect(find.text('أكمل رقم الترخيص الإعلاني لهذه الوحدة وتحقق منه'), findsOneWidget);
      expect(_units('pf-3'), hasLength(1));

      await _enter(tester, _licenceInputs.last, '٧٢٠٠٠٠٥٥٥٥'); // Arabic digits
      await tapText(tester, 'تحقق');
      await _enter(tester, _price(0), '٨٥٠٬٠٠٠');
      await tapText(tester, 'حفظ الوحدة');
      final units = _units('pf-3');
      expect(units, hasLength(2));
      expect(units.last.license, '7200005555', reason: 'Arabic digits are stored as digits');
      expect(units.last.licenseVerified, isTrue);
      expect(units.last.price, 850000);
      expect(store.listingById('pf-3')!.available, 2);
      expect(store.listingById('pf-3')!.sold, 0);
    });

    testWidgets('off-plan: no licence field and none required — the new unit is saved', (tester) async {
      await freshDevice();
      signUp('f03.offplan', role: 'developer');
      _project('pf-4', delivery: 'offplan', units: <Map<String, Object?>>[_unit('u-a', license: null)]);
      await pumpApp(tester, '/account/listings/pf-4');
      await tapText(tester, 'إضافة وحدة');
      expect(find.byType(UnitLicenceField), findsNothing);
      await tapText(tester, 'حفظ الوحدة');
      expect(find.byType(FormError), findsNothing);
      expect(_units('pf-4'), hasLength(2));
      expect(_units('pf-4').last.license, isNull);
    });

    testWidgets('sold / available and delete keep the counters right; deleting a published unit asks first', (tester) async {
      await freshDevice();
      signUp('f03.delete', role: 'developer');
      _project(
        'pf-5',
        units: <Map<String, Object?>>[
          _unit('u-a'),
          _unit('u-b', name: 'B1', license: '7200000002'),
        ],
      );
      await pumpApp(tester, '/account/listings/pf-5');

      await tapText(tester, 'مباع', index: 1);
      expect(_units('pf-5').map((u) => u.sold), <bool>[false, true]);
      expect(store.listingById('pf-5')!.available, 1);
      expect(store.listingById('pf-5')!.sold, 1);

      await tapText(tester, 'حذف', index: 0);
      expect(find.text('حذف الوحدة؟'), findsOneWidget);
      await tapText(tester, 'إلغاء', last: true);
      expect(_units('pf-5'), hasLength(2), reason: 'cancelled: nothing removed');

      await tapText(tester, 'حذف', index: 0);
      await tapText(tester, 'حذف', last: true);
      expect(_units('pf-5').map((u) => u.id), <String>['u-b']);
      expect(store.listingById('pf-5')!.available, 0);
      expect(store.listingById('pf-5')!.sold, 1);

      await tapText(tester, 'متاح', index: 0);
      expect(store.listingById('pf-5')!.available, 1);
      expect(store.listingById('pf-5')!.sold, 0);

      // a draft goes away without a question and without a write
      final before = _stored('pf-5');
      await tapText(tester, 'إضافة وحدة');
      await tapText(tester, 'حذف', index: 1);
      expect(find.text('حذف الوحدة؟'), findsNothing);
      expect(find.byType(ProjectUnitEditor), findsOneWidget);
      expect(_stored('pf-5'), before);
    });

    testWidgets('the owner is checked before every write', (tester) async {
      await freshDevice();
      signUp('f03.owner', role: 'developer');
      _project('pf-6');
      final before = _stored('pf-6');
      await pumpApp(tester, '/account/listings/pf-6');
      await tapText(tester, 'تعديل');
      await _enter(tester, _price(0), '990000');
      // another account signs in while the editor is still open
      LocalAuth.signOut();
      signUp('f03.other', role: 'developer');
      await tapText(tester, 'حفظ الوحدة');
      expect(find.text('لا يمكنك تعديل هذا المشروع.'), findsWidgets);
      await tapText(tester, 'مباع');
      await tapText(tester, 'حفظ المشروع');
      expect(_stored('pf-6'), before);
    });
  });

  group('F04 — unit prices', () {
    testWidgets('editor: a negative or invalid price is refused and the price is kept; Arabic digits are read', (tester) async {
      await freshDevice();
      signUp('f04.edit', role: 'developer');
      _project('pf-7');
      await pumpApp(tester, '/account/listings/pf-7');
      await tapText(tester, 'تعديل');
      final price = _price(0);

      await _enter(tester, price, '-100');
      await tapText(tester, 'حفظ الوحدة');
      expect(find.text('السعر لا يكون سالبًا'), findsOneWidget);
      expect(_units('pf-7').single.price, 900000);

      await _enter(tester, price, '12.5.1');
      await tapText(tester, 'حفظ الوحدة');
      expect(find.text('أدخل السعر بالأرقام'), findsOneWidget);
      expect(_units('pf-7').single.price, 900000, reason: 'never a silent 0');

      await _enter(tester, price, '٩٥٠٠٠٠');
      await tapText(tester, 'حفظ الوحدة');
      expect(find.byType(FormError), findsNothing);
      expect(_units('pf-7').single.price, 950000);
    });

    testWidgets('editor: an empty price is still 0, as before (a product decision)', (tester) async {
      await freshDevice();
      signUp('f04.zero', role: 'developer');
      _project('pf-8');
      await pumpApp(tester, '/account/listings/pf-8');
      await tapText(tester, 'تعديل');
      await _enter(tester, _price(0), '');
      await tapText(tester, 'حفظ الوحدة');
      expect(_units('pf-8').single.price, 0);
    });

    testWidgets('add form: a negative or invalid unit price stops «متابعة» and names the unit', (tester) async {
      await freshDevice();
      signUp('f04.add', role: 'developer');
      await pumpApp(tester, '/add?type=project');
      await _enter(tester, _inField('العنوان'), 'مشروع الأسعار');
      await _enter(tester, find.ancestor(of: find.text('قريب من الخدمات والمدارس والمسجد…'), matching: find.byType(TextField)), 'قريب من الخدمات');
      await _enter(tester, _licenceInputs.first, '7200004444');
      await tapText(tester, 'تحقق');
      await tapText(tester, 'إضافة وحدة');
      await _enter(tester, _licenceInputs.last, '7200004445');
      await tapText(tester, 'تحقق');

      await _enter(tester, _price(1), '-5');
      await tapText(tester, 'متابعة');
      expect(find.text('السعر لا يكون سالبًا: وحدة 2'), findsOneWidget);
      expect(find.text('نشر حسب الاختيار'), findsNothing);

      await _enter(tester, _price(1), 'abc');
      await tester.pump();
      expect(find.text('أدخل السعر بالأرقام: وحدة 2'), findsOneWidget, reason: 'the message follows the field');

      await _enter(tester, _price(0), '١٬٢٠٠٬٠٠٠');
      await _enter(tester, _price(1), '980000');
      await tapText(tester, 'متابعة');
      await tapText(tester, 'نشر حسب الاختيار');
      await tester.pump(const Duration(milliseconds: 400));
      final p = store.myListings().firstWhere((l) => l.kind == 'project');
      expect(p.units!.map((u) => u.price), <double>[1200000, 980000]);
    });
  });

  group('F07 — saving keeps the project hidden', () {
    testWidgets('«حفظ المشروع» and «حفظ الوحدة» on a hidden project leave it hidden', (tester) async {
      await freshDevice();
      signUp('f07.project', role: 'developer');
      _project('pf-9', active: false);
      await pumpApp(tester, '/account/listings/pf-9');
      await tapText(tester, 'تعديل');
      await _enter(tester, _price(0), '910000');
      await tapText(tester, 'حفظ الوحدة');
      expect(_units('pf-9').single.price, 910000);
      expect(store.listingById('pf-9')!.active, isFalse);

      await _enter(tester, _inField('العنوان'), 'مشروع موقوف معدّل');
      await tapText(tester, 'حفظ المشروع');
      expect(store.listingById('pf-9')!.title, 'مشروع موقوف معدّل');
      expect(store.listingById('pf-9')!.active, isFalse, reason: 'showing it again is its own action');
    });
  });
}
