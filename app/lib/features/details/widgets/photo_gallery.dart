// Full-width photo gallery at the top of the listing / project / unit pages.
//
// Layout (same idea as the big Saudi property apps):
//   ┌──────────────────────────────────────┐
//   │ [badge]                              │  photo, edge to edge, 3:2
//   │                                      │  swipe to change photo
//   │                          [كل الصور]  │  white pill, opens the viewer
//   └──────────────────────────────────────┘
//                  ● ○ ○ ○                     page dots on white, active = green

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// Swipeable, edge-to-edge photo gallery with an "all photos" pill and dots.
class PhotoGallery extends StatefulWidget {
  const PhotoGallery({super.key, required this.photos, this.index = 0, this.onIndex, this.badge});

  /// Image references (see `imageFor`). Empty → the fallback photo.
  final List<String> photos;

  /// Photo shown first.
  final int index;

  /// Called when the visible photo changes.
  final ValueChanged<int>? onIndex;

  /// Optional label pinned to the top-right corner (e.g. "للبيع").
  final Widget? badge;

  /// Width / height of the photo area.
  static const double aspectRatio = 3 / 2;

  @override
  State<PhotoGallery> createState() => _PhotoGalleryState();
}

class _PhotoGalleryState extends State<PhotoGallery> {
  late int _index = widget.index;
  late final PageController _pages = PageController(initialPage: widget.index);

  List<String> get _photos {
    final list = widget.photos.where((s) => s.isNotEmpty).toList();
    return list.isEmpty ? const <String>[kPhotoFallback] : list;
  }

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  void _show(int n) {
    if (!mounted) return;
    setState(() => _index = n);
    widget.onIndex?.call(n);
    if (_pages.hasClients && _pages.page?.round() != n) _pages.jumpToPage(n);
  }

  void _openViewer() {
    showGeneralDialog<void>(
      context: context,
      barrierDismissible: false,
      barrierColor: const Color(0xFF000000),
      transitionDuration: const Duration(milliseconds: 180),
      transitionBuilder: (ctx, anim, _, child) => FadeTransition(opacity: anim, child: child),
      pageBuilder: (ctx, a, b) => PhotoViewer(photos: _photos, index: _index, onIndex: _show),
    );
  }

  @override
  Widget build(BuildContext context) {
    final photos = _photos;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        AspectRatio(
          aspectRatio: PhotoGallery.aspectRatio,
          child: Stack(
            fit: StackFit.expand,
            children: <Widget>[
              ColoredBox(
                color: AppColors.mist,
                child: PageView.builder(
                  controller: _pages,
                  itemCount: photos.length,
                  onPageChanged: (n) {
                    setState(() => _index = n);
                    widget.onIndex?.call(n);
                  },
                  itemBuilder: (context, n) => GestureDetector(onTap: _openViewer, child: AppImage(photos[n])),
                ),
              ),
              if (widget.badge != null) PositionedDirectional(top: 12, start: 12, child: widget.badge!),
              PositionedDirectional(
                start: 12,
                bottom: 12,
                child: Tap(
                  onTap: _openViewer,
                  child: Container(
                    height: 34,
                    padding: const EdgeInsets.symmetric(horizontal: 14),
                    decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(10), boxShadow: AppShadows.raised),
                    alignment: Alignment.center,
                    child: Tx('كل الصور', style: ts(14, w: 600, lh: 20, c: AppColors.ink)),
                  ),
                ),
              ),
            ],
          ),
        ),
        if (photos.length > 1)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: PageDots(count: photos.length, index: _index),
          ),
      ],
    );
  }
}

/// Row of page dots: the active one is green, the others light grey.
class PageDots extends StatelessWidget {
  const PageDots({super.key, required this.count, required this.index});

  final int count;
  final int index;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: <Widget>[
        for (var n = 0; n < count; n++)
          AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            margin: const EdgeInsets.symmetric(horizontal: 4),
            width: 7,
            height: 7,
            decoration: BoxDecoration(color: n == index ? AppColors.success : const Color(0xFFD3DAD6), shape: BoxShape.circle),
          ),
      ],
    );
  }
}

/// Full-screen black viewer: swipe between photos, close with "إغلاق".
class PhotoViewer extends StatefulWidget {
  const PhotoViewer({super.key, required this.photos, required this.index, required this.onIndex});

  final List<String> photos;
  final int index;
  final ValueChanged<int> onIndex;

  @override
  State<PhotoViewer> createState() => _PhotoViewerState();
}

class _PhotoViewerState extends State<PhotoViewer> {
  late int _index = widget.index;
  late final PageController _pages = PageController(initialPage: widget.index);

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  void _go(int n) {
    final len = widget.photos.length;
    final next = ((n % len) + len) % len;
    _pages.animateToPage(next, duration: const Duration(milliseconds: 220), curve: Curves.easeOut);
  }

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.paddingOf(context);
    Widget arrow(String icon, VoidCallback onTap) => Tap(
      onTap: onTap,
      child: Container(
        width: 44,
        height: 44,
        decoration: const BoxDecoration(color: Color(0x29FFFFFF), shape: BoxShape.circle),
        alignment: Alignment.center,
        child: LucideIcon(icon, size: 20, strokeWidth: 2.2, color: AppColors.white),
      ),
    );
    return ColoredBox(
      color: const Color(0xFF000000),
      child: Stack(
        children: <Widget>[
          Positioned.fill(
            child: PageView.builder(
              controller: _pages,
              itemCount: widget.photos.length,
              onPageChanged: (n) {
                setState(() => _index = n);
                widget.onIndex(n);
              },
              itemBuilder: (context, n) => InteractiveViewer(
                maxScale: 4,
                child: Center(
                  child: AppImage(widget.photos[n], fit: BoxFit.contain, width: double.infinity),
                ),
              ),
            ),
          ),
          PositionedDirectional(
            top: 12 + pad.top,
            start: 16,
            child: Tap(
              onTap: () => Navigator.of(context).pop(),
              child: Container(
                height: 36,
                padding: const EdgeInsets.symmetric(horizontal: 14),
                decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(999)),
                alignment: Alignment.center,
                child: Tx('إغلاق', style: ts(15, w: 600, lh: 22, c: AppColors.ink)),
              ),
            ),
          ),
          if (widget.photos.length > 1) ...<Widget>[
            PositionedDirectional(start: 10, top: 0, bottom: 0, child: Center(child: arrow(Lu.chevronRight, () => _go(_index - 1)))),
            PositionedDirectional(end: 10, top: 0, bottom: 0, child: Center(child: arrow(Lu.chevronLeft, () => _go(_index + 1)))),
          ],
          Positioned(
            left: 0,
            right: 0,
            bottom: 24 + pad.bottom,
            child: Center(
              child: Tx(
                '${_index + 1} / ${widget.photos.length}',
                style: ts(15, w: 500, lh: 22, c: AppColors.white),
                dir: TextDirection.ltr,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
