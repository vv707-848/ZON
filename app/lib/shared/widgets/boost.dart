// The "urgent" toggle of a request and the «عاجل» badge of a listing. The
// confirmation and payment of boosts live in billing_flow.dart.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// "طلب عاجل" toggle: gold when on. With [locked] it only shows the state
/// (an urgent request stays urgent).
class UrgentToggle extends StatelessWidget {
  const UrgentToggle({super.key, required this.on, required this.onTap, this.label = 'طلب عاجل', this.locked = false});

  final bool on;
  final VoidCallback onTap;
  final String label;
  final bool locked;

  @override
  Widget build(BuildContext context) {
    final box = Container(
      height: 44,
      decoration: BoxDecoration(
        color: on ? null : AppColors.tint,
        gradient: on
            ? const LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: <Color>[Color(0xFFF6E7B4), Color(0xFFE2C56A)])
            : null,
        borderRadius: BorderRadius.circular(14),
      ),
      alignment: Alignment.center,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          LucideIcon(Lu.zap, size: 16, strokeWidth: 2, color: on ? const Color(0xFF4A3808) : AppColors.muted),
          const SizedBox(width: 6),
          Tx(label, style: ts(15, w: 600, lh: 22.5, c: on ? const Color(0xFF4A3808) : AppColors.deep)),
        ],
      ),
    );
    return Semantics(
      button: !locked,
      toggled: on,
      child: locked ? box : Tap(onTap: onTap, child: box),
    );
  }
}

/// Red of the «عاجل» badges (requests and listings).
const Color kUrgentRed = Color(0xFFC62828);

/// Red «عاجل» badge of an urgent listing (same look as urgent requests).
class UrgentBadge extends StatelessWidget {
  const UrgentBadge({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 26,
      padding: const EdgeInsets.symmetric(horizontal: 10),
      decoration: BoxDecoration(color: kUrgentRed, borderRadius: BorderRadius.circular(999)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const LucideIcon(Lu.zap, size: 12, strokeWidth: 2.2, color: AppColors.white),
          const SizedBox(width: 4),
          Tx('عاجل', style: ts(12, w: 700, lh: 18, c: AppColors.white)),
        ],
      ),
    );
  }
}
