// QA F05 — a photo picked from the gallery is kept by the app: it survives
// the cleaning of the picker's temporary file and a restart, the cover order
// is kept, leaving an edit changes nothing, photos saved by version 1.2.0 as
// temporary paths are adopted while their file exists, a missing photo is a
// neutral «الصورة غير متاحة» box (never another photo), and unused copies
// are removed only when nothing refers to them and they are a day old.

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/services/photo_picker.dart';
import 'package:zon/core/services/photo_store.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/widgets/add_kit.dart';

import 'support/test_env.dart';

/// A small real PNG (8×6).
final List<int> _png = base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAGCAIAAABxZ0isAAAAFElEQVR4nGPUy/ZnwAaYsIrSSQIAihkA9KIuRVoAAAAASUVORK5CYII=');

/// The picker's temporary folder (what image_picker hands back).
Directory _pickerTmp() => Directory.systemTemp.createTempSync('image_picker_tmp-');

File _photo(Directory dir, String name) => File('${dir.path}/$name')..writeAsBytesSync(_png);

List<File> _files(String sub) {
  final d = Directory('${PhotoStore.root!.path}/$sub');
  return d.existsSync() ? d.listSync().whereType<File>().toList() : <File>[];
}

/// Makes every photo file of the app [days] days old.
void _age(int days) {
  final t = DateTime.now().subtract(Duration(days: days));
  for (final f in <File>[..._files('drafts'), ..._files('saved')]) {
    f.setLastModifiedSync(t);
  }
}

/// Lets real file work (copies, image reads) finish.
Future<void> _settleIo(WidgetTester tester) async {
  for (var i = 0; i < 6; i++) {
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 60)));
    await tester.pump();
  }
}

Future<void> _tap(WidgetTester tester, Finder f) async {
  await tester.ensureVisible(f.first);
  await tester.pump();
  await tester.tap(f.first, warnIfMissed: false);
  await tester.pump();
}

Finder _photoStar(int i) => find.bySemanticsLabel('اجعل الصورة ${i + 1} الغلاف');

void main() {
  testWidgets('kept after the picker’s file is cleaned and the app restarts; the cover order is kept', (tester) async {
    await freshDevice();
    signUp('f05.keep', role: 'broker');
    publishTestListing('ph-1', images: const <String>['/listings/villa-1.jpg']);
    final tmp = _pickerTmp();
    final a = _photo(tmp, 'image_picker_A.png');
    final b = _photo(tmp, 'image_picker_B.jpg');
    photoPicker = ({bool multiple = true}) async => PhotoPickResult(<String>[a.path, b.path]);
    final semantics = tester.ensureSemantics();

    await pumpApp(tester, '/account/listings/ph-1');
    await _tap(tester, find.byKey(const ValueKey<String>('photo-add')));
    await _settleIo(tester);
    expect(find.byType(PhotoThumb), findsNWidgets(3));
    final shown = tester.widgetList<PhotoThumb>(find.byType(PhotoThumb)).map((t) => t.src).toList();
    expect(shown.skip(1), everyElement(startsWith('zon-photo:drafts/')), reason: 'copied at once, not the temporary path');
    await _tap(tester, _photoStar(2)); // B becomes the cover
    await tester.pump(const Duration(milliseconds: 300));
    await tapText(tester, 'حفظ وتحديث العرض');
    await tester.pump(const Duration(milliseconds: 300));

    final saved = store.listingById('ph-1')!.images;
    expect(saved, hasLength(3));
    expect(saved[0], allOf(startsWith('zon-photo:saved/'), endsWith('.jpg')), reason: 'B, the cover');
    expect(saved[1], '/listings/villa-1.jpg');
    expect(saved[2], allOf(startsWith('zon-photo:saved/'), endsWith('.png')));
    expect(saved.every((p) => !p.contains(tmp.path)), isTrue);

    // the system clears the picker's temporary files; days later the app restarts
    tmp.deleteSync(recursive: true);
    _age(3);
    await restartApp();
    expect(store.listingById('ph-1')!.images, saved, reason: 'same photos, same order');
    for (final p in saved.where(PhotoStore.isRef)) {
      expect(PhotoStore.fileOf(p)!.readAsBytesSync(), _png);
    }
    expect(_files('drafts'), isEmpty, reason: 'the old draft copies were removed');
    expect(_files('saved'), hasLength(2));

    await pumpApp(tester, '/listings/ph-1');
    await _settleIo(tester);
    expect(find.text('الصورة غير متاحة'), findsNothing);
    semantics.dispose();
  });

  testWidgets('leaving the edit keeps the original photos; the unused copy goes after a day', (tester) async {
    await freshDevice();
    signUp('f05.leave', role: 'broker');
    publishTestListing('ph-2', images: const <String>['/listings/villa-1.jpg']);
    final tmp = _pickerTmp();
    photoPicker = ({bool multiple = true}) async => PhotoPickResult(<String>[_photo(tmp, 'image_picker_C.png').path]);
    final semantics = tester.ensureSemantics();
    await pumpApp(tester, '/account/listings/ph-2');
    await _tap(tester, find.byKey(const ValueKey<String>('photo-add')));
    await _settleIo(tester);
    expect(find.byType(PhotoThumb), findsNWidgets(2));
    await tapText(tester, 'إلغاء');
    await tapText(tester, 'تجاهل');
    expect(store.listingById('ph-2')!.images, <String>['/listings/villa-1.jpg']);
    expect(_files('saved'), isEmpty, reason: 'nothing was saved');
    expect(_files('drafts'), hasLength(1));

    await restartApp();
    expect(_files('drafts'), hasLength(1), reason: 'not a day old yet: kept');
    _age(2);
    await restartApp();
    expect(_files('drafts'), isEmpty);
    semantics.dispose();
  });

  testWidgets('a photo that cannot be copied is not added (no temporary path is kept)', (tester) async {
    await freshDevice();
    signUp('f05.fail', role: 'broker');
    publishTestListing('ph-3', images: const <String>['/listings/villa-1.jpg']);
    photoPicker = ({bool multiple = true}) async => const PhotoPickResult(<String>['/tmp/zon-picker-gone/image_picker_X.jpg']);
    final semantics = tester.ensureSemantics();
    await pumpApp(tester, '/account/listings/ph-3');
    await _tap(tester, find.byKey(const ValueKey<String>('photo-add')));
    await _settleIo(tester);
    expect(find.text(kPhotoNotKept), findsOneWidget);
    expect(find.byType(PhotoThumb), findsOneWidget);
    semantics.dispose();
  });

  group('photos saved by version 1.2.0 (absolute temporary paths)', () {
    Map<String, Object> device(String kept, String gone) => <String, Object>{
      'zon.extra.listings.v1': jsonEncode(<Object?>[
        Listing(
          id: 'v3-1',
          kind: 'unit',
          title: 'عرض من 1.2.0',
          purpose: 'sale',
          city: 'الرياض',
          neighborhood: 'الملقا',
          propertyType: 'villa',
          source: 'broker',
          price: 2500000,
          rooms: 5,
          baths: 4,
          area: 400,
          images: <String>[kept, gone],
          description: 'وصف',
          advertiser: 'وسيط',
          advertiserRole: 'broker',
          license: '7200000001',
          active: true,
        ).toJson(),
      ]),
    };

    testWidgets('adopted while the file exists, once; a missing one shows «الصورة غير متاحة», never another photo', (tester) async {
      final tmp = _pickerTmp();
      final kept = _photo(tmp, 'image_picker_OLD.png');
      final gone = '${tmp.path}/image_picker_GONE.png';
      await freshDevice(device(kept.path, gone));
      final images = store.listingById('v3-1')!.images;
      expect(images[0], startsWith('zon-photo:saved/'));
      expect(PhotoStore.fileOf(images[0])!.readAsBytesSync(), _png);
      expect(images[1], gone, reason: 'nothing to copy: left as it was');

      await restartApp();
      expect(store.listingById('v3-1')!.images, images, reason: 'adopted once');
      expect(_files('saved'), hasLength(1));
      kept.deleteSync();
      await restartApp();
      expect(PhotoStore.fileOf(images[0])!.existsSync(), isTrue, reason: 'the app’s copy does not depend on the old file');

      await tester.pumpWidget(
        Directionality(
          textDirection: TextDirection.rtl,
          child: Center(child: SizedBox(width: 200, height: 150, child: AppImage(gone))),
        ),
      );
      await _settleIo(tester);
      expect(find.text('الصورة غير متاحة'), findsOneWidget);
      expect(find.byWidgetPredicate((w) => w is Image && w.image is AssetImage), findsNothing, reason: 'no sample photo in its place');
    });
  });

  test('a project’s cover and unit photo are kept; an old photo nothing refers to any more is removed', () async {
    await freshDevice();
    signUp('f05.project', role: 'developer');
    final tmp = _pickerTmp();
    final draft = (await PhotoStore.keepDraft(_photo(tmp, 'image_picker_P.png').path))!;
    expect(draft, startsWith('zon-photo:drafts/'));
    store.saveListing(
      Listing.fromJson(<String, Object?>{
        'id': 'pr-ph',
        'kind': 'project',
        'title': 'مشروع الصور',
        'city': 'الرياض',
        'neighborhood': 'الملقا',
        'propertyType': 'apartment',
        'source': 'developer',
        'delivery': 'offplan',
        'description': 'وصف',
        'advertiser': 'مطور',
        'advertiserRole': 'developer',
        'license': '',
        'images': <String>[draft],
        'units': <Object>[
          <String, Object?>{'id': 'u1', 'name': 'A', 'price': 1, 'image': draft, 'description': ''},
        ],
      }),
    );
    final p = store.listingById('pr-ph')!;
    final ref = p.images.single;
    expect(ref, startsWith('zon-photo:saved/'));
    expect(p.units!.single.image, ref);
    tmp.deleteSync(recursive: true);
    _age(3);
    await restartApp();
    expect(PhotoStore.fileOf(ref)!.existsSync(), isTrue, reason: 'referenced: kept');

    // removed from the project and its unit
    store.patchListing('pr-ph', <String, Object?>{
      'images': <String>['/listings/apt-1.jpg'],
      'units': <Object>[
        <String, Object?>{'id': 'u1', 'name': 'A', 'price': 1, 'image': '/listings/apt-1.jpg', 'description': ''},
      ],
    });
    await restartApp();
    expect(PhotoStore.fileOf(ref)!.existsSync(), isFalse, reason: 'nothing refers to it any more');
  });
}
