// Icons of the app — the open-source Lucide set (ISC licence), drawn as
// vectors so they stay crisp at any size and take any colour.
//
// Usage: `LucideIcon(Lu.heart, size: 20, color: AppColors.forest)`.
// To add an icon: copy its SVG shapes from lucide.dev into lucide_data.dart
// (same structure as the others) and add a constant to [Lu].

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/svg_path.dart';
import 'package:zon/core/theme/colors.dart';
part 'lucide_data.dart';

/// Names of every icon used in the app (Lucide names).
abstract final class Lu {
  static const arrowRight = 'ArrowRight';
  static const arrowUpFromLine = 'ArrowUpFromLine';
  static const badgeCheck = 'BadgeCheck';
  static const bath = 'Bath';
  static const bedDouble = 'BedDouble';
  static const bell = 'Bell';
  static const bellRing = 'BellRing';
  static const briefcase = 'Briefcase';
  static const building2 = 'Building2';
  static const calendar = 'Calendar';
  static const calendarClock = 'CalendarClock';
  static const calendarDays = 'CalendarDays';
  static const camera = 'Camera';
  static const check = 'Check';
  static const chevronDown = 'ChevronDown';
  static const chevronLeft = 'ChevronLeft';
  static const chevronRight = 'ChevronRight';
  static const circleAlert = 'CircleAlert';
  static const circleStop = 'CircleStop';
  static const clipboardList = 'ClipboardList';
  static const clock = 'Clock';
  static const creditCard = 'CreditCard';
  static const crosshair = 'Crosshair';
  static const crown = 'Crown';
  static const eye = 'Eye';
  static const fileCheck = 'FileCheck';
  static const filePenLine = 'FilePenLine';
  static const fileText = 'FileText';
  static const flag = 'Flag';
  static const handshake = 'Handshake';
  static const heart = 'Heart';
  static const home = 'Home';
  static const house = 'House';
  static const image = 'Image';
  static const imageOff = 'ImageOff';
  static const imagePlus = 'ImagePlus';
  static const inbox = 'Inbox';
  static const info = 'Info';
  static const keyRound = 'KeyRound';
  static const landmark = 'Landmark';
  static const landPlot = 'LandPlot';
  static const layers = 'Layers';
  static const layoutGrid = 'LayoutGrid';
  static const lightbulb = 'Lightbulb';
  static const link2 = 'Link2';
  static const lock = 'Lock';
  static const logIn = 'LogIn';
  static const mapPin = 'MapPin';
  static const maximize2 = 'Maximize2';
  static const messageCircle = 'MessageCircle';
  static const mic = 'Mic';
  static const micOff = 'MicOff';
  static const pencil = 'Pencil';
  static const phone = 'Phone';
  static const plus = 'Plus';
  static const scale = 'Scale';
  static const scanSearch = 'ScanSearch';
  static const search = 'Search';
  static const send = 'Send';
  static const settings = 'Settings';
  static const shield = 'Shield';
  static const shieldCheck = 'ShieldCheck';
  static const slidersHorizontal = 'SlidersHorizontal';
  static const smartphone = 'Smartphone';
  static const sofa = 'Sofa';
  static const sparkles = 'Sparkles';
  static const star = 'Star';
  static const tag = 'Tag';
  static const trash2 = 'Trash2';
  static const triangleAlert = 'TriangleAlert';
  static const user = 'User';
  static const userPlus = 'UserPlus';
  static const userRound = 'UserRound';
  static const users = 'Users';
  static const wallet = 'Wallet';
  static const warehouse = 'Warehouse';
  static const x = 'X';
  static const zap = 'Zap';
}

enum LucideShapeKind { path, circle, ellipse, rect, line, poly }

/// One primitive of a lucide icon (in the 24x24 viewBox).
class LucideShape {
  const LucideShape.path(this.d) : kind = LucideShapeKind.path, a = 0, b = 0, c = 0, e = 0, rx = 0, ry = 0, points = null, closed = false;
  const LucideShape.circle(this.a, this.b, this.c) : kind = LucideShapeKind.circle, d = null, e = 0, rx = 0, ry = 0, points = null, closed = false;
  const LucideShape.ellipse(this.a, this.b, this.rx, this.ry) : kind = LucideShapeKind.ellipse, d = null, c = 0, e = 0, points = null, closed = false;
  const LucideShape.rect(this.a, this.b, this.c, this.e, this.rx, this.ry) : kind = LucideShapeKind.rect, d = null, points = null, closed = false;
  const LucideShape.line(this.a, this.b, this.c, this.e) : kind = LucideShapeKind.line, d = null, rx = 0, ry = 0, points = null, closed = false;
  const LucideShape.poly(this.points, {this.closed = false}) : kind = LucideShapeKind.poly, d = null, a = 0, b = 0, c = 0, e = 0, rx = 0, ry = 0;

  final LucideShapeKind kind;
  final String? d;
  final double a, b, c, e, rx, ry;
  final List<double>? points;
  final bool closed;

  Path toPath() {
    switch (kind) {
      case LucideShapeKind.path:
        return parseSvgPath(d!);
      case LucideShapeKind.circle:
        return Path()..addOval(Rect.fromCircle(center: Offset(a, b), radius: c));
      case LucideShapeKind.ellipse:
        return Path()..addOval(Rect.fromCenter(center: Offset(a, b), width: rx * 2, height: ry * 2));
      case LucideShapeKind.rect:
        return Path()..addRRect(RRect.fromRectAndRadius(Rect.fromLTWH(a, b, c, e), Radius.elliptical(rx, ry)));
      case LucideShapeKind.line:
        return Path()
          ..moveTo(a, b)
          ..lineTo(c, e);
      case LucideShapeKind.poly:
        final pts = points!;
        final p = Path()..moveTo(pts[0], pts[1]);
        for (var k = 2; k + 1 < pts.length; k += 2) {
          p.lineTo(pts[k], pts[k + 1]);
        }
        if (closed) p.close();
        return p;
    }
  }
}

final Map<String, List<Path>> _pathCache = <String, List<Path>>{};

List<Path> _pathsFor(String name) {
  return _pathCache.putIfAbsent(name, () {
    final shapes = _lucideData[name];
    if (shapes == null) return const <Path>[];
    return shapes.map((s) => s.toPath()).toList(growable: false);
  });
}

/// Draws a Lucide icon. [strokeWidth] is measured on the icon's 24 × 24 grid,
/// so it scales with [size].
class LucideIcon extends StatelessWidget {
  const LucideIcon(this.name, {super.key, this.size = 24, this.color, this.strokeWidth = 2, this.fill});

  final String name;
  final double size;
  final Color? color;
  final double strokeWidth;

  /// When non-null the icon shapes are also filled with this colour
  /// (e.g. a liked heart).
  final Color? fill;

  @override
  Widget build(BuildContext context) {
    final c = color ?? DefaultTextStyle.of(context).style.color ?? AppColors.ink;
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(painter: _LucidePainter(name, c, strokeWidth, fill)),
    );
  }
}

class _LucidePainter extends CustomPainter {
  _LucidePainter(this.name, this.color, this.strokeWidth, this.fill);

  final String name;
  final Color color;
  final double strokeWidth;
  final Color? fill;

  @override
  void paint(Canvas canvas, Size size) {
    final paths = _pathsFor(name);
    canvas.save();
    canvas.scale(size.width / 24, size.height / 24);
    if (fill != null) {
      final f = Paint()
        ..style = PaintingStyle.fill
        ..color = fill!
        ..isAntiAlias = true;
      for (final p in paths) {
        canvas.drawPath(p, f);
      }
    }
    if (strokeWidth > 0) {
      final s = Paint()
        ..style = PaintingStyle.stroke
        ..color = color
        ..strokeWidth = strokeWidth
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..isAntiAlias = true;
      for (final p in paths) {
        canvas.drawPath(p, s);
      }
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(_LucidePainter old) => old.name != name || old.color != color || old.strokeWidth != strokeWidth || old.fill != fill;
}
