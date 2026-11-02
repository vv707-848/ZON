// The app's dialogs: one look for every question and notice.
//
// * [showAppDialog]  — a yes/no question ("open the map?", "delete this task?")
// * [showAppNotice]  — a short message with a single OK button
// * [showAppFrame] + [AppDialogCard] — the same frame and card for dialogs
//   with their own state (e.g. the payment confirmation)
//
// All show an icon tile, a title, an optional message and `AppButton`s.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/app_button.dart';

/// Asks a yes/no question and resolves to `true` when the user confirms.
///
/// * [icon] — a Lucide icon name shown in a rounded tile above the title.
/// * [destructive] — paints the icon tile and the confirm button red
///   (for deletions).
Future<bool> showAppDialog(
  BuildContext context, {
  required String icon,
  required String title,
  String? message,
  String confirmLabel = 'متابعة',
  String cancelLabel = 'إلغاء',
  bool destructive = false,
}) async {
  final ok = await showAppFrame<bool>(
    context,
    label: title,
    builder: (ctx) => AppDialogCard(
      icon: icon,
      title: title,
      message: message,
      accent: destructive ? AppColors.danger : AppColors.forest,
      buttons: ButtonPair(
        secondary: AppButton(label: cancelLabel, tone: ButtonTone.tonal, onTap: () => Navigator.of(ctx).pop(false)),
        primary: AppButton(label: confirmLabel, tone: destructive ? ButtonTone.danger : ButtonTone.primary, onTap: () => Navigator.of(ctx).pop(true)),
      ),
    ),
  );
  return ok ?? false;
}

/// Shows a short notice with a single [okLabel] button.
Future<void> showAppNotice(BuildContext context, {required String icon, required String title, String? message, String okLabel = 'حسناً'}) async {
  await showAppFrame<bool>(
    context,
    label: title,
    builder: (ctx) => AppDialogCard(
      icon: icon,
      title: title,
      message: message,
      actions: <Widget>[
        Expanded(
          child: AppButton(label: okLabel, onTap: () => Navigator.of(ctx).pop(true)),
        ),
      ],
    ),
  );
}

/// Opens [builder] in the shared dialog frame (fade + slight zoom, dimmed
/// green backdrop). [dismissible] lets a tap outside close it.
Future<T?> showAppFrame<T>(BuildContext context, {required String label, required WidgetBuilder builder, bool dismissible = true}) {
  return showGeneralDialog<T>(
    context: context,
    barrierDismissible: dismissible,
    barrierLabel: label,
    barrierColor: const Color(0x661C2B24),
    transitionDuration: const Duration(milliseconds: 160),
    transitionBuilder: (ctx, anim, _, child) => FadeTransition(
      opacity: anim,
      child: ScaleTransition(scale: Tween<double>(begin: 0.96, end: 1).animate(anim), child: child),
    ),
    pageBuilder: (ctx, a, b) {
      return SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 320),
              child: Material(
                type: MaterialType.transparency,
                child: DefaultTextStyle(style: baseTextStyle(), child: builder(ctx)),
              ),
            ),
          ),
        ),
      );
    },
  );
}

/// The white card of every dialog: icon tile, title, message, optional
/// [extra], then [buttons] (e.g. a [ButtonPair]) or a row of [actions].
class AppDialogCard extends StatelessWidget {
  const AppDialogCard({
    super.key,
    required this.icon,
    required this.title,
    this.message,
    this.messageColor,
    this.extra,
    this.actions = const <Widget>[],
    this.buttons,
    this.accent = AppColors.forest,
  });

  final String icon;
  final String title;
  final String? message;
  final Color? messageColor;

  /// Shown under the message (e.g. a small label).
  final Widget? extra;
  final List<Widget> actions;

  /// Used instead of [actions] when set (two buttons: a [ButtonPair]).
  final Widget? buttons;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(18, 22, 18, 16),
      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(22), boxShadow: AppShadows.modal),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Center(
            child: Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(color: accent, borderRadius: BorderRadius.circular(14)),
              alignment: Alignment.center,
              child: LucideIcon(icon, size: 22, strokeWidth: 1.8, color: AppColors.white),
            ),
          ),
          const SizedBox(height: 12),
          Tx(
            title,
            style: ts(16, w: 600, lh: 24, c: AppColors.deep),
            align: TextAlign.center,
          ),
          if (message != null) ...<Widget>[
            const SizedBox(height: 6),
            Tx(
              message!,
              style: ts(14, lh: 21, c: messageColor ?? AppColors.muted),
              align: TextAlign.center,
            ),
          ],
          if (extra != null) ...<Widget>[const SizedBox(height: 12), extra!],
          const SizedBox(height: 16),
          buttons ?? Row(children: actions),
        ],
      ),
    );
  }
}
