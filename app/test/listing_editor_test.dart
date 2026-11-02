// E01 — editing a listing's photos and details; EDIT-02 negative price;
// ACCESS-02 guarded editor route.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/services/photo_picker.dart';
import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/listing_editor.dart';
import 'package:zon/features/add/widgets/add_kit.dart';
import 'package:zon/features/auth/login_screen.dart';
import 'package:zon/features/details/listing_screen.dart';

import 'support/test_env.dart';

/// What the fake gallery returns next.
PhotoPickResult _next = const PhotoPickResult(<String>[]);

Finder _photoX(int i) => find.bySemanticsLabel('حذف الصورة ${i + 1}');
Finder _photoStar(int i) => find.bySemanticsLabel('اجعل الصورة ${i + 1} الغلاف');

Future<void> _tap(WidgetTester tester, Finder f) async {
  await tester.ensureVisible(f.first);
  await tester.pump();
  await tester.tap(f.first, warnIfMissed: false);
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 250));
}

Finder _numberField(String label) => find.descendant(
  of: find.ancestor(of: find.text(label), matching: find.byType(Column)).first,
  matching: find.byType(EditableText),
);

void main() {
  setUp(() {
    photoPicker = ({bool multiple = true}) async => _next;
  });

  testWidgets('add a photo, delete another, change the cover → saved, shown, reopened the same', (tester) async {
    await freshDevice();
    signUp('e01.photos', role: 'broker');
    publishTestListing('ed-1', images: const <String>['/listings/villa-1.jpg', '/listings/villa-2.jpg']);
    final semantics = tester.ensureSemantics();
    await pumpApp(tester, '/account/listings/ed-1');
    expect(find.byType(ListingEditor), findsOneWidget);
    expect(find.byType(PhotoThumb), findsNWidgets(2));

    _next = const PhotoPickResult(<String>['/listings/apt-1.jpg']);
    await _tap(tester, find.byKey(const ValueKey<String>('photo-add')));
    expect(find.byType(PhotoThumb), findsNWidgets(3), reason: 'live preview of the added photo');
    await _tap(tester, _photoX(0)); // delete villa-1 (the cover)
    expect(find.byType(PhotoThumb), findsNWidgets(2));
    await _tap(tester, _photoStar(1)); // apt-1 becomes the cover
    await tapText(tester, 'حفظ وتحديث العرض');
    await tester.pump(const Duration(milliseconds: 300));

    final saved = store.listingById('ed-1')!;
    expect(saved.images, <String>['/listings/apt-1.jpg', '/listings/villa-2.jpg']);
    expect(saved.license, '7200000001', reason: 'the licence is kept');
    expect(saved.propertyType, 'villa', reason: 'the type is kept');

    await pumpApp(tester, '/listings/ed-1');
    expect(find.byType(ListingScreen), findsOneWidget);
    await pumpApp(tester, '/account/listings/ed-1');
    final thumbs = tester.widgetList<PhotoThumb>(find.byType(PhotoThumb)).map((t) => t.src).toList();
    expect(thumbs, <String>['/listings/apt-1.jpg', '/listings/villa-2.jpg']);
    semantics.dispose();
  });

  testWidgets('cancel or back without saving keeps the original photos', (tester) async {
    await freshDevice();
    signUp('e01.cancel', role: 'broker');
    publishTestListing('ed-2', images: const <String>['/listings/villa-1.jpg', '/listings/villa-2.jpg']);
    final semantics = tester.ensureSemantics();
    await pumpApp(tester, '/account/listings/ed-2');
    await _tap(tester, _photoX(1));
    await tapText(tester, 'إلغاء');
    expect(find.text('تجاهل التعديلات؟'), findsOneWidget);
    await tapText(tester, 'تجاهل');
    expect(store.listingById('ed-2')!.images, <String>['/listings/villa-1.jpg', '/listings/villa-2.jpg']);
    semantics.dispose();
  });

  testWidgets('a price-only edit keeps the photos; a negative price is refused with a message', (tester) async {
    await freshDevice();
    signUp('e01.price', role: 'broker');
    publishTestListing('ed-3', images: const <String>['/listings/villa-1.jpg']);
    await pumpApp(tester, '/account/listings/ed-3');
    final price = _numberField('السعر');
    await tester.ensureVisible(price.first);
    await tester.enterText(price.first, '-100');
    await tester.pump();
    await tapText(tester, 'حفظ وتحديث العرض');
    expect(find.text('السعر لا يكون سالبًا'), findsOneWidget);
    expect(store.listingById('ed-3')!.price, 2500000, reason: 'not saved');

    await tester.enterText(price.first, '٢٧٠٠٠٠٠');
    await tester.pump();
    await tapText(tester, 'حفظ وتحديث العرض');
    await tester.pump(const Duration(milliseconds: 300));
    final l = store.listingById('ed-3')!;
    expect(l.price, 2700000);
    expect(l.images, <String>['/listings/villa-1.jpg']);
  });

  testWidgets('the last photo cannot be deleted; permission refused / closed picker / missing file', (tester) async {
    await freshDevice();
    signUp('e01.edge', role: 'broker');
    publishTestListing('ed-4', images: const <String>['/tmp/zon-missing-photo.jpg']);
    final semantics = tester.ensureSemantics();
    await pumpApp(tester, '/account/listings/ed-4');
    // let the real file read fail (outside the test's fake clock)
    for (var i = 0; i < 5; i++) {
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
      await tester.pump();
    }
    expect(find.text('غير متاحة'), findsWidgets, reason: 'a missing file is shown, not a crash');

    await _tap(tester, _photoX(0));
    expect(find.textContaining('يبقى للعرض صورة واحدة على الأقل'), findsOneWidget);
    expect(find.byType(PhotoThumb), findsOneWidget);

    _next = const PhotoPickResult(<String>[], error: 'اسمح لزون بالوصول إلى الصور من إعدادات الهاتف ثم حاول مرة أخرى.');
    await _tap(tester, find.byKey(const ValueKey<String>('photo-add')));
    expect(find.textContaining('اسمح لزون بالوصول إلى الصور'), findsOneWidget);
    expect(find.byType(PhotoThumb), findsOneWidget);

    _next = const PhotoPickResult(<String>[]); // closed without choosing
    await _tap(tester, find.byKey(const ValueKey<String>('photo-add')));
    expect(find.byType(PhotoThumb), findsOneWidget);
    expect(store.listingById('ed-4')!.images, <String>['/tmp/zon-missing-photo.jpg']);
    semantics.dispose();
  });

  testWidgets('ACCESS-02: a guest and another account cannot open the editor by its address', (tester) async {
    await freshDevice();
    signUp('e01.owner', role: 'broker');
    publishTestListing('ed-5');
    LocalAuth.signOut();
    await pumpApp(tester, '/account/listings/ed-5');
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.byType(ListingEditor), findsNothing);
    expect(find.byType(LoginScreen), findsOneWidget);

    signUp('e01.other', role: 'broker');
    await pumpApp(tester, '/account/listings/ed-5');
    expect(find.byType(ListingEditor), findsNothing);
    expect(find.text('لا يمكنك تعديل هذا العرض'), findsOneWidget);
    // a sample listing belongs to nobody either
    await pumpApp(tester, '/account/listings/l1');
    expect(find.byType(ListingEditor), findsNothing);
  });

  test('the add form keeps the shared picker working for projects and units', () {
    // PhotoDrop keeps its API (onFiles) and now also shows the current photos.
    const drop = PhotoDrop(onFiles: _noop, current: <String>['/listings/apt-1.jpg']);
    expect(drop.current, hasLength(1));
  });
}

void _noop(List<String> _) {}
