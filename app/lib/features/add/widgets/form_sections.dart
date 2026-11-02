// Section-level pieces of the add / edit forms: page headings, the cover hero,
// white form cards, hints and the photo picker.

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/services/photo_picker.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';
import 'package:zon/features/add/widgets/photo_manager.dart' show PhotoThumb;

// ------------------------------------------------------------------ headings

/// Heading of the short add steps: a small kicker line above a large title.
class AddHead extends StatelessWidget {
  const AddHead({super.key, required this.kicker, required this.title});

  final String kicker;
  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 4, 4, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx(kicker, style: ts(15, w: 500, lh: 22.5, c: AppColors.sage)),
          const SizedBox(height: 4),
          Tx(title, style: ts(28, w: 700, lh: 36, c: AppColors.deep)),
        ],
      ),
    );
  }
}

/// Header of the long forms: back button, title, subtitle and cover image.
class ProjHero extends StatelessWidget {
  const ProjHero({super.key, required this.title, required this.sub, required this.image, required this.onBack});

  final String title;
  final String sub;

  /// Asset or file path of the cover (the first picked photo, or the
  /// property type's default image).
  final String image;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    final cam = Container(
      height: 32,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const LucideIcon(Lu.camera, size: 14, color: AppColors.white),
          const SizedBox(width: 6),
          Tx('تعديل الصورة', style: ts(13, w: 600, lh: 19.5, c: AppColors.white)),
        ],
      ),
    );
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: BackCircle(onTap: onBack),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(top: 4, bottom: 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      Tx(title, style: ts(22, w: 700, lh: 32, c: AppColors.deep)),
                      const SizedBox(height: 2),
                      Tx(sub, style: ts(13, w: 500, lh: 19, c: AppColors.muted)),
                    ],
                  ),
                ),
              ),
            ],
          ),
          Container(
            height: 168,
            clipBehavior: Clip.antiAlias,
            decoration: BoxDecoration(borderRadius: BorderRadius.circular(24)),
            child: Stack(
              fit: StackFit.expand,
              children: <Widget>[
                AppImage(image),
                PositionedDirectional(start: 12, bottom: 12, child: cam),
              ],
            ),
          ),
          const SizedBox(height: 4),
        ],
      ),
    );
  }
}

// ------------------------------------------------------------------ cards

/// White rounded section of a long form.
class ProjCard extends StatelessWidget {
  const ProjCard({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 20),
      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(28), boxShadow: AppShadows.card),
      child: child,
    );
  }
}

/// Heading inside a form card (e.g. "الوحدات — 3").
Widget projCardHead(String text) => Padding(
  padding: const EdgeInsets.fromLTRB(2, 4, 2, 0),
  child: Tx(text, style: ts(16, w: 700, lh: 24, c: AppColors.deep)),
);

/// Small explanatory note inside a form card.
Widget projHint(String text) => Container(
  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
  decoration: BoxDecoration(color: kAddBox, borderRadius: BorderRadius.circular(16)),
  child: Tx(text, style: ts(13, w: 500, lh: 19, c: kAddIc)),
);

// ------------------------------------------------------------------ photos

/// Photo picker of the add forms (listing photos, project cover, unit
/// image). Shows the photos already chosen ([current]) as thumbnails, and a
/// short message when the gallery cannot be used (permission refused…);
/// closing the gallery without choosing changes nothing.
class PhotoDrop extends StatefulWidget {
  const PhotoDrop({super.key, required this.onFiles, this.current = const <String>[]});

  final ValueChanged<List<String>> onFiles;

  /// Photos already chosen (shown as thumbnails).
  final List<String> current;

  @override
  State<PhotoDrop> createState() => _PhotoDropState();
}

class _PhotoDropState extends State<PhotoDrop> {
  String note = '';

  Future<void> _pick() async {
    final r = await pickPhotos(multiple: true);
    if (!mounted) return;
    setState(() => note = r.error ?? '');
    if (r.paths.isEmpty) return;
    widget.onFiles(r.paths);
  }

  @override
  Widget build(BuildContext context) {
    final shown = widget.current.where((p) => p.isNotEmpty).toList();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tap(
          onTap: _pick,
          child: CustomPaint(
            foregroundPainter: const DashedRRectPainter(color: Color(0xFFBEC6C1), radius: 28),
            child: Container(
              constraints: const BoxConstraints(minHeight: 104),
              padding: EdgeInsets.symmetric(horizontal: 17, vertical: shown.isEmpty ? 29 : 14),
              decoration: BoxDecoration(color: kAddBox, borderRadius: BorderRadius.circular(28)),
              alignment: Alignment.center,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (shown.isNotEmpty) ...<Widget>[
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      alignment: WrapAlignment.center,
                      children: <Widget>[for (final p in shown.take(6)) PhotoThumb(p, size: 52, radius: 10)],
                    ),
                    const SizedBox(height: 10),
                  ],
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      const LucideIcon(Lu.imagePlus, size: 22, strokeWidth: 1.75, color: AppColors.muted),
                      const SizedBox(width: 8),
                      Flexible(
                        child: Tx(
                          shown.isEmpty ? 'أضف صوراً من الاستوديو' : 'استبدل الصور من الاستوديو',
                          style: ts(16, w: 600, lh: 24, c: AppColors.muted),
                          align: TextAlign.center,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
        if (note.isNotEmpty) ...<Widget>[const SizedBox(height: 8), FormError(note)],
      ],
    );
  }
}
