// The animated ZON identity card (بطاقة زون) and the small count tiles.
//
// The card uses the logo's palette: a deep-green face with mint light trails.

import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// A linear gradient across [rect] at [angleDeg] degrees (0 = upwards, clockwise).
ui.Shader angledGradient(Rect rect, double angleDeg, List<Color> colors, [List<double>? stops]) {
  final a = angleDeg * math.pi / 180;
  final dir = Offset(math.sin(a), -math.cos(a));
  final len = (rect.width * math.sin(a)).abs() + (rect.height * math.cos(a)).abs();
  final c = rect.center;
  return ui.Gradient.linear(c - dir * (len / 2), c + dir * (len / 2), colors, stops);
}

/// The ZON identity card: deep-green face, the logo, the user's name, a
/// rating chip, slowly turning orbits and a pulsing sensor.
class BarqCard extends StatefulWidget {
  const BarqCard({super.key, required this.name, this.locked = false, this.verified = false});

  final String name;
  final bool locked;
  final bool verified;

  @override
  State<BarqCard> createState() => _BarqCardState();
}

class _BarqCardState extends State<BarqCard> with SingleTickerProviderStateMixin {
  late final Ticker _ticker;
  final ValueNotifier<double> _t = ValueNotifier<double>(0);

  @override
  void initState() {
    super.initState();
    _ticker = createTicker((d) => _t.value = d.inMicroseconds / 1e6)..start();
  }

  @override
  void dispose() {
    _ticker.dispose();
    _t.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final w = box.maxWidth;
        final h = w / 1.586;
        // corner radius grows with the width (22–32)
        final vw = MediaQuery.sizeOf(context).width;
        final r = (vw * 0.05).clamp(22.0, 32.0);
        return Container(
          width: w,
          height: h,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(r),
            boxShadow: <BoxShadow>[decorShadow(const Color(0x47000000), 0, 30, 70), decorShadow(const Color(0x179EF3C7), 0, 0, 42)],
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(r),
            child: Stack(
              children: <Widget>[
                Positioned.fill(
                  child: RepaintBoundary(child: CustomPaint(painter: _BarqPainter(_t, r))),
                ),
                // card face (inside the 1 px border)
                Positioned.fill(
                  child: Padding(
                    padding: const EdgeInsets.all(1),
                    child: Stack(
                      children: <Widget>[
                        Positioned.fill(
                          child: Padding(
                            padding: const EdgeInsets.fromLTRB(20, 18.4, 20, 19.2),
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.end,
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: <Widget>[
                                Row(
                                  children: <Widget>[
                                    const ZonLogo(size: 48),
                                    const SizedBox(width: 14),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        mainAxisSize: MainAxisSize.min,
                                        children: <Widget>[
                                          Row(
                                            children: <Widget>[
                                              Flexible(
                                                child: Tx(
                                                  widget.name,
                                                  style: ts(16, w: 700, lh: 22, c: AppColors.cream),
                                                  maxLines: 1,
                                                  softWrap: false,
                                                  overflow: TextOverflow.ellipsis,
                                                ),
                                              ),
                                              if (widget.verified) ...<Widget>[
                                                const SizedBox(width: 6),
                                                const LucideIcon(Lu.badgeCheck, size: 16, color: Color(0xFF9EF3C7)),
                                              ],
                                            ],
                                          ),
                                          if (widget.locked) ...<Widget>[
                                            const SizedBox(height: 6),
                                            Tx('أدخل لفتح البطاقة', style: ts(13, w: 500, lh: 19.5, c: const Color(0xB3FFFFFF))),
                                          ],
                                        ],
                                      ),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        ),
                        // rating chip, top-left corner
                        Positioned(
                          top: 14,
                          left: 14,
                          child: Container(
                            height: 28,
                            padding: const EdgeInsets.only(left: 8, right: 10),
                            decoration: BoxDecoration(
                              color: const Color(0xFF9EF3C7),
                              borderRadius: BorderRadius.circular(999),
                              boxShadow: <BoxShadow>[decorShadow(const Color(0x409EF3C7), 0, 6, 16)],
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: <Widget>[
                                const LucideIcon(Lu.star, size: 13, strokeWidth: 0, color: AppColors.logoGreen, fill: AppColors.logoGreen),
                                const SizedBox(width: 4),
                                Tx('4.8', style: ts(14, w: 700, lh: 21, c: AppColors.logoGreen)),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _BarqPainter extends CustomPainter {
  _BarqPainter(this.t, this.radius) : super(repaint: t);

  final ValueNotifier<double> t;
  final double radius;

  static const Color _pulse = Color(0xFF9EF3C7);

  ui.Picture? _dots;
  Size? _dotsSize;

  ui.Picture _dotLayer(Size s) {
    if (_dots != null && _dotsSize == s) return _dots!;
    final rec = ui.PictureRecorder();
    final c = Canvas(rec);
    // radial-gradient(#ffffff8f .55px, #0000 .55px) / 6px 6px, opacity .24, overlay
    final p = Paint()
      ..color = const Color(0x8FFFFFFF)
      ..isAntiAlias = true;
    for (var y = 3.0; y < s.height + 3; y += 6) {
      for (var x = 3.0; x < s.width + 3; x += 6) {
        c.drawCircle(Offset(x, y), 0.55, p);
      }
    }
    _dots = rec.endRecording();
    _dotsSize = s;
    return _dots!;
  }

  @override
  void paint(Canvas canvas, Size size) {
    final outer = RRect.fromRectAndRadius(Offset.zero & size, Radius.circular(radius));
    final inner = outer.deflate(1);
    final pad = inner.outerRect; // padding box
    final w = pad.width;
    final h = pad.height;

    // background (painted under the border; the image is sized to the padding box)
    canvas.drawRRect(
      outer,
      Paint()
        ..shader = angledGradient(
          pad,
          145,
          const <Color>[Color(0xFF2B4A3B), AppColors.logoGreen, Color(0xFF1D3A2D), Color(0xFF0A1711)],
          const <double>[0, 0.25, 0.56, 1],
        ),
    );
    canvas.drawRRect(outer, Paint()..shader = angledGradient(pad, 116, const <Color>[Color(0x1F06120D), Color(0xD606120D)]));

    canvas.save();
    canvas.clipRRect(inner);
    canvas.translate(pad.left, pad.top);
    final local = Offset.zero & pad.size;

    // dot grid texture (overlay blend, 24 % opacity)
    canvas.saveLayer(
      local,
      Paint()
        ..blendMode = BlendMode.overlay
        ..color = const Color.fromRGBO(0, 0, 0, 0.24),
    );
    canvas.drawPicture(_dotLayer(pad.size));
    canvas.restore();

    // glow near the bottom-right and a faint diagonal sheen
    final gc = Offset(w * 0.82, h * 0.86);
    final far = math.sqrt(gc.dx * gc.dx + gc.dy * gc.dy);
    canvas.drawRect(
      local,
      Paint()
        ..shader = ui.Gradient.radial(gc, far, const <Color>[Color(0x1C9EF3C7), Color(0x009EF3C7), Color(0x009EF3C7)], const <double>[0, 0.28, 1]),
    );
    canvas.drawRect(
      local,
      Paint()
        ..shader = angledGradient(
          local,
          132,
          const <Color>[Color(0x00FFFFFF), Color(0x00FFFFFF), Color(0x0DFFFFFF), Color(0x00FFFFFF), Color(0x00FFFFFF)],
          const <double>[0, 0.48, 0.49, 0.50, 1],
        ),
    );

    final secs = t.value;

    // orbits (border 1px, radius 50%, one side transparent)
    void orbit(Rect box, double rotDeg, double gapCenterDeg, Color color) {
      canvas.save();
      canvas.translate(box.center.dx, box.center.dy);
      canvas.rotate(rotDeg * math.pi / 180);
      final r = box.width / 2 - 0.5;
      final start = (gapCenterDeg + 45) * math.pi / 180;
      canvas.drawArc(
        Rect.fromCircle(center: Offset.zero, radius: r),
        start,
        270 * math.pi / 180,
        false,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1
          ..color = color
          ..isAntiAlias = true,
      );
      canvas.restore();
    }

    final o1 = w * 0.92;
    orbit(Rect.fromLTWH(-0.15 * w, -0.75 * h, o1, o1), 28 + 360 * ((secs / 18) % 1), 0, const Color(0x6E9EF3C7));
    final o2 = w * 1.12;
    orbit(Rect.fromLTWH(-0.55 * w, -0.35 * h, o2, o2), -32 + 360 - 360 * ((secs / 24) % 1), 90, const Color(0x21FFFFFF));
    final o3 = w * 0.96;
    orbit(Rect.fromLTWH(w + 0.34 * w - o3, h + 1.08 * h - o3, o3, o3), 0, 270, const Color(0x2E9EF3C7));

    // electric path
    {
      final pw = w * 0.48;
      final box = Rect.fromLTWH(w + 0.05 * w - pw, 0.38 * h, pw, 1);
      canvas.save();
      canvas.translate(box.center.dx, box.center.dy);
      canvas.rotate(-14 * math.pi / 180);
      final line = Rect.fromCenter(center: Offset.zero, width: pw, height: 1);
      canvas.drawRect(
        line,
        Paint()
          ..color = const Color(0x739EF3C7)
          ..maskFilter = MaskFilter.blur(BlurStyle.normal, _sigma(14)),
      );
      canvas.drawRect(
        line,
        Paint()..shader = angledGradient(line, 90, const <Color>[Color(0x009EF3C7), _pulse, Color(0x269EF3C7)], const <double>[0, 0.5, 1]),
      );
      final dot = Offset(line.left + pw * 0.42 + 3.5, line.top - 3 + 3.5);
      canvas.drawCircle(
        dot,
        3.5,
        Paint()
          ..color = _pulse
          ..maskFilter = MaskFilter.blur(BlurStyle.normal, _sigma(16)),
      );
      canvas.drawCircle(dot, 3.5, Paint()..color = _pulse);
      canvas.restore();
    }

    // sensor (pulses: scale 1.09 + drop-shadow at 50%)
    {
      final phase = (secs % 3.5) / 3.5;
      final half = phase < 0.5 ? phase * 2 : (1 - phase) * 2;
      final k = Curves.easeInOut.transform(half);
      const s = 12.0;
      final box = Rect.fromLTWH(w - 0.11 * w - s, 0.12 * h, s, s);
      canvas.save();
      canvas.translate(box.center.dx, box.center.dy);
      final sc = 1 + 0.09 * k;
      canvas.scale(sc, sc);
      if (k > 0.001) {
        // soft glow around the sensor at the peak of the pulse
        final glow = Paint()
          ..color = Color.fromRGBO(0x9E, 0xF3, 0xC7, 0xA3 / 255 * k)
          ..maskFilter = MaskFilter.blur(BlurStyle.normal, _sigma(6 * k));
        canvas.drawCircle(
          Offset.zero,
          10,
          glow
            ..style = PaintingStyle.stroke
            ..strokeWidth = 1,
        );
        canvas.drawCircle(
          Offset.zero,
          5.5,
          Paint()
            ..color = glow.color
            ..style = PaintingStyle.stroke
            ..strokeWidth = 1
            ..maskFilter = glow.maskFilter,
        );
        canvas.drawCircle(
          Offset.zero,
          2.3,
          Paint()
            ..color = glow.color
            ..maskFilter = glow.maskFilter,
        );
      }
      // outer glow
      canvas.drawCircle(
        Offset.zero,
        6,
        Paint()
          ..color = const Color(0x529EF3C7)
          ..maskFilter = MaskFilter.blur(BlurStyle.outer, _sigma(14)),
      );
      // ring
      canvas.drawCircle(
        Offset.zero,
        5.5,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1
          ..color = const Color(0xA89EF3C7),
      );
      // inner dot with glow
      const dr = 2.3125;
      canvas.drawCircle(
        Offset.zero,
        dr,
        Paint()
          ..color = _pulse
          ..maskFilter = MaskFilter.blur(BlurStyle.outer, _sigma(9)),
      );
      canvas.drawCircle(Offset.zero, dr, Paint()..color = _pulse);
      // dashed ring (20 px circle)
      final dashPaint = Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1
        ..color = const Color(0x739EF3C7);
      const rr = 9.5;
      const n = 12;
      const circ = 2 * math.pi * rr;
      const dash = 3.0 / circ * 2 * math.pi;
      for (var i = 0; i < n; i++) {
        final a0 = -math.pi / 2 + i * 2 * math.pi / n;
        canvas.drawArc(Rect.fromCircle(center: Offset.zero, radius: rr), a0, dash, false, dashPaint);
      }
      canvas.restore();
    }

    // three slanted metal bars, the tallest on the left
    {
      final bh = 0.13 * h;
      final bottom = h - 0.10 * h;
      final left = 0.09 * w;
      const bw = 4.0;
      final heights = <double>[bh, bh * 0.70, bh * 0.42];
      for (var i = 0; i < 3; i++) {
        final x = left + i * (bw + 5);
        final rect = Rect.fromLTWH(x, bottom - heights[i], bw, heights[i]);
        canvas.save();
        canvas.translate(rect.center.dx, rect.center.dy);
        canvas.transform(_skewX(-16 * math.pi / 180));
        final rr = RRect.fromRectAndRadius(Rect.fromCenter(center: Offset.zero, width: bw, height: heights[i]), const Radius.circular(2));
        canvas.drawRRect(
          rr,
          Paint()
            ..shader = angledGradient(
              rr.outerRect,
              90,
              const <Color>[Color(0xDBB4C2B9), Color(0xDBFAF6EA), Color(0xDB7F8F86)],
              const <double>[0, 0.52, 1],
            ),
        );
        canvas.restore();
      }
    }

    // inset 0 1px #ffffff3d (top highlight inside the border)
    final innerLocal = inner.shift(-pad.topLeft);
    canvas.drawPath(
      Path.combine(PathOperation.difference, Path()..addRRect(innerLocal), Path()..addRRect(innerLocal.shift(const Offset(0, 1)))),
      Paint()..color = const Color(0x3DFFFFFF),
    );
    canvas.restore();

    // border 1px #ffffff29
    canvas.drawDRRect(outer, inner, Paint()..color = const Color(0x29FFFFFF));
  }

  /// Blur radius → Gaussian sigma (half the radius).
  static double _sigma(double blur) => blur / 2;

  @override
  bool shouldRepaint(_BarqPainter old) => old.radius != radius;
}

/// Horizontal skew by angle [a] as a 4×4 matrix.
Float64List _skewX(double a) {
  final m = Float64List(16);
  m[0] = 1;
  m[5] = 1;
  m[10] = 1;
  m[15] = 1;
  m[4] = math.tan(a);
  return m;
}

/// A small count tile (المتابعون / تابع / المفضلة).
class MeStat extends StatelessWidget {
  const MeStat({super.key, required this.n, required this.label});

  final String n;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const <BoxShadow>[
          BoxShadow(color: AppColors.mistLine, spreadRadius: 1),
          ...AppShadows.card,
        ],
      ),
      child: Column(
        children: <Widget>[
          Tx(
            n,
            style: ts(20, w: 600, lh: 28, c: AppColors.deep),
            align: TextAlign.center,
          ),
          Tx(
            label,
            style: ts(12, lh: 18, c: AppColors.stone),
            align: TextAlign.center,
          ),
        ],
      ),
    );
  }
}
