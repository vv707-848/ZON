// L01 — an advertising licence per unit in ready projects; hidden fields
// and no hidden check for off-plan projects; no inherited project number.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/project_editor.dart';
import 'package:zon/features/add/widgets/add_kit.dart';
import 'package:zon/features/details/project_screen.dart';
import 'package:zon/features/details/widgets/license_panel.dart';

import 'support/test_env.dart';

Finder _inField(String label) => find.descendant(
  of: find.ancestor(of: find.text(label), matching: find.byType(ProjField)).first,
  matching: find.byType(EditableText),
);

Finder get _licenceInputs => find.descendant(of: find.byType(UnitLicenceField), matching: find.byType(EditableText));

Future<void> _enter(WidgetTester tester, Finder f, String text) async {
  await tester.ensureVisible(f);
  await tester.enterText(f, text);
  await tester.pump();
}

Listing _myProject() => store.myListings().firstWhere((l) => l.kind == 'project');

/// Shown licence values (QR panels) on the current page.
List<String> _shownLicences(WidgetTester tester) =>
    tester.widgetList<LicensePanel>(find.byType(LicensePanel)).map((p) => p.license ?? '').where((s) => s.isNotEmpty).toList();

Future<void> _fillProject(WidgetTester tester, {required String title}) async {
  await _enter(tester, _inField('العنوان'), title);
  await _enter(tester, find.ancestor(of: find.text('قريب من الخدمات والمدارس والمسجد…'), matching: find.byType(TextField)), 'قريب من الخدمات');
}

void main() {
  testWidgets('ready project with two units: two independent numbers, saved and shown per unit', (tester) async {
    await freshDevice();
    signUp('l01.dev', role: 'developer');
    await pumpApp(tester, '/add?type=project');
    await _fillProject(tester, title: 'مشروع الوحدتين');
    await tapText(tester, 'إضافة وحدة');
    expect(find.byType(UnitLicenceField), findsNWidgets(2), reason: 'one licence field under each unit');

    await _enter(tester, _licenceInputs.at(0), '7200001111');
    await _enter(tester, _licenceInputs.at(1), '٧٢٠٠٠٠٢٢٢٢'); // Arabic digits
    // only the first verified → publishing is refused and names the second
    await tapText(tester, 'تحقق', index: 0);
    await tapText(tester, 'متابعة');
    expect(find.textContaining('أكمل رقم الترخيص الإعلاني وتحقق منه: وحدة 2'), findsOneWidget);

    await tapText(tester, 'تحقق');
    expect(find.textContaining('أكمل رقم الترخيص الإعلاني وتحقق منه'), findsNothing, reason: 'the message follows the fix');
    await tapText(tester, 'متابعة');
    await tapText(tester, 'نشر حسب الاختيار');
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.byType(ProjectScreen), findsOneWidget);

    final p = _myProject();
    expect(p.license, isEmpty, reason: 'no project-wide number');
    expect(p.units!.map((u) => u.license), <String>['7200001111', '7200002222']);
    expect(p.units!.every((u) => u.licenseVerified == true), isTrue);

    // each unit page shows its own number (panel value and readable text)
    for (final u in p.units!) {
      await pumpApp(tester, '/projects/${p.id}/units/${u.id}');
      expect(_shownLicences(tester), <String>[u.license!]);
      expect(find.text('رقم الترخيص الإعلاني: ${u.license}'), findsOneWidget);
    }

    // reopening the editor restores both numbers
    await pumpApp(tester, '/account/listings/${p.id}');
    expect(find.byType(ProjectEditor), findsOneWidget);
    expect(find.text('الترخيص 7200001111'), findsOneWidget);
    expect(find.text('الترخيص 7200002222'), findsOneWidget);
  });

  testWidgets('changing one unit’s number does not touch the other (unit editor)', (tester) async {
    await freshDevice();
    signUp('l01.edit', role: 'developer');
    store.saveListing(
      Listing.fromJson(<String, Object?>{
        'id': 'pr-1',
        'kind': 'project',
        'title': 'مشروع للتعديل',
        'city': 'الرياض',
        'neighborhood': 'الملقا',
        'propertyType': 'apartment',
        'source': 'developer',
        'delivery': 'ready',
        'description': 'وصف',
        'advertiser': 'مطور',
        'advertiserRole': 'developer',
        'license': '',
        'units': <Object>[
          <String, Object?>{
            'id': 'u-a',
            'name': 'A1',
            'price': 900000,
            'image': '/listings/apt-1.jpg',
            'description': '',
            'license': '7200000001',
            'licenseVerified': true,
          },
          <String, Object?>{
            'id': 'u-b',
            'name': 'B1',
            'price': 950000,
            'image': '/listings/apt-1.jpg',
            'description': '',
            'license': '7200000002',
            'licenseVerified': true,
          },
        ],
      }),
    );
    await pumpApp(tester, '/account/listings/pr-1');
    await tapText(tester, 'تعديل', index: 0);
    await _enter(tester, _licenceInputs.first, '7299999999');
    expect(find.text('تحقق'), findsOneWidget, reason: 'a changed number needs a new check');
    await tapText(tester, 'تحقق');
    await tapText(tester, 'حفظ الوحدة');
    final units = store.listingById('pr-1')!.units!;
    expect(units.firstWhere((u) => u.id == 'u-a').license, '7299999999');
    expect(units.firstWhere((u) => u.id == 'u-b').license, '7200000002');
  });

  testWidgets('off-plan: fields hidden, no hidden check, nothing inherited — and back to ready keeps the draft', (tester) async {
    await freshDevice();
    signUp('l01.offplan', role: 'developer');
    await pumpApp(tester, '/add?type=project');
    await _fillProject(tester, title: 'مشروع على الخارطة');
    await _enter(tester, _licenceInputs.first, '7200003333');
    // switch to off-plan
    await tapText(tester, 'جاهز');
    await tapText(tester, 'على الخارطة', last: true);
    expect(find.byType(UnitLicenceField), findsNothing);
    // back to ready: the typed number is still in the draft (not lost, not copied)
    await tapText(tester, 'على الخارطة');
    await tapText(tester, 'جاهز', last: true);
    expect(find.byType(UnitLicenceField), findsOneWidget);
    expect(find.text('7200003333'), findsOneWidget);
    // off-plan again and publish without any number
    await tapText(tester, 'جاهز');
    await tapText(tester, 'على الخارطة', last: true);
    await tapText(tester, 'متابعة');
    expect(find.byType(FormError), findsNothing, reason: 'no hidden licence check');
    await tapText(tester, 'نشر حسب الاختيار');
    await tester.pump(const Duration(milliseconds: 400));
    final p = _myProject();
    expect(p.delivery, 'offplan');
    expect(p.units!.single.license, isNull, reason: 'the draft number is not published with an off-plan project');
    await pumpApp(tester, '/projects/${p.id}/units/${p.units!.single.id}');
    expect(_shownLicences(tester), isEmpty);
    expect(find.textContaining('رقم الترخيص الإعلاني:'), findsNothing);
  });

  testWidgets('older data: a project-level number is not shown on units nor copied to them', (tester) async {
    await freshDevice();
    // sample project p1 is ready, with a project number and units without their own
    final p1 = store.listingById('p1')!;
    expect(p1.license, isNotEmpty);
    await pumpApp(tester, '/projects/p1/units/${p1.units!.first.id}');
    expect(_shownLicences(tester), isEmpty);

    signUp('l01.legacy', role: 'developer');
    store.saveListing(
      Listing.fromJson(<String, Object?>{
        'id': 'old-1',
        'kind': 'project',
        'title': 'مشروع قديم',
        'city': 'الرياض',
        'neighborhood': 'الملقا',
        'propertyType': 'apartment',
        'source': 'developer',
        'delivery': 'ready',
        'description': 'وصف',
        'advertiser': 'مطور',
        'advertiserRole': 'developer',
        'license': '7211111111',
        'units': <Object>[
          <String, Object?>{'id': 'o-a', 'name': 'A', 'price': 1, 'image': '', 'description': ''},
        ],
      }),
    );
    await pumpApp(tester, '/account/listings/old-1');
    expect(find.textContaining('لا يُنقل للوحدات تلقائيًا'), findsOneWidget);
    await tapText(tester, 'حفظ المشروع');
    expect(find.textContaining('أكمل رقم الترخيص الإعلاني'), findsOneWidget);
    expect(store.listingById('old-1')!.units!.single.license, isNull, reason: 'never copied from the project');
  });
}
