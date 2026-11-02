// Picking photos from the phone's gallery (image_picker), with the cases
// the forms must handle: permission refused, picker closed, picker missing.
// Each photo is copied at once into the app's own folder (QA F05 — see
// photo_store.dart): the gallery's file is temporary.

import 'package:flutter/services.dart';

import 'package:image_picker/image_picker.dart';

import 'package:zon/core/services/photo_store.dart';

/// What the gallery returned.
class PhotoPickResult {
  const PhotoPickResult(this.paths, {this.error});

  /// The chosen photos (empty when the user closed the picker): references
  /// of the app's copies once [pickPhotos] kept them.
  final List<String> paths;

  /// A short Arabic message when the gallery could not be used.
  final String? error;
}

/// Opens the gallery. Tests replace it with a fake.
Future<PhotoPickResult> Function({bool multiple}) photoPicker = _galleryPick;

/// Message when a photo could not be copied into the app's folder.
const String kPhotoNotKept = 'تعذّر حفظ الصورة على الجهاز — حاول مرة أخرى.';

/// Opens the gallery ([photoPicker]) and keeps a durable copy of every
/// photo chosen (QA F05). Used by the forms.
Future<PhotoPickResult> pickPhotos({bool multiple = true}) async {
  final r = await photoPicker(multiple: multiple);
  return r.paths.isEmpty ? r : _keep(r.paths);
}

Future<PhotoPickResult> _keep(List<String> paths) async {
  final kept = <String>[];
  for (final p in paths) {
    final ref = await PhotoStore.keepDraft(p);
    if (ref == null) return const PhotoPickResult(<String>[], error: kPhotoNotKept);
    kept.add(ref);
  }
  return PhotoPickResult(kept);
}

Future<PhotoPickResult> _galleryPick({bool multiple = true}) async {
  try {
    final picker = ImagePicker();
    final files = multiple
        ? await picker.pickMultiImage()
        : <XFile?>[await picker.pickImage(source: ImageSource.gallery)].whereType<XFile>().toList();
    // copied at once: the picker's file is temporary
    return await _keep(files.map((f) => f.path).toList());
  } on PlatformException catch (e) {
    final code = e.code.toLowerCase();
    if (code.contains('denied') || code.contains('access') || code.contains('permission')) {
      return const PhotoPickResult(<String>[], error: 'اسمح لزون بالوصول إلى الصور من إعدادات الهاتف ثم حاول مرة أخرى.');
    }
    return const PhotoPickResult(<String>[], error: 'تعذّر فتح الاستوديو — حاول مرة أخرى.');
  } catch (_) {
    return const PhotoPickResult(<String>[], error: 'تعذّر فتح الاستوديو — حاول مرة أخرى.');
  }
}
