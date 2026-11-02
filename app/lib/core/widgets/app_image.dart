// Photo widget used for listings, projects, units and chat attachments.

import 'dart:io';

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/services/photo_store.dart';
import 'package:zon/core/theme/theme.dart';

/// Shown when a listing has no photo at all.
const String kPhotoFallback = '/listings/tower-1.jpg';

/// Resolves an image reference stored in the data to an [ImageProvider].
///
/// * `/listings/<file>` — a bundled sample photo in `assets/images/<file>`
///   (the data keeps this short path).
/// * `assets/…` — any other bundled asset.
/// * `zon-photo:…` — a photo kept in the app's folder (PhotoStore, QA F05).
/// * anything else — a file path saved by an older version.
ImageProvider imageFor(String src) {
  if (src.startsWith('/listings/')) return AssetImage('assets/images/${src.substring('/listings/'.length)}');
  if (src.startsWith('assets/')) return AssetImage(src);
  if (PhotoStore.isRef(src)) return FileImage(PhotoStore.fileOf(src) ?? File(src));
  return FileImage(File(src));
}

/// An image that covers its box. A photo of the user that cannot be read
/// (its file is gone) shows the neutral [PhotoUnavailable] box — never
/// another property's photo in its place (QA F05); [fallback] is only used
/// when there is no photo at all.
class AppImage extends StatelessWidget {
  const AppImage(this.src, {super.key, this.fallback = kPhotoFallback, this.fit = BoxFit.cover, this.width, this.height});

  final String src;
  final String fallback;
  final BoxFit fit;
  final double? width;
  final double? height;

  @override
  Widget build(BuildContext context) {
    final s = src.isEmpty ? fallback : src;
    return Image(
      image: imageFor(s),
      fit: fit,
      width: width,
      height: height,
      gaplessPlayback: true,
      errorBuilder: (context, error, stack) {
        if (!PhotoStore.isBundled(s)) return PhotoUnavailable(width: width, height: height);
        if (s == fallback) return const SizedBox.shrink();
        return Image(image: imageFor(fallback), fit: fit, width: width, height: height);
      },
    );
  }
}

/// Neutral box for a photo that cannot be shown: «الصورة غير متاحة».
class PhotoUnavailable extends StatelessWidget {
  const PhotoUnavailable({super.key, this.width, this.height});

  final double? width;
  final double? height;

  @override
  Widget build(BuildContext context) => Container(
    width: width,
    height: height,
    color: const Color(0xFFF1F4F2),
    alignment: Alignment.center,
    padding: const EdgeInsets.all(6),
    child: FittedBox(
      fit: BoxFit.scaleDown,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const LucideIcon(Lu.imageOff, size: 22, color: AppColors.muted),
          const SizedBox(height: 4),
          Tx('الصورة غير متاحة', style: ts(12, w: 600, lh: 18, c: AppColors.muted)),
        ],
      ),
    ),
  );
}
