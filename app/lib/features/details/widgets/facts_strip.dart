// Facts strip (purpose · city · neighbourhood) of the listing page.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';

/// Strip of up to four facts (icon + label over a value).
class FactsStrip extends StatelessWidget {
  const FactsStrip({super.key, required this.items});

  final List<MapEntry<String, String>> items;

  @override
  Widget build(BuildContext context) {
    final t = items.take(4).toList();
    return Container(
      decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(22)),
      clipBehavior: Clip.antiAlias,
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var n = 0; n < t.length; n++)
              Expanded(
                child: Container(
                  constraints: const BoxConstraints(minHeight: 76),
                  decoration: n == 0
                      ? null
                      : const BoxDecoration(
                          border: BorderDirectional(start: BorderSide(color: Color(0xFFD8DFD8))),
                        ),
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[
                          LucideIcon(
                            (t[n].key.contains('حي') || t[n].key.contains('مدينة'))
                                ? Lu.mapPin
                                : (t[n].key.contains('نوع') || t[n].key.contains('عرض'))
                                ? Lu.building2
                                : Lu.tag,
                            size: 14,
                            strokeWidth: 2,
                            color: const Color(0xFF7A807A),
                          ),
                          const SizedBox(width: 4),
                          Tx(t[n].key, style: ts(13, w: 600, lh: 19.5, c: n == 0 ? AppColors.ink : AppColors.sage)),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Tx(
                        t[n].value,
                        style: ts(16, w: 700, lh: 24, c: n == 0 ? AppColors.ink : AppColors.forest),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        softWrap: false,
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
