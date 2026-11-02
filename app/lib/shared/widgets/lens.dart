// The filter card ("lens") of the feeds and the floating pick menu.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

class LensModeButton {
  const LensModeButton({required this.label, this.icon, required this.on, required this.onTap});

  final String label;
  final String? icon;
  final bool on;
  final VoidCallback onTap;
}

class LensMode {
  const LensMode({required this.buttons, this.roles = false});

  final List<LensModeButton> buttons;

  /// Compact buttons (the five role tabs of the requests page).
  final bool roles;
}

class LensField {
  const LensField({required this.label, required this.value, required this.options, required this.onChanged});

  final String label;
  final String value;
  final List<Opt> options;
  final ValueChanged<String> onChanged;
}

class LensSortButton {
  const LensSortButton({required this.label, required this.on, required this.onTap, this.icon});

  final String label;
  final bool on;
  final VoidCallback onTap;
  final String? icon;
}

class PickItem {
  const PickItem({required this.label, required this.on, required this.onTap});

  final String label;
  final bool on;
  final VoidCallback onTap;
}

class LensSort {
  const LensSort({required this.buttons, this.metricOn = false, this.metricOpen = false, this.onMetric, this.menu});

  final List<LensSortButton> buttons;
  final bool metricOn;
  final bool metricOpen;
  final VoidCallback? onMetric;

  /// When non-null a "تصفية" dropdown is shown as the last segment.
  final List<PickItem>? menu;
}

/// The filter card of the home and requests pages: mode buttons, select
/// fields in two columns and the sort bar.
class Lens extends StatelessWidget {
  const Lens({super.key, required this.mode, required this.fields, required this.sort});

  final LensMode mode;
  final List<LensField> fields;
  final LensSort sort;

  @override
  Widget build(BuildContext context) {
    const muted = AppColors.sage;
    final rows = <Widget>[];
    for (var i = 0; i < fields.length; i += 2) {
      if (i > 0) rows.add(const SizedBox(height: 8));
      rows.add(
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Expanded(child: _field(fields[i])),
            const SizedBox(width: 10),
            Expanded(child: i + 1 < fields.length ? _field(fields[i + 1]) : const SizedBox.shrink()),
          ],
        ),
      );
    }
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(26),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Container(
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(16)),
            child: Row(
              children: <Widget>[
                for (final b in mode.buttons)
                  Expanded(
                    child: Tap(
                      onTap: b.onTap,
                      child: Container(
                        height: mode.roles ? 36 : 40,
                        padding: mode.roles ? const EdgeInsets.symmetric(horizontal: 2) : EdgeInsets.zero,
                        decoration: BoxDecoration(color: b.on ? AppColors.forest : const Color(0x00000000), borderRadius: BorderRadius.circular(12)),
                        alignment: Alignment.center,
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: <Widget>[
                            if (b.icon != null) ...<Widget>[
                              LucideIcon(b.icon!, size: 14, strokeWidth: 2.2, color: b.on ? AppColors.white : muted),
                              const SizedBox(width: 6),
                            ],
                            Flexible(
                              child: Tx(
                                b.label,
                                style: mode.roles
                                    ? ts(13, w: 600, lh: 19.5, c: b.on ? AppColors.white : muted)
                                    : ts(14, w: 700, lh: 21, c: b.on ? AppColors.white : muted),
                                maxLines: 1,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          ...rows,
          const SizedBox(height: 12),
          _SortBar(sort: sort),
        ],
      ),
    );
  }

  Widget _field(LensField f) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx(f.label, style: ts(11, w: 700, lh: 16.5, c: AppColors.sage)),
        const SizedBox(height: 4),
        SelectBox(
          value: f.value,
          options: f.options,
          onChanged: f.onChanged,
          height: 40,
          style: ts(14, w: 700, lh: 21, c: AppColors.forest),
          decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(12)),
        ),
      ],
    );
  }
}

class _SortBar extends StatelessWidget {
  const _SortBar({required this.sort});

  final LensSort sort;

  @override
  Widget build(BuildContext context) {
    Widget seg(String label, bool on, VoidCallback onTap, {String? icon, bool metric = false}) {
      final color = on ? AppColors.white : AppColors.sage;
      return Tap(
        onTap: onTap,
        child: Container(
          height: 34,
          padding: metric ? const EdgeInsets.symmetric(horizontal: 2) : EdgeInsets.zero,
          decoration: BoxDecoration(color: on ? AppColors.forest : const Color(0x00000000), borderRadius: BorderRadius.circular(999)),
          alignment: Alignment.center,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              if (icon != null) ...<Widget>[LucideIcon(icon, size: 12, strokeWidth: 2.4, color: color), const SizedBox(width: 4)],
              Flexible(
                child: Tx(label, style: ts(13, w: 600, lh: 13, c: color), maxLines: 1, softWrap: false),
              ),
              if (metric) ...<Widget>[const SizedBox(width: 2), LucideIcon(Lu.chevronDown, size: 11, strokeWidth: 2.2, color: color)],
            ],
          ),
        ),
      );
    }

    return Container(
      height: 40,
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(999)),
      child: Row(
        children: <Widget>[
          for (final b in sort.buttons) Expanded(child: seg(b.label, b.on, b.onTap, icon: b.icon)),
          if (sort.menu != null)
            Expanded(
              child: PickMenuAnchor(
                open: sort.metricOpen,
                items: sort.menu!,
                child: seg('تصفية', sort.metricOn, sort.onMetric ?? () {}, metric: true),
              ),
            ),
        ],
      ),
    );
  }
}

/// Shows a [PickMenu] under [child] (8 px below, aligned to its left edge)
/// while [open] is true.
class PickMenuAnchor extends StatefulWidget {
  const PickMenuAnchor({super.key, required this.open, required this.items, required this.child, this.width = 196, this.compact = false});

  final bool open;
  final List<PickItem> items;
  final Widget child;
  final double width;

  /// Tighter menu without dividers.
  final bool compact;

  @override
  State<PickMenuAnchor> createState() => _PickMenuAnchorState();
}

class _PickMenuAnchorState extends State<PickMenuAnchor> {
  final OverlayPortalController _ctl = OverlayPortalController();
  final LayerLink _link = LayerLink();

  @override
  void initState() {
    super.initState();
    if (widget.open) _sync();
  }

  @override
  void didUpdateWidget(PickMenuAnchor old) {
    super.didUpdateWidget(old);
    if (widget.open != old.open) _sync();
  }

  /// Shows/hides the menu after the current frame (an overlay may not be
  /// changed while the widget tree is being built).
  void _sync() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (widget.open) {
        _ctl.show();
      } else {
        _ctl.hide();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final rtl = Directionality.of(context) == TextDirection.rtl;
    return CompositedTransformTarget(
      link: _link,
      child: OverlayPortal(
        controller: _ctl,
        overlayChildBuilder: (ctx) {
          return CompositedTransformFollower(
            link: _link,
            targetAnchor: rtl ? Alignment.bottomLeft : Alignment.bottomRight,
            followerAnchor: rtl ? Alignment.topLeft : Alignment.topRight,
            offset: const Offset(0, 8),
            child: Align(
              alignment: rtl ? Alignment.topLeft : Alignment.topRight,
              child: Directionality(
                textDirection: Directionality.of(context),
                child: DefaultTextStyle(
                  style: DefaultTextStyle.of(context).style,
                  child: PickMenu(items: widget.items, width: widget.width, compact: widget.compact),
                ),
              ),
            ),
          );
        },
        child: widget.child,
      ),
    );
  }
}

/// A floating list of choices with a check on the selected one.
class PickMenu extends StatelessWidget {
  const PickMenu({super.key, required this.items, this.width = 196, this.compact = false});

  final List<PickItem> items;
  final double width;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(18), boxShadow: AppShadows.raised),
      clipBehavior: Clip.antiAlias,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          for (var i = 0; i < items.length; i++)
            Tap(
              onTap: items[i].onTap,
              child: Container(
                height: 44,
                padding: EdgeInsets.symmetric(horizontal: compact ? 4 : 16),
                decoration: BoxDecoration(
                  color: AppColors.white,
                  border: (!compact && i < items.length - 1) ? const Border(bottom: BorderSide(color: Color(0xFFECEFED))) : null,
                ),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Tx(
                        items[i].label,
                        style: compact
                            ? ts(13, w: 600, lh: 19.5, c: items[i].on ? AppColors.forest : AppColors.deep)
                            : ts(15, w: 600, lh: 22.5, c: items[i].on ? AppColors.forest : AppColors.deep),
                      ),
                    ),
                    const SizedBox(width: 10),
                    SizedBox(
                      width: 16,
                      height: 16,
                      child: items[i].on
                          ? Center(
                              child: LucideIcon(Lu.check, size: compact ? 14 : 16, strokeWidth: 2.4, color: AppColors.forest),
                            )
                          : null,
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}
