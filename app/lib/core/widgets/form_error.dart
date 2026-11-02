// A form's validation message (red, with an alert icon).

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';

/// Shows why a form cannot be saved yet, e.g. "اكتب وصف الطلب".
class FormError extends StatelessWidget {
  const FormError(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      liveRegion: true,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(color: const Color(0xFFFBEAEA), borderRadius: BorderRadius.circular(14)),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: LucideIcon(Lu.circleAlert, size: 16, strokeWidth: 2, color: AppColors.danger),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Tx(text, style: ts(14, w: 600, lh: 21, c: AppColors.danger)),
            ),
          ],
        ),
      ),
    );
  }
}
