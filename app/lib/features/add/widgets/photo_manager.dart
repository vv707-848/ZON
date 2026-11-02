// Photos of a listing in the editor (brief E01): the current photos as
// thumbnails, add from the gallery, delete one, choose the cover (the first
// photo is the cover), with a live preview. Nothing is saved here — the
// editor saves the list with «حفظ وتحديث العرض»; leaving without saving keeps
// the original photos.
//
// Handled without a crash: permission refused, picker closed, a photo file
// that is no longer on the phone (shown as "غير متاحة" and can be deleted).
// No placeholder photo is ever added on the user's behalf; a listing keeps
// at least one photo (deleting the last one shows why it cannot).

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/services/photo_picker.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';

/// Most photos one listing keeps.
const int kMaxListingPhotos = 12;

/// A square photo thumbnail; a missing file shows "غير متاحة".
class PhotoThumb extends StatelessWidget {
  const PhotoThumb(this.src, {super.key, this.size = 96, this.radius = 14});

  final String src;
  final double size;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final missing = Container(
      color: kStoneBg,
      alignment: Alignment.center,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const LucideIcon(Lu.imageOff, size: 20, color: AppColors.muted),
          const SizedBox(height: 4),
          Tx('غير متاحة', style: ts(11, w: 600, lh: 16, c: AppColors.muted)),
        ],
      ),
    );
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: SizedBox(
        width: size,
        height: size,
        child: Image(image: imageFor(src), fit: BoxFit.cover, gaplessPlayback: true, errorBuilder: (context, error, stack) => missing),
      ),
    );
  }
}

/// The photo list editor (see the file comment).
class PhotoManager extends StatefulWidget {
  const PhotoManager({super.key, required this.photos, required this.onChanged});

  /// Current photos; the first one is the cover.
  final List<String> photos;
  final ValueChanged<List<String>> onChanged;

  @override
  State<PhotoManager> createState() => _PhotoManagerState();
}

class _PhotoManagerState extends State<PhotoManager> {
  String note = '';
  bool picking = false;

  List<String> get photos => widget.photos;

  Future<void> _add() async {
    if (picking) return;
    if (photos.length >= kMaxListingPhotos) {
      setState(() => note = 'الحد $kMaxListingPhotos صورة للعرض.');
      return;
    }
    setState(() {
      picking = true;
      note = '';
    });
    final r = await pickPhotos(multiple: true);
    if (!mounted) return;
    setState(() => picking = false);
    if (r.error != null) {
      setState(() => note = r.error!);
      return;
    }
    if (r.paths.isEmpty) return; // closed without choosing: nothing changes
    final next = <String>[...photos, ...r.paths.where((p) => !photos.contains(p))];
    widget.onChanged(next.take(kMaxListingPhotos).toList());
  }

  void _remove(int i) {
    if (photos.length <= 1) {
      setState(() => note = 'يبقى للعرض صورة واحدة على الأقل — أضف صورة أخرى ثم احذف هذه.');
      return;
    }
    setState(() => note = '');
    widget.onChanged(<String>[
      for (var k = 0; k < photos.length; k++)
        if (k != i) photos[k],
    ]);
  }

  void _makeCover(int i) {
    if (i == 0) return;
    setState(() => note = '');
    widget.onChanged(<String>[
      photos[i],
      for (var k = 0; k < photos.length; k++)
        if (k != i) photos[k],
    ]);
  }

  Widget _round(String icon, String label, VoidCallback onTap, {Color bg = const Color(0xE6FFFFFF), Color fg = AppColors.deep}) => Semantics(
    button: true,
    label: label,
    child: Tap(
      onTap: onTap,
      child: SizedBox(
        width: 36,
        height: 36,
        child: Center(
          child: Container(
            width: 28,
            height: 28,
            decoration: BoxDecoration(color: bg, shape: BoxShape.circle, boxShadow: AppShadows.hairline),
            alignment: Alignment.center,
            child: LucideIcon(icon, size: 15, strokeWidth: 2.2, color: fg),
          ),
        ),
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final tiles = <Widget>[
      for (var i = 0; i < photos.length; i++)
        KeyedSubtree(
          key: ValueKey<String>('photo-$i-${photos[i]}'),
          child: Stack(
            children: <Widget>[
              PhotoThumb(photos[i]),
              PositionedDirectional(top: 0, end: 0, child: _round(Lu.x, 'حذف الصورة ${i + 1}', () => _remove(i))),
              if (i == 0)
                PositionedDirectional(
                  bottom: 6,
                  start: 6,
                  child: Container(
                    height: 22,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
                    alignment: Alignment.center,
                    child: Tx('الغلاف', style: ts(11, w: 700, lh: 16, c: AppColors.white)),
                  ),
                )
              else
                PositionedDirectional(bottom: 0, start: 0, child: _round(Lu.star, 'اجعل الصورة ${i + 1} الغلاف', () => _makeCover(i))),
            ],
          ),
        ),
      if (photos.length < kMaxListingPhotos)
        Semantics(
          button: true,
          label: 'أضف صوراً من الاستوديو',
          child: Tap(
            key: const ValueKey<String>('photo-add'),
            onTap: _add,
            child: CustomPaint(
              foregroundPainter: const DashedRRectPainter(color: Color(0xFFBEC6C1), radius: 14),
              child: Container(
                width: 96,
                height: 96,
                decoration: BoxDecoration(color: kAddBox, borderRadius: BorderRadius.circular(14)),
                alignment: Alignment.center,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    LucideIcon(picking ? Lu.clock : Lu.imagePlus, size: 22, strokeWidth: 1.75, color: AppColors.muted),
                    const SizedBox(height: 4),
                    Tx('أضف صوراً', style: ts(12, w: 600, lh: 18, c: AppColors.muted)),
                  ],
                ),
              ),
            ),
          ),
        ),
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        // live preview of the cover
        if (photos.isNotEmpty)
          ClipRRect(
            borderRadius: BorderRadius.circular(18),
            child: AspectRatio(
              aspectRatio: 16 / 9,
              child: Image(
                image: imageFor(photos.first),
                fit: BoxFit.cover,
                gaplessPlayback: true,
                errorBuilder: (context, error, stack) => Container(
                  color: kStoneBg,
                  alignment: Alignment.center,
                  child: Tx('صورة الغلاف غير متاحة على الجهاز', style: ts(13, w: 600, lh: 19.5, c: AppColors.muted)),
                ),
              ),
            ),
          ),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 8, children: tiles),
        const SizedBox(height: 6),
        Tx('الصورة الأولى هي الغلاف — اضغط النجمة لتغييره.', style: ts(12, lh: 18, c: AppColors.muted)),
        if (note.isNotEmpty) ...<Widget>[const SizedBox(height: 8), FormError(note)],
      ],
    );
  }
}
