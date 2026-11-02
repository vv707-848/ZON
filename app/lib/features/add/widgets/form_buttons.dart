// Round buttons and toggles of the add / edit forms.
//
// Text buttons ("متابعة", "نشر الطلب"…) use the app-wide `AppButton`
// (core/widgets/app_button.dart) — do not add new text buttons here.

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';

/// Round green close button (44 px) at the top of a full-screen step.
class CloseCircle extends StatelessWidget {
  const CloseCircle({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: onTap,
      child: Container(
        width: 44,
        height: 44,
        decoration: const BoxDecoration(color: AppColors.forest, shape: BoxShape.circle),
        alignment: Alignment.center,
        child: const LucideIcon(Lu.x, size: 18, strokeWidth: 2.2, color: AppColors.white),
      ),
    );
  }
}

/// Toggle chip of the forms: icon, label and a check dot (e.g. "بصك").
class AddFlag extends StatelessWidget {
  const AddFlag({super.key, required this.on, required this.onToggle, required this.label, this.icon});

  final bool on;
  final VoidCallback onToggle;
  final String label;
  final String? icon;

  @override
  Widget build(BuildContext context) {
    final fg = on ? AppColors.white : AppColors.deep;
    return Tap(
      onTap: onToggle,
      child: Container(
        height: 48,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        decoration: BoxDecoration(
          color: on ? AppColors.forest : kAddBox,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: on ? AppColors.forest : kAddBoxLine),
        ),
        child: Row(
          children: <Widget>[
            SizedBox(
              width: 24,
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: icon == null ? const SizedBox.shrink() : LucideIcon(icon!, size: 18, strokeWidth: 1.75, color: fg),
              ),
            ),
            const SizedBox(width: 6),
            Expanded(
              child: Tx(
                label,
                style: ts(15, w: 600, lh: 22, c: fg),
                align: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            const SizedBox(width: 6),
            Container(
              width: 24,
              height: 24,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: on ? AppColors.white : null,
                border: on ? null : Border.all(color: AppColors.deep, width: 1.5),
              ),
              alignment: Alignment.center,
              child: on ? const LucideIcon(Lu.check, size: 12, strokeWidth: 3, color: AppColors.forest) : null,
            ),
          ],
        ),
      ),
    );
  }
}
