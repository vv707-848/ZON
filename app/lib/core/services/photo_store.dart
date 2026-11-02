// Durable copies of the photos picked from the gallery (QA F05).
//
// The gallery hands back files in temporary folders (Android: the app's
// cache; iOS: tmp) that the system may clear at any time — image_picker's
// own documentation asks the app to move a picked photo somewhere permanent.
// So:
//
// * picking copies each photo at once into the app's own support folder,
//   among the drafts: `photos/drafts/<name>`;
// * saving a listing, a project (its cover and its units) or a stock item
//   copies the photos it keeps into `photos/saved/<name>` — the draft file
//   stays until the sweep, so a form that is still open keeps showing it;
// * the data keeps a short reference `zon-photo:saved/<name>`, resolved
//   against the folder at every launch (on iOS the absolute path of the
//   app's container changes when the app is updated — Apple TN2406 — so an
//   absolute path is never stored);
// * at launch, a photo file that no saved value refers to any more and
//   that is older than a day is removed (drafts of forms that were left,
//   photos removed from a listing); nothing that is referenced is deleted;
// * photos saved by version 1.2.0 and earlier as absolute (temporary) paths
//   are adopted — copied into `saved` — while their file still exists; when
//   it does not, the app shows «الصورة غير متاحة», never another photo.
//
// Uploading the photos to the server (so another device sees them) waits
// for the backend; the reference stays the app's key to the photo until then.

import 'dart:io';
import 'dart:math';

import 'package:path_provider/path_provider.dart';

/// The app's photo folder (see the file comment).
class PhotoStore {
  PhotoStore._();

  /// Prefix of the references saved in the data.
  static const String scheme = 'zon-photo:';
  static const String _drafts = 'drafts';
  static const String _saved = 'saved';

  static Directory? _root;
  static final Random _rnd = Random();

  /// The photos folder (null until [init] succeeded).
  static Directory? get root => _root;

  /// Prepares `<app support folder>/photos`. [base] replaces the support
  /// folder (tests). When the folder cannot be prepared, picking a photo
  /// reports an error instead of keeping a temporary path.
  static Future<void> init([Directory? base]) async {
    try {
      final dir = base ?? await getApplicationSupportDirectory();
      final r = Directory('${dir.path}/photos');
      Directory('${r.path}/$_drafts').createSync(recursive: true);
      Directory('${r.path}/$_saved').createSync(recursive: true);
      _root = r;
    } catch (_) {
      _root = null;
    }
  }

  /// A sample photo bundled with the app (`/listings/…`, `assets/…`).
  static bool isBundled(String s) => s.startsWith('/listings/') || s.startsWith('assets/');

  /// A reference made by this store.
  static bool isRef(String s) => s.startsWith(scheme);

  /// The file behind [ref] (null when it is not a reference or the folder
  /// is not ready).
  static File? fileOf(String ref) {
    final r = _root;
    if (r == null || !isRef(ref)) return null;
    final rel = ref.substring(scheme.length);
    if (rel.contains('..')) return null;
    return File('${r.path}/$rel');
  }

  static String _newName(String source) {
    final slash = source.lastIndexOf('/');
    final dot = source.lastIndexOf('.');
    var ext = dot > slash ? source.substring(dot + 1).toLowerCase() : 'jpg';
    if (!RegExp(r'^[a-z0-9]{1,5}$').hasMatch(ext)) ext = 'jpg';
    return '${DateTime.now().microsecondsSinceEpoch}-${_rnd.nextInt(1 << 30).toRadixString(36)}.$ext';
  }

  /// Keeps a copy of the picked file [path] among the drafts and returns
  /// its reference. Bundled photos and references are returned as they are;
  /// null when the copy failed.
  static Future<String?> keepDraft(String path) async {
    if (isBundled(path) || isRef(path)) return path;
    final r = _root;
    if (r == null) return null;
    try {
      final name = _newName(path);
      final copy = await File(path).copy('${r.path}/$_drafts/$name');
      copy.setLastModifiedSync(DateTime.now());
      return '$scheme$_drafts/$name';
    } catch (_) {
      return null;
    }
  }

  /// The reference to save for [ref]: a draft is copied into the saved
  /// photos; anything else is returned as it is.
  static String commit(String ref) {
    final r = _root;
    const draft = '$scheme$_drafts/';
    if (r == null || !ref.startsWith(draft)) return ref;
    final name = ref.substring(draft.length);
    final saved = File('${r.path}/$_saved/$name');
    try {
      if (!saved.existsSync()) {
        File('${r.path}/$_drafts/$name').copySync(saved.path);
        saved.setLastModifiedSync(DateTime.now());
      }
      return '$scheme$_saved/$name';
    } catch (_) {
      return ref; // the draft is gone: the app shows «الصورة غير متاحة»
    }
  }

  /// A photo saved by an older version as an absolute path: copied into the
  /// saved photos while its file still exists, otherwise left as it is.
  static String adopt(String path) {
    final r = _root;
    if (r == null || isBundled(path) || isRef(path) || !path.startsWith('/')) return path;
    try {
      final f = File(path);
      if (!f.existsSync()) return path;
      final name = _newName(path);
      final copy = f.copySync('${r.path}/$_saved/$name');
      copy.setLastModifiedSync(DateTime.now());
      return '$scheme$_saved/$name';
    } catch (_) {
      return path;
    }
  }

  /// Removes the photo files that [stored] (every value the app saved) does
  /// not mention and that are older than [minAge]. Returns how many.
  static int sweep(String stored, {Duration minAge = const Duration(days: 1)}) {
    final r = _root;
    if (r == null) return 0;
    final now = DateTime.now();
    var removed = 0;
    for (final sub in const <String>[_drafts, _saved]) {
      final dir = Directory('${r.path}/$sub');
      if (!dir.existsSync()) continue;
      for (final e in dir.listSync()) {
        if (e is! File) continue;
        if (stored.contains('$sub/${e.uri.pathSegments.last}')) continue;
        try {
          if (now.difference(e.lastModifiedSync()) < minAge) continue;
          e.deleteSync();
          removed++;
        } catch (_) {
          // in use or already gone: kept for the next launch
        }
      }
    }
    return removed;
  }
}
