// The زون logo: a cream "Z" joined by a mint node, on the brand green.
//
// Drawn from vector paths traced from the official logo, so it is sharp at
// every size and needs no image file. The launcher icons and the splash
// images in android/ and ios/ are rendered from the same shapes.
//
//   ZonLogo(size: 44)                                   // the icon tile
//   ZonLogo.mark(size: 280, color: AppColors.mistLine)  // the mark alone, one colour

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/svg_path.dart';
import 'package:zon/core/theme/theme.dart';

/// Outline of the "Z" (two halves) in a 1000 × 1000 box.
const List<String> _zPaths = <String>[
  'M725 788.4 C748.2 782.8 766.9 768.2 777.1 747.7 C782.3 737.1 784.3 729.6 784.7 718.5 C785.3 706.5 783 695.7 777.6 684.6 '
      'C767.5 663.7 749.5 649.6 725.9 644.1 L721.1 643 L553.3 642.8 C460.9 642.8 385.4 642.6 385.4 642.5 C385.4 642.1 393.2 634.7 416.9 612.7 '
      'C429.2 601.3 443.1 588.5 447.7 584.2 C452.2 579.9 456.3 576.1 456.8 575.7 C457.4 575.1 457.2 574.9 455.2 574.1 C432.5 565.3 416.4 548 408.6 524.2 '
      'C405.8 515.8 404.7 508.2 404.7 498.5 C404.7 486.8 406.8 475.2 410.7 464.8 C412.2 460.8 412.2 460.8 407 465.6 C401.3 470.8 360.9 509.4 346.6 523.2 '
      'C338.9 530.7 317.9 550.8 300 568 C247.6 618.1 245.5 620.2 241.1 625.5 C230.8 637.8 223.6 651.4 219.1 667.2 C211.4 693.9 215.9 722.6 231.6 746.9 '
      'C245.9 768.9 268.2 784.3 292 788.5 C297.5 789.5 302.9 789.5 509.4 789.4 C711 789.3 721.3 789.3 725 788.4 Z',
  'M633.1 456.9 C653.7 436.3 685.9 404.4 704.6 385.9 C723.3 367.4 740.2 350.6 742 348.4 C753.9 334.1 761.7 316.6 764.4 298.4 '
      'C765.6 290.1 765.2 274.4 763.6 266.5 C757.4 236 737.8 212.6 711.5 204.3 C701.4 201.1 716.4 201.3 500 201.3 L301.3 201.3 L295.8 202.5 '
      'C280.9 205.8 269 212 258.5 222 C248.2 231.9 241.8 242.9 237.7 257.6 C235.9 263.9 235.4 276.6 236.7 283.8 C241.6 312.1 265.4 336 293.7 340.8 '
      'C298.2 341.6 312.7 341.7 446.2 341.7 C557.8 341.7 593.8 341.8 594.1 342.3 C594.3 342.6 593.8 343.4 592.9 344.2 C590.4 346.3 531.8 403.3 531.8 403.6 '
      'C531.8 403.8 533.2 404.3 534.9 404.8 C552.9 410.1 571.1 424.3 581.2 440.7 C589.5 454.4 594.5 471.8 594.9 488.7 C595 491.8 595.2 494.3 595.3 494.3 '
      'C595.4 494.3 612.4 477.4 633.1 456.9 Z',
];

/// The node: one large dot and four small ones around it (x, y, radius).
const List<(double, double, double)> _nodeDots = <(double, double, double)>[
  (499.4, 492.3, 45.6),
  (499.4, 421.0, 13.3),
  (499.4, 563.7, 13.3),
  (428.0, 492.3, 13.3),
  (570.7, 492.3, 13.3),
];

final List<Path> _zParsed = <Path>[for (final d in _zPaths) parseSvgPath(d)];

/// The logo, as the app-icon tile or as a one-colour mark.
class ZonLogo extends StatelessWidget {
  /// The icon tile: green rounded square, cream Z, mint node.
  /// [radius] defaults to the icon's corner (22 % of [size]).
  const ZonLogo({super.key, this.size = 44, this.radius}) : markColor = null;

  /// The Z and the node alone, painted in [color] (no tile).
  const ZonLogo.mark({super.key, this.size = 44, required Color color}) : markColor = color, radius = 0;

  final double size;
  final double? radius;

  /// Non-null for [ZonLogo.mark].
  final Color? markColor;

  @override
  Widget build(BuildContext context) {
    return SizedBox.square(
      dimension: size,
      child: CustomPaint(
        painter: _LogoPainter(markColor: markColor, radius: radius ?? size * 0.22),
      ),
    );
  }
}

class _LogoPainter extends CustomPainter {
  const _LogoPainter({required this.markColor, required this.radius});

  final Color? markColor;
  final double radius;

  @override
  void paint(Canvas canvas, Size size) {
    final tile = markColor == null;
    if (tile) {
      canvas.drawRRect(RRect.fromRectAndRadius(Offset.zero & size, Radius.circular(radius)), Paint()..color = AppColors.logoGreen);
    }
    canvas.save();
    canvas.scale(size.width / 1000, size.height / 1000);
    final z = Paint()
      ..color = markColor ?? AppColors.cream
      ..isAntiAlias = true;
    for (final p in _zParsed) {
      canvas.drawPath(p, z);
    }
    final dot = Paint()
      ..color = markColor ?? AppColors.mint
      ..isAntiAlias = true;
    for (final (x, y, r) in _nodeDots) {
      canvas.drawCircle(Offset(x, y), r, dot);
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(_LogoPainter old) => old.markColor != markColor || old.radius != radius;
}
