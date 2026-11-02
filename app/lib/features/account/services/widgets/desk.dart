// The "desk" pieces shared by the account services: the dark summary card,
// counters, progress ring, toolbar, segmented filters and search field.

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// Soft text on the dark desk card.
const Color kDeskSoft = Color(0xFFC5D0C8);

/// Light green of icons and small headings on the desk card.
const Color kDeskMint = Color(0xFFC8E6D0);

/// Value colour of the filter pickers on the desk card (city, district,
/// type): the logo's mint, the same for every filter.
const Color kDeskAccent = AppColors.mint;

/// The dark green summary card at the top of the services (with a soft
/// breathing glow and a scan sweep while the AI works).
class DeskCard extends StatefulWidget {
  const DeskCard({
    super.key,
    required this.children,
    this.scanning = false,
    this.orbSize = 180,
    this.padding = const EdgeInsets.fromLTRB(16, 20, 16, 16),
    this.showScan = true,
  });

  final List<Widget> children;

  /// While true the scan sweep runs faster (0.9 s instead of 2.8 s).
  final bool scanning;
  final double orbSize;
  final EdgeInsets padding;
  final bool showScan;

  @override
  State<DeskCard> createState() => _DeskCardState();
}

class _DeskCardState extends State<DeskCard> with SingleTickerProviderStateMixin {
  late final Ticker _ticker;
  final ValueNotifier<double> _t = ValueNotifier<double>(0);

  /// Scan progress is integrated so a duration change keeps the position.
  double _scanPhase = 0;
  double _last = 0;

  @override
  void initState() {
    super.initState();
    _ticker = createTicker((d) {
      final now = d.inMicroseconds / 1e6;
      final dt = now - _last;
      _last = now;
      _scanPhase = (_scanPhase + dt / (widget.scanning ? 0.9 : 2.8)) % 1;
      _t.value = now;
    })..start();
  }

  @override
  void dispose() {
    _ticker.dispose();
    _t.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(22)),
      clipBehavior: Clip.antiAlias,
      child: Stack(
        children: <Widget>[
          Positioned.fill(
            child: RepaintBoundary(child: CustomPaint(painter: _DeskFx(_t, () => _scanPhase, widget.orbSize, widget.showScan))),
          ),
          Padding(
            padding: widget.padding,
            child: DefaultTextStyle.merge(
              style: const TextStyle(color: Color(0xFFE8EEE9)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: widget.children),
            ),
          ),
        ],
      ),
    );
  }
}

class _DeskFx extends CustomPainter {
  _DeskFx(this.t, this.scan, this.orb, this.showScan) : super(repaint: t);

  final ValueNotifier<double> t;
  final double Function() scan;
  final double orb;
  final bool showScan;

  @override
  void paint(Canvas canvas, Size size) {
    // desk-breathe 4.8s ease-in-out: .55 → 1 → .55
    final ph = (t.value % 4.8) / 4.8;
    final half = ph < 0.5 ? ph * 2 : (1 - ph) * 2;
    final op = 0.55 + 0.45 * Curves.easeInOut.transform(half);
    final c = Offset(-40 + orb / 2, -70 + orb / 2);
    final r = orb / 2;
    final far = r * math.sqrt2;
    canvas.save();
    canvas.clipPath(Path()..addOval(Rect.fromCircle(center: c, radius: r)));
    canvas.drawCircle(
      c,
      r,
      Paint()
        ..shader = RadialGradient(
          colors: <Color>[
            const Color(0x337DCF9A).withValues(alpha: 0.2 * op),
            const Color(0x007DCF9A),
            const Color(0x007DCF9A),
          ],
          stops: const <double>[0, 0.7, 1],
        ).createShader(Rect.fromCircle(center: c, radius: far)),
    );
    canvas.restore();

    if (showScan) {
      // the scan bar sweeps across the card
      final w = size.width * 0.42;
      final p = Curves.easeInOut.transform(scan());
      final x = (-1.2 + 4.0 * p) * w;
      final rect = Rect.fromLTWH(x, 0, w, size.height);
      canvas.drawRect(
        rect,
        Paint()..shader = const LinearGradient(colors: <Color>[Color(0x007DCF9A), Color(0x287DCF9A), Color(0x007DCF9A)]).createShader(rect),
      );
    }
  }

  @override
  bool shouldRepaint(_DeskFx old) => old.orb != orb || old.showScan != showScan;
}

/// 56 px progress ring with a centred label.
class DeskRing extends StatelessWidget {
  const DeskRing({super.key, required this.pct, required this.label});

  final double pct;
  final String label;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 56,
      height: 56,
      child: Stack(
        children: <Widget>[
          Positioned.fill(child: CustomPaint(painter: _RingPainter(pct / 100))),
          Center(
            child: Tx(label, style: ts(12, w: 700, lh: 18, c: AppColors.white)),
          ),
        ],
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  _RingPainter(this.frac);

  final double frac;

  @override
  void paint(Canvas canvas, Size size) {
    final c = size.center(Offset.zero);
    const r = 22.0;
    canvas.drawCircle(
      c,
      r,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 4
        ..color = const Color(0x24FFFFFF),
    );
    if (frac > 0) {
      canvas.drawArc(
        Rect.fromCircle(center: c, radius: r),
        -math.pi / 2,
        2 * math.pi * frac.clamp(0.0, 1.0),
        false,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 4
          ..strokeCap = StrokeCap.round
          ..color = kDeskMint,
      );
    }
  }

  @override
  bool shouldRepaint(_RingPainter old) => old.frac != frac;
}

class DeskMeter {
  const DeskMeter({required this.value, required this.label, required this.tone, this.on = false, this.onTap, this.valueWidget});

  final String value;
  final String label;

  /// "hot" | "hold" | "ok"
  final String tone;
  final bool on;
  final VoidCallback? onTap;
  final Widget? valueWidget;
}

/// Three counter tiles (they also act as filters).
class DeskMeters extends StatelessWidget {
  const DeskMeters({super.key, required this.items});

  final List<DeskMeter> items;

  @override
  Widget build(BuildContext context) {
    Color tone(String t) => switch (t) {
      'hot' => const Color(0xFFF3B4A8),
      'hold' => const Color(0xFFE8D48A),
      _ => const Color(0xFF9BE7C4),
    };
    return Padding(
      padding: const EdgeInsets.only(top: 16),
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < items.length; i++) ...<Widget>[
              if (i > 0) const SizedBox(width: 8),
              Expanded(
                child: Tap(
                  onTap: items[i].onTap,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
                    decoration: BoxDecoration(
                      color: items[i].on ? AppColors.white : const Color(0x14EEF2EE),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Column(
                      children: <Widget>[
                        items[i].valueWidget ??
                            Tx(
                              items[i].value,
                              style: ts(22, w: 700, lh: 33, c: tone(items[i].tone)),
                              align: TextAlign.center,
                            ),
                        const SizedBox(height: 4),
                        Tx(
                          items[i].label,
                          style: ts(13, w: 600, lh: 19.5, c: items[i].on ? AppColors.forest : kDeskSoft),
                          align: TextAlign.center,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// White action button inside the desk.
class DeskAi extends StatelessWidget {
  const DeskAi({super.key, required this.label, this.icon, this.onTap, this.disabled = false});

  final String label;
  final String? icon;
  final VoidCallback? onTap;
  final bool disabled;

  @override
  Widget build(BuildContext context) {
    final box = Container(
      height: 46,
      margin: const EdgeInsets.only(top: 14),
      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(14)),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          if (icon != null) ...<Widget>[LucideIcon(icon!, size: 16, color: AppColors.forest), const SizedBox(width: 8)],
          Tx(label, style: ts(15, w: 700, lh: 22.5, c: AppColors.forest)),
        ],
      ),
    );
    return Tap(onTap: disabled ? null : onTap, child: box);
  }
}

/// Small hint line inside the desk.
Widget deskHint(String t) => Padding(
  padding: const EdgeInsets.only(top: 12),
  child: Tx(t, style: ts(14, lh: 22.5, c: kDeskSoft)),
);

/// AI summary box inside the desk.
Widget deskBrief(String title, String text) => Container(
  margin: const EdgeInsets.only(top: 14),
  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
  decoration: BoxDecoration(color: const Color(0x18EEF2EE), borderRadius: BorderRadius.circular(16)),
  child: Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: <Widget>[
      Tx(title, style: ts(12, w: 700, lh: 18, c: kDeskMint)),
      const SizedBox(height: 6),
      Tx(text, style: ts(15, lh: 25.5, c: AppColors.white)),
    ],
  ),
);

/// Section title + the "add" toggle button.
class DeskToolbar extends StatelessWidget {
  const DeskToolbar({super.key, required this.title, required this.open, required this.addLabel, required this.onToggle});

  final String title;
  final bool open;
  final String addLabel;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: <Widget>[
        Tx(title, style: ts(16, w: 700, lh: 24, c: AppColors.forest)),
        Tap(
          onTap: onToggle,
          child: Container(
            height: 36,
            padding: const EdgeInsets.symmetric(horizontal: 14),
            decoration: BoxDecoration(color: open ? AppColors.mist : AppColors.forest, borderRadius: BorderRadius.circular(999)),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                LucideIcon(Lu.plus, size: 16, color: open ? AppColors.forest : AppColors.white),
                const SizedBox(width: 6),
                Tx(open ? 'إغلاق' : addLabel, style: ts(14, w: 700, lh: 21, c: open ? AppColors.forest : AppColors.white)),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class StripItem {
  const StripItem({required this.label, required this.on, required this.onTap, this.count});

  final String label;
  final bool on;
  final VoidCallback onTap;

  /// Optional count above the label.
  final String? count;
}

/// Segmented filter strip.
class OwnStrip extends StatelessWidget {
  const OwnStrip({super.key, required this.items, this.height = 40});

  final List<StripItem> items;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(14)),
      child: Row(
        children: <Widget>[
          for (var i = 0; i < items.length; i++) ...<Widget>[
            if (i > 0) const SizedBox(width: 4),
            Expanded(
              child: Tap(
                onTap: items[i].onTap,
                child: Container(
                  height: height,
                  padding: const EdgeInsets.symmetric(horizontal: 2),
                  decoration: BoxDecoration(color: items[i].on ? AppColors.forest : const Color(0x00000000), borderRadius: BorderRadius.circular(10)),
                  alignment: Alignment.center,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (items[i].count != null) ...<Widget>[
                        Tx(items[i].count!, style: ts(16, w: 700, lh: 16, c: items[i].on ? AppColors.white : AppColors.forest)),
                        const SizedBox(height: 3),
                      ],
                      Tx(
                        items[i].label,
                        style: ts(13, w: 600, lh: 15.5, c: items[i].on ? AppColors.white : AppColors.sage),
                        maxLines: 1,
                        softWrap: false,
                        overflow: TextOverflow.ellipsis,
                        align: TextAlign.center,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Lane title with a hint and a count.
class LaneHead extends StatelessWidget {
  const LaneHead({super.key, required this.title, required this.hint, required this.count});

  final String title;
  final String hint;
  final String count;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 8, 4, 0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.baseline,
        textBaseline: TextBaseline.alphabetic,
        children: <Widget>[
          Tx(title, style: ts(15, w: 700, lh: 22.5, c: AppColors.forest)),
          const SizedBox(width: 8),
          Tx(hint, style: ts(14, lh: 21, c: AppColors.muted)),
          const Spacer(),
          const SizedBox(width: 8),
          Tx(count, style: ts(14, w: 700, lh: 21, c: AppColors.muted)),
        ],
      ),
    );
  }
}

/// White search field with a magnifier.
class FindBox extends StatelessWidget {
  const FindBox({super.key, required this.controller, this.placeholder = 'ابحث بالاسم…'});

  final TextEditingController controller;
  final String placeholder;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 44,
      padding: const EdgeInsets.symmetric(horizontal: 14),
      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(14)),
      foregroundDecoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.mistLine, width: 1.4),
      ),
      child: Row(
        children: <Widget>[
          const LucideIcon(Lu.search, size: 16, color: AppColors.sage),
          const SizedBox(width: 8),
          Expanded(
            child: FieldBox(
              controller: controller,
              placeholder: placeholder,
              style: ts(15, w: 600, lh: 22.5, c: AppColors.forest),
              placeholderColor: const Color(0xFF8A9690),
              placeholderWeight: 500,
              height: null,
              padding: EdgeInsets.zero,
            ),
          ),
        ],
      ),
    );
  }
}

/// Ring with a big count and a title beside it.
class DeskJewel extends StatelessWidget {
  const DeskJewel({
    super.key,
    required this.value,
    required this.small,
    required this.title,
    this.frac = 1,
    this.ringColor = kDeskMint,
    this.valueStyle,
  });

  final String value;
  final String small;
  final String title;

  /// Portion of the ring drawn (1 → full circle).
  final double frac;
  final Color ringColor;

  /// Smaller count text (matching desk).
  final TextStyle? valueStyle;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        SizedBox(
          width: 78,
          height: 78,
          child: Stack(
            children: <Widget>[
              Positioned.fill(child: CustomPaint(painter: _JewelRing(frac, ringColor))),
              Positioned(
                left: 0,
                right: 0,
                top: 14,
                bottom: 22,
                child: Center(
                  child: Tx(value, style: valueStyle ?? ts(28, w: 700, lh: 28, c: AppColors.white)),
                ),
              ),
              Positioned(
                left: 0,
                right: 0,
                bottom: 15,
                child: Tx(
                  small,
                  style: ts(11, w: 700, lh: 16.5, c: kDeskMint),
                  align: TextAlign.center,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 14),
        Flexible(
          child: Tx(title, style: ts(16, w: 700, lh: 19, c: AppColors.white)),
        ),
      ],
    );
  }
}

class _JewelRing extends CustomPainter {
  _JewelRing(this.frac, this.color);

  final double frac;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    // viewBox 72 → 78px; r=30, stroke 3.2 (scaled)
    final s = size.width / 72;
    final c = size.center(Offset.zero);
    final r = 30 * s;
    canvas.drawCircle(
      c,
      r,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3.2 * s
        ..color = const Color(0x22FFFFFF),
    );
    if (frac > 0) {
      canvas.drawArc(
        Rect.fromCircle(center: c, radius: r),
        -math.pi / 2,
        2 * math.pi * frac.clamp(0.0, 1.0),
        false,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3.2 * s
          ..strokeCap = StrokeCap.round
          ..color = color,
      );
    }
  }

  @override
  bool shouldRepaint(_JewelRing old) => old.frac != frac || old.color != color;
}

/// A filter tile with a centred drop-down.
class MeterSelect extends StatelessWidget {
  const MeterSelect({super.key, required this.value, required this.options, required this.onChanged, required this.color, this.enabled = true});

  final String value;
  final List<Opt> options;
  final ValueChanged<String> onChanged;
  final Color color;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    var label = '';
    for (final o in options) {
      if (o.value == value) label = o.label;
    }
    return Tap(
      onTap: !enabled
          ? null
          : () async {
              FocusScope.of(context).unfocus();
              final v = await showSelectPicker(context, options, value);
              if (v != null && v != value) onChanged(v);
            },
      child: SizedBox(
        height: 26,
        child: Center(
          child: Tx(
            label,
            style: ts(16, w: 700, lh: 24, c: color),
            maxLines: 1,
            softWrap: false,
            overflow: TextOverflow.clip,
          ),
        ),
      ),
    );
  }
}

/// Three filter tiles (city / neighbourhood / type).
class CrmMeters extends StatelessWidget {
  const CrmMeters({super.key, required this.tiles, this.top = 16});

  /// (select widget, label) per tile, ordered ok / hold / hot.
  final List<(Widget, String)> tiles;
  final double top;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(top: top),
      child: Row(
        children: <Widget>[
          for (var i = 0; i < tiles.length; i++) ...<Widget>[
            if (i > 0) const SizedBox(width: 8),
            Expanded(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
                decoration: BoxDecoration(color: const Color(0x14EEF2EE), borderRadius: BorderRadius.circular(14)),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    tiles[i].$1,
                    const SizedBox(height: 4),
                    Tx(
                      tiles[i].$2,
                      style: ts(12, w: 600, lh: 18, c: const Color(0x99F4F6F5)),
                      align: TextAlign.center,
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
