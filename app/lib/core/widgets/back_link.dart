// Back navigation controls: the round back button and the "رجوع" link.

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/tap.dart';

/// Round white back button (36 px): a chevron pointing to the start side.
class BackCircle extends StatelessWidget {
  const BackCircle({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: onTap,
      child: Container(
        width: 36,
        height: 36,
        decoration: const BoxDecoration(color: AppColors.white, shape: BoxShape.circle, boxShadow: AppShadows.hairline),
        alignment: Alignment.center,
        child: const LucideIcon(Lu.chevronRight, size: 20, color: AppColors.deep),
      ),
    );
  }
}

/// "رجوع" link at the top of a form step: [BackCircle] and the word.
class BackLink extends StatelessWidget {
  const BackLink({super.key, required this.onTap, this.label = 'رجوع'});

  final VoidCallback onTap;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: Tap(
        onTap: onTap,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            BackCircle(onTap: onTap),
            const SizedBox(width: 8),
            Tx(label, style: ts(15, w: 600, lh: 22, c: AppColors.deep)),
          ],
        ),
      ),
    );
  }
}
