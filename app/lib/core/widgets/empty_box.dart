// "Nothing here" placeholder with an optional action button.

import 'package:flutter/widgets.dart';

import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/tap.dart';

/// Body text style of [EmptyBox].
TextStyle emptyTextStyle({int w = 500}) => ts(15, w: w, lh: 21.5, c: AppColors.muted);

/// White box with a dashed border, a title, a hint and an optional action.
class EmptyBox extends StatelessWidget {
  const EmptyBox({super.key, required this.title, required this.text, required this.textStyle, this.action, this.onAction});

  final String title;
  final String text;
  final TextStyle textStyle;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      foregroundPainter: const DashedRRectPainter(color: Color(0xFFD5DCD7), radius: 18),
      child: Container(
        decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(18)),
        // padding 28px 16px inside a 1px dashed border
        padding: const EdgeInsets.fromLTRB(17, 29, 17, 29),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Tx(
              title,
              style: ts(16, w: 700, lh: 24, c: AppColors.ink),
              align: TextAlign.center,
            ),
            const SizedBox(height: 10),
            Tx(text, style: textStyle, align: TextAlign.center),
            if (action != null) ...<Widget>[
              const SizedBox(height: 10),
              Tap(
                onTap: onAction,
                child: Container(
                  height: 40,
                  decoration: BoxDecoration(color: AppColors.deep, borderRadius: BorderRadius.circular(12)),
                  alignment: Alignment.center,
                  child: Tx(action!, style: ts(16, w: 700, lh: 24, c: AppColors.white)),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Paints a 1 px dashed rounded-rectangle border.
class DashedRRectPainter extends CustomPainter {
  const DashedRRectPainter({required this.color, required this.radius, this.dash = 3, this.gap = 3});

  final Color color;
  final double radius;
  final double dash;
  final double gap;

  @override
  void paint(Canvas canvas, Size size) {
    final rrect = RRect.fromRectAndRadius(Offset.zero & size, Radius.circular(radius)).deflate(0.5);
    final path = Path()..addRRect(rrect);
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1;
    for (final m in path.computeMetrics()) {
      var d = 0.0;
      while (d < m.length) {
        canvas.drawPath(m.extractPath(d, d + dash), paint);
        d += dash + gap;
      }
    }
  }

  @override
  bool shouldRepaint(DashedRRectPainter old) => old.color != color || old.radius != radius;
}
