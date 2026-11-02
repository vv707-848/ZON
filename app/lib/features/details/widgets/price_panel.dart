// Dark green price panel (price · negotiable / unit type · status).

import 'package:flutter/material.dart';

import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// Price amount aligned on the text baseline (riyal sign sits on the baseline).
class BaselineSar extends StatelessWidget {
  const BaselineSar({
    super.key,
    required this.text,
    required this.style,
    this.iconW = 20,
    this.iconH = 22,
    this.gap = 6,
    this.iconColor = AppColors.white,
  });

  final String text;
  final TextStyle style;
  final double iconW;
  final double iconH;
  final double gap;
  final Color iconColor;

  @override
  Widget build(BuildContext context) {
    final eff = DefaultTextStyle.of(context).style.merge(style);
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Text.rich(
        TextSpan(
          children: <InlineSpan>[
            WidgetSpan(
              alignment: PlaceholderAlignment.aboveBaseline,
              baseline: TextBaseline.alphabetic,
              child: RiyalIcon(width: iconW, height: iconH, color: iconColor),
            ),
            WidgetSpan(child: SizedBox(width: gap)),
            TextSpan(text: text),
          ],
        ),
        style: style,
        textAlign: TextAlign.center,
        // Not forced: a riyal sign taller than the
        // text ascent grows the line.
        strutStyle: StrutStyle(
          fontFamily: eff.fontFamily,
          fontFamilyFallback: eff.fontFamilyFallback,
          fontSize: eff.fontSize,
          height: eff.height,
          leadingDistribution: TextLeadingDistribution.even,
        ),
      ),
    );
  }
}

class DuoCell {
  const DuoCell(this.label, this.value, {this.sar = false, this.valueSize = 16, this.valueWeight = 600, this.flex = 1});

  final String label;
  final String value;
  final bool sar;
  final double valueSize;
  final int valueWeight;
  final int flex;
}

/// Dark green price panel with two or three cells.
class PriceDuo extends StatelessWidget {
  const PriceDuo({super.key, required this.cells, this.trio = false});

  final List<DuoCell> cells;
  final bool trio;

  @override
  Widget build(BuildContext context) {
    const labelColor = Color(0xFFC9D4CC);
    return Container(
      padding: trio ? const EdgeInsets.symmetric(vertical: 12, horizontal: 6) : const EdgeInsets.symmetric(vertical: 16, horizontal: 8),
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(22)),
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var n = 0; n < cells.length; n++)
              Expanded(
                flex: cells[n].flex,
                child: Container(
                  decoration: n < cells.length - 1
                      ? const BoxDecoration(
                          border: BorderDirectional(end: BorderSide(color: Color(0x28FFFFFF))),
                        )
                      : null,
                  padding: EdgeInsets.symmetric(horizontal: trio ? 6 : 10),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      Tx(
                        cells[n].label,
                        style: ts(13, w: 500, lh: 19.5, c: labelColor),
                        align: TextAlign.center,
                      ),
                      SizedBox(height: cells[n].sar ? 14 : 6),
                      if (cells[n].sar)
                        BaselineSar(
                          text: cells[n].value,
                          style: ts(cells[n].valueSize, w: 700, lh: cells[n].valueSize * 1.5, c: AppColors.white),
                        )
                      else
                        Tx(
                          cells[n].value,
                          style: ts(cells[n].valueSize, w: cells[n].valueWeight, lh: 24, c: AppColors.white),
                          align: TextAlign.center,
                        ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
