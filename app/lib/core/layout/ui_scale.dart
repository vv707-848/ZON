// Proportional sizing across phone sizes.
//
// Every screen is designed on a 390-pt wide phone (iPhone 12–16). Instead of
// letting small phones look cramped and large phones look empty, the whole UI
// is scaled by `screen width / 390`, so the composition — sizes, spacing,
// line breaks — is the same on every phone. The factor is clamped so that
// tablets and very small phones stay comfortable:
//
//   360 pt  (small Android) → 0.92 ×
//   375 pt  (iPhone SE/mini) → 0.96 ×
//   390 pt  (design width)  → 1.00 ×
//   412 pt  (most Android)  → 1.06 ×
//   430 pt  (Pro Max)       → 1.10 ×
//   tablets                → 1.10 × (content width is capped by AppShell)
//
// Widgets inside see a smaller "logical" screen (size / scale) and draw at the
// normal design sizes; [FittedBox] then scales the result. Hit testing, text
// input and accessibility all go through the same transform.

import 'package:flutter/widgets.dart';

/// Scales [child] by `screen width / 390` (see the file comment).
class UiScale extends StatelessWidget {
  const UiScale({super.key, required this.child});

  /// Width the screens were designed for.
  static const double designWidth = 390;

  /// Limits of the scale factor.
  static const double minScale = 0.92;
  static const double maxScale = 1.10;

  final Widget child;

  /// The factor used for a screen of [width] logical pixels.
  static double factorFor(double width) => (width / designWidth).clamp(minScale, maxScale);

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    if (mq.size.isEmpty) return child;
    final scale = factorFor(mq.size.width);
    if ((scale - 1).abs() < 0.005) return child;
    final size = mq.size / scale;
    return MediaQuery(
      data: mq.copyWith(
        size: size,
        devicePixelRatio: mq.devicePixelRatio * scale,
        padding: mq.padding / scale,
        viewPadding: mq.viewPadding / scale,
        viewInsets: mq.viewInsets / scale,
        systemGestureInsets: mq.systemGestureInsets / scale,
      ),
      child: FittedBox(
        fit: BoxFit.fill,
        alignment: Alignment.topLeft,
        child: SizedBox(width: size.width, height: size.height, child: child),
      ),
    );
  }
}
