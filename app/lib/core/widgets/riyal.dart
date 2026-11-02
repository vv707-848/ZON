// The new Saudi riyal sign (vector) and price text that uses it.

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/svg_path.dart';
import 'package:zon/core/theme/theme.dart';

// Official glyph outline (SAMA), in its own 1124 × 1256 coordinate space.
const double _riyalW = 1124.14;
const double _riyalH = 1256.39;

final List<Path> _riyalPaths = <Path>[
  parseSvgPath('M699.62,1113.02h0c-20.06,44.48-33.32,92.75-38.4,143.37l424.51-90.24c20.06-44.47,33.31-92.75,38.4-143.37l-424.51,90.24Z'),
  parseSvgPath(
    'M1085.73,895.8c20.06-44.47,33.32-92.75,38.4-143.37l-330.68,70.33v-135.2l292.27-62.11c20.06-44.47,33.32-92.75,38.4-143.37l-330.68,70.27V66.13c-50.67,28.45-95.67,66.32-132.25,110.99v403.35l-132.25,28.11V0c-50.67,28.44-95.67,66.32-132.25,110.99v525.69l-295.91,62.88c-20.06,44.47-33.33,92.75-38.42,143.37l334.33-71.05v170.26l-358.3,76.14c-20.06,44.47-33.32,92.75-38.4,143.37l375.04-79.7c30.53-6.35,56.77-24.4,73.83-49.24l68.78-101.97v-.02c7.14-10.55,11.3-23.27,11.3-36.97v-149.98l132.25-28.11v270.4l424.53-90.28Z',
  ),
];

/// The Saudi riyal sign, scaled to fit (centred) in a [width]×[height] box.
class RiyalIcon extends StatelessWidget {
  const RiyalIcon({super.key, required this.width, required this.height, required this.color});

  final double width;
  final double height;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: width,
      height: height,
      child: CustomPaint(painter: _RiyalPainter(color)),
    );
  }
}

class _RiyalPainter extends CustomPainter {
  _RiyalPainter(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final s = (size.width / _riyalW) < (size.height / _riyalH) ? size.width / _riyalW : size.height / _riyalH;
    final dx = (size.width - _riyalW * s) / 2;
    final dy = (size.height - _riyalH * s) / 2;
    canvas.save();
    canvas.translate(dx, dy);
    canvas.scale(s);
    final p = Paint()
      ..color = color
      ..isAntiAlias = true;
    for (final path in _riyalPaths) {
      canvas.drawPath(path, p);
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(_RiyalPainter old) => old.color != color;
}

/// An amount with the riyal sign (always laid out left-to-right).
class SarText extends StatelessWidget {
  const SarText(
    this.text, {
    super.key,
    required this.style,
    required this.iconW,
    required this.iconH,
    this.gap = 4,
    this.iconColor,
    this.mainAxisAlignment = MainAxisAlignment.start,
  });

  final String text;
  final TextStyle style;
  final double iconW;
  final double iconH;
  final double gap;
  final Color? iconColor;
  final MainAxisAlignment mainAxisAlignment;

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        mainAxisAlignment: mainAxisAlignment,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: <Widget>[
          RiyalIcon(width: iconW, height: iconH, color: iconColor ?? style.color ?? AppColors.ink),
          SizedBox(width: gap),
          Flexible(child: Tx(text, style: style, maxLines: 1, softWrap: false)),
        ],
      ),
    );
  }
}
