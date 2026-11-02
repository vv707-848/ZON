// Filter + sort control above a project's units, drawn as ONE framed bar
// divided into segments:
//
//   ╭──────────┬──────────┬──────────┬─────────────╮
//   │   الكل   │  المتاح  │ تم البيع │ أقل سعر  ⌄ │
//   ╰──────────┴──────────┴──────────┴─────────────╯
//
// The selected filter is a green pill inside the frame; the last segment opens
// the sort menu.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/shared/widgets/lens.dart';

/// Unit filters: key → label.
const Map<String, String> kUnitFilters = <String, String>{'all': 'الكل', 'available': 'المتاح', 'sold': 'تم البيع'};

/// Unit sort orders: key → label.
const Map<String, String> kUnitSorts = <String, String>{
  'price-asc': 'أقل سعر',
  'price-desc': 'أعلى سعر',
  'area-desc': 'أعلى مساحة',
  'area-asc': 'أقل مساحة',
};

class UnitsFilterBar extends StatelessWidget {
  const UnitsFilterBar({
    super.key,
    required this.filter,
    required this.onFilter,
    required this.sort,
    required this.onSort,
    required this.sortOpen,
    required this.onToggleSort,
  });

  /// Selected key of [kUnitFilters].
  final String filter;
  final ValueChanged<String> onFilter;

  /// Selected key of [kUnitSorts].
  final String sort;
  final ValueChanged<String> onSort;

  /// Whether the sort menu is open.
  final bool sortOpen;
  final VoidCallback onToggleSort;

  static const double _height = 46;
  static const double _inset = 3;

  @override
  Widget build(BuildContext context) {
    final keys = kUnitFilters.keys.toList();
    final selected = keys.indexOf(filter);
    // A divider is drawn before segment i unless it touches the selected pill.
    bool divider(int i) => i != selected && i - 1 != selected;

    return Container(
      height: _height,
      padding: const EdgeInsets.all(_inset),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.line),
      ),
      child: Row(
        children: <Widget>[
          for (var i = 0; i < keys.length; i++) ...<Widget>[
            if (i > 0) _Divider(visible: divider(i)),
            Expanded(
              flex: 4,
              child: _Segment(label: kUnitFilters[keys[i]]!, on: i == selected, onTap: () => onFilter(keys[i])),
            ),
          ],
          _Divider(visible: selected != keys.length - 1),
          Expanded(
            flex: 6,
            child: PickMenuAnchor(
              open: sortOpen,
              compact: true,
              items: <PickItem>[for (final e in kUnitSorts.entries) PickItem(label: e.value, on: sort == e.key, onTap: () => onSort(e.key))],
              child: Tap(
                onTap: onToggleSort,
                child: Container(
                  decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(11)),
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      Flexible(
                        child: Tx(kUnitSorts[sort] ?? '', style: ts(14, w: 600, lh: 20, c: AppColors.forest), maxLines: 1, softWrap: false),
                      ),
                      const SizedBox(width: 4),
                      AnimatedRotation(
                        turns: sortOpen ? 0.5 : 0,
                        duration: const Duration(milliseconds: 150),
                        child: const LucideIcon(Lu.chevronDown, size: 15, strokeWidth: 2.2, color: AppColors.forest),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Segment extends StatelessWidget {
  const _Segment({required this.label, required this.on, required this.onTap});

  final String label;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: on,
      child: Tap(
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          decoration: BoxDecoration(color: on ? AppColors.forest : const Color(0x00000000), borderRadius: BorderRadius.circular(11)),
          alignment: Alignment.center,
          child: Tx(
            label,
            style: ts(14, w: on ? 600 : 500, lh: 20, c: on ? AppColors.white : AppColors.deep),
            maxLines: 1,
            softWrap: false,
          ),
        ),
      ),
    );
  }
}

/// Thin vertical line between two segments.
class _Divider extends StatelessWidget {
  const _Divider({required this.visible});

  final bool visible;

  @override
  Widget build(BuildContext context) {
    return Container(width: 1, height: 20, color: visible ? AppColors.line : const Color(0x00000000));
  }
}
