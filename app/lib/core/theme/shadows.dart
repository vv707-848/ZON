// Elevation of زون — three soft levels, nothing else.
//
// * [AppShadows.card]   cards resting on the page (listing cards, list rows)
// * [AppShadows.raised] floating controls (tab bar, pills over photos, menus)
// * [AppShadows.modal]  dialogs and bottom sheets
//
// Flat blocks inside a card use a fill colour or a hairline border instead of
// a shadow — stacking shadows is what made the old screens look "heavy".

import 'package:flutter/painting.dart';

abstract final class AppShadows {
  /// Cards on the page background.
  static const List<BoxShadow> card = <BoxShadow>[
    BoxShadow(color: Color(0x0D10201A), blurRadius: 2, offset: Offset(0, 1)),
    BoxShadow(color: Color(0x0F10201A), blurRadius: 16, offset: Offset(0, 6)),
  ];

  /// Floating controls.
  static const List<BoxShadow> raised = <BoxShadow>[
    BoxShadow(color: Color(0x0F10201A), blurRadius: 3, offset: Offset(0, 1)),
    BoxShadow(color: Color(0x1A10201A), blurRadius: 24, offset: Offset(0, 10)),
  ];

  /// Dialogs, sheets and pop-ups.
  static const List<BoxShadow> modal = <BoxShadow>[BoxShadow(color: Color(0x2910201A), blurRadius: 40, offset: Offset(0, 18))];

  /// A 1 px ring that reads like a border but does not change layout size.
  static const List<BoxShadow> hairline = <BoxShadow>[BoxShadow(color: Color(0x14121214), spreadRadius: 1)];
}

/// A shadow given as (dx, dy, blur, spread), where blur is the visible
/// blur size (twice the Gaussian sigma).
///
/// Prefer the [AppShadows] tokens; this helper exists for the few decorative
/// glows (e.g. the AI cards) that need a specific colour.
BoxShadow decorShadow(Color color, double dx, double dy, [double blur = 0, double spread = 0]) {
  final r = blur <= 0 ? 0.0 : ((blur / 2 - 0.5) / 0.57735).clamp(0.0, double.infinity);
  return BoxShadow(color: color, offset: Offset(dx, dy), blurRadius: r.toDouble(), spreadRadius: spread);
}
