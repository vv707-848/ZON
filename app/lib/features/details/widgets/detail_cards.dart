// Section headings and the description card of the detail pages.

import 'package:flutter/material.dart';

import 'package:zon/core/theme/theme.dart';

/// Section heading with a short green bar before it.
class BarHeading extends StatelessWidget {
  const BarHeading(
    this.text, {
    super.key,
    required this.size,
    required this.weight,
    required this.lh,
    this.barWidth = 14,
    this.barColor = AppColors.success,
  });

  final String text;
  final double size;
  final int weight;
  final double lh;
  final double barWidth;
  final Color barColor;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Container(
          width: barWidth,
          height: 2,
          decoration: BoxDecoration(color: barColor, borderRadius: BorderRadius.circular(2)),
        ),
        const SizedBox(width: 8),
        Flexible(
          child: Tx(
            text,
            style: ts(size, w: weight, lh: lh, c: AppColors.deep),
          ),
        ),
      ],
    );
  }
}

/// Description card (tinted block with a heading).
class DescCard extends StatelessWidget {
  const DescCard({super.key, required this.title, required this.text});

  final String title;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: AppColors.offerPaper, borderRadius: BorderRadius.circular(22)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          BarHeading(title, size: 15, weight: 700, lh: 22.5),
          const SizedBox(height: 8 + 8),
          Tx(text, style: ts(16, w: 500, lh: 27, c: AppColors.deep)),
        ],
      ),
    );
  }
}

/// Heading above the specification grid.
class InfoHeading extends StatelessWidget {
  const InfoHeading(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(padding: const EdgeInsets.fromLTRB(4, 4, 4, 0), child: BarHeading(text, size: 18, weight: 700, lh: 27));
  }
}
