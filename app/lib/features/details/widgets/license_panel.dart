// Advertising licence card with its QR tile.

import 'package:flutter/material.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// QR-style tile for the advertising licence: a pattern drawn from the
/// licence number (kept from v2). It is NOT a scannable QR code — a real
/// code needs the REGA advertisement link once the service is connected.
class LicenseQr extends StatelessWidget {
  const LicenseQr({super.key, required this.value, this.size = 70, this.background = AppColors.offerPaper, this.padding = 3, this.radius = 10});

  final String value;
  final double size;
  final Color background;

  /// `p-1` is 4px; the licence panel overrides it to 3px.
  final double padding;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      padding: EdgeInsets.all(padding),
      decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(radius)),
      child: CustomPaint(painter: _QrPainter(value, padding)),
    );
  }
}

class _QrPainter extends CustomPainter {
  _QrPainter(this.value, this.pad);

  final String value;
  final double pad;

  static int _imul(int a, int b) => (a * b) & 0xFFFFFFFF;

  List<bool> _cells() {
    var h = 2166136261;
    for (final c in value.codeUnits) {
      h = (h ^ c) & 0xFFFFFFFF;
      h = _imul(h, 16777619);
    }
    var a = h;
    final cells = <bool>[];
    for (var i = 0; i < 441; i++) {
      a = (_imul(a, 1664525) + 1013904223) & 0xFFFFFFFF;
      cells.add(a % 3 != 0);
    }
    return cells;
  }

  @override
  void paint(Canvas canvas, Size size) {
    final cells = _cells();
    // The SVG is `size` wide with padding; its 21 cells use the full outer
    // size, so the grid is scaled into the content box.
    final full = size.width + 2 * pad;
    final o = full / 21;
    final scale = size.width / full;
    bool finder(int x, int y, int ox, int oy) {
      final dx = x - ox;
      final dy = y - oy;
      return dx == 0 || dy == 0 || dx == 6 || dy == 6 || (dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4);
    }

    bool on(int x, int y) {
      if (x < 7 && y < 7) return finder(x, y, 0, 0);
      if (x >= 14 && y < 7) return finder(x, y, 14, 0);
      if (x < 7 && y >= 14) return finder(x, y, 0, 14);
      return cells[x * 21 + y];
    }

    final p = Paint()..color = AppColors.ink;
    canvas.save();
    canvas.scale(scale);
    for (var x = 0; x < 21; x++) {
      for (var y = 0; y < 21; y++) {
        if (on(x, y)) canvas.drawRect(Rect.fromLTWH(y * o, x * o, o, o), p);
      }
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(_QrPainter old) => old.value != value || old.pad != pad;
}

/// Licence card linking to the REGA (الهيئة العامة للعقار) page.
class LicensePanel extends StatelessWidget {
  const LicensePanel({super.key, required this.license, this.size = 56});

  final String? license;
  final double size;

  @override
  Widget build(BuildContext context) {
    final lic = license;
    if (lic == null || lic.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Tap(
        onTap: () => openExternal('https://eservicesredp.rega.gov.sa/'),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(color: AppColors.offerPaper, borderRadius: BorderRadius.circular(22)),
          child: Row(
            children: <Widget>[
              LicenseQr(value: lic, size: size),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Tx('انقر هنا لعرض تفاصيل الإعلان لدى الهيئة العامة للعقار', style: ts(14, w: 600, lh: 21.5, c: AppColors.deep)),
                    const SizedBox(height: 4),
                    // the number itself, readable (the tile beside it is a
                    // pattern drawn from the number, not a scannable code)
                    Tx('رقم الترخيص الإعلاني: $lic', style: ts(13, w: 600, lh: 19.5, c: AppColors.sage)),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
