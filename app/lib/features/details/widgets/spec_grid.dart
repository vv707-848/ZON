// Specification grid (two columns of icon + label + value cells).

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

String specIcon(String label) {
  if (label.contains('مساحة') || label.contains('الأرض') || label.contains('البناء')) return Lu.maximize2;
  if (label.contains('غرف')) return Lu.bedDouble;
  if (label.contains('دورات') || label.contains('حمام')) return Lu.bath;
  if (label.contains('صالات')) return Lu.sofa;
  if (label.contains('سعر') || label.contains('دخل')) return Lu.wallet;
  if (label.contains('طابق') || label.contains('دور')) return Lu.layers;
  if (label.contains('سكن')) return Lu.home;
  if (label.contains('صك') || label.contains('بنك')) return Lu.fileCheck;
  if (label.contains('تفاوض') || label.contains('دفع')) return Lu.handshake;
  if (label.contains('إجمالي') || label.contains('طوابق') || label.contains('عمارة') || label.contains('مشروع') || label.contains('نوع العقار')) {
    return Lu.building2;
  }
  if (label.contains('عمر')) return Lu.calendarDays;
  if (label.contains('مدينة')) return Lu.building2;
  if (label.contains('حي')) return Lu.mapPin;
  if (label.contains('مباع')) return Lu.tag;
  if (label.contains('استخدام') || label.contains('أرض')) return Lu.landmark;
  if (label.contains('غرض')) return Lu.tag;
  if (label.contains('نسبة') || label.contains('حالة')) return Lu.building2;
  return Lu.layers;
}

class SpecItem {
  const SpecItem(this.k, this.v, {this.sar = false});

  final String k;
  final String v;
  final bool sar;
}

/// Specification cells in two columns; an odd last cell spans both.
class SpecGrid extends StatelessWidget {
  const SpecGrid({super.key, required this.items});

  final List<SpecItem> items;

  @override
  Widget build(BuildContext context) {
    final rows = <Widget>[];
    for (var i = 0; i < items.length; i += 2) {
      if (i > 0) rows.add(const SizedBox(height: 8));
      if (i + 1 < items.length) {
        rows.add(
          Row(
            children: <Widget>[
              Expanded(child: _SpecCell(items[i])),
              const SizedBox(width: 8),
              Expanded(child: _SpecCell(items[i + 1])),
            ],
          ),
        );
      } else {
        rows.add(_SpecCell(items[i]));
      }
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: rows);
  }
}

class _SpecCell extends StatelessWidget {
  const _SpecCell(this.item);

  final SpecItem item;

  @override
  Widget build(BuildContext context) {
    final valueStyle = ts(20, w: 700, lh: 20, c: AppColors.deep);
    return Container(
      height: 96,
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
      decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(18)),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              LucideIcon(specIcon(item.k), size: 14, strokeWidth: 2, color: AppColors.forest),
              const SizedBox(width: 5),
              Tx(item.k, style: ts(13, w: 600, lh: 13, c: AppColors.sage), maxLines: 1, softWrap: false),
            ],
          ),
          const SizedBox(height: 8),
          if (item.sar)
            SizedBox(
              height: 20,
              child: Center(
                child: SarText(
                  item.v,
                  style: ts(13, w: 700, lh: 13, c: AppColors.deep),
                  iconW: 10,
                  iconH: 11,
                  gap: 3,
                  iconColor: AppColors.deep,
                ),
              ),
            )
          else
            Tx(item.v, style: valueStyle, maxLines: 1, overflow: TextOverflow.ellipsis, align: TextAlign.center),
        ],
      ),
    );
  }
}
