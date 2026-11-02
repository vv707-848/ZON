// Plain tap target used for every custom button.

import 'package:flutter/widgets.dart';

/// Makes [child] tappable without a Material ripple (the app draws its own
/// pressed/selected states). The whole rectangle is tappable, not only the
/// painted pixels.
class Tap extends StatelessWidget {
  const Tap({super.key, required this.child, this.onTap, this.onTapDown});

  final Widget child;
  final VoidCallback? onTap;
  final GestureTapDownCallback? onTapDown;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(behavior: HitTestBehavior.opaque, onTap: onTap, onTapDown: onTapDown, child: child);
  }
}
