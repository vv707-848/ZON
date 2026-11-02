// The text button of زون — one widget for every call-to-action.
//
// Pick the look with [ButtonTone] (what the button means) and the height with
// [ButtonSize] (how prominent it is). Do not build buttons from raw Containers:
// using this widget keeps every button in the app the same height, radius,
// font and colour.
//
//   AppButton(label: 'متابعة', onTap: next)                          // main action
//   AppButton(label: 'نشر الطلب', size: ButtonSize.large, onTap: …) // page CTA
//   AppButton(label: 'إلغاء', tone: ButtonTone.ghost, onTap: …)
//
// A null `onTap` draws the button faded and ignores taps (disabled).

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/tap.dart';

/// What a button means.
enum ButtonTone {
  /// Filled brand green, white label — the main action of a page or card.
  primary,

  /// Soft green fill, green label — a helpful side action (e.g. AI wording).
  tonal,

  /// White with a hairline ring, green label — the alternative action.
  secondary,

  /// No fill, hairline ring — cancel, skip, sign out.
  ghost,

  /// Filled red — destructive actions (delete the account).
  danger,

  /// Translucent white, white label — a second action on a dark green card.
  glass,
}

/// How tall a button is.
enum ButtonSize {
  /// 48 px — inside cards, dialogs and the service forms.
  regular,

  /// 52 px — the main action at the bottom of a page.
  large,
}

/// A full-width text button (see the file comment for usage).
class AppButton extends StatelessWidget {
  const AppButton({super.key, required this.label, required this.onTap, this.tone = ButtonTone.primary, this.size = ButtonSize.regular, this.icon});

  final String label;

  /// Null → disabled.
  final VoidCallback? onTap;
  final ButtonTone tone;
  final ButtonSize size;

  /// Optional Lucide icon drawn before the label (see `Lu`).
  final String? icon;

  /// Fill, label colour and ring of each tone.
  static (Color fill, Color label, Color? ring) colorsOf(ButtonTone tone) => switch (tone) {
    ButtonTone.primary => (AppColors.forest, AppColors.white, null),
    ButtonTone.tonal => (AppColors.mist, AppColors.forest, AppColors.mistLine),
    ButtonTone.secondary => (AppColors.white, AppColors.forest, AppColors.line),
    ButtonTone.ghost => (const Color(0x00FFFFFF), AppColors.deep, AppColors.line),
    ButtonTone.danger => (AppColors.danger, AppColors.white, null),
    ButtonTone.glass => (const Color(0x1FFFFFFF), AppColors.white, null),
  };

  /// Height of each size.
  static double heightOf(ButtonSize size) => size == ButtonSize.large ? 52 : 48;

  /// Corner radius of each size.
  static double radiusOf(ButtonSize size) => size == ButtonSize.large ? 16 : 14;

  /// Width this button needs to show its whole label on one line (padding,
  /// ring, icon and the phone's text size included).
  double fullWidth(BuildContext context) {
    final painter = TextPainter(
      text: TextSpan(text: label, style: DefaultTextStyle.of(context).style.merge(ts(16, w: 700, lh: 24))),
      textDirection: Directionality.of(context),
      textScaler: MediaQuery.textScalerOf(context),
      maxLines: 1,
    )..layout();
    final width = painter.width + 32 + 2 + (icon == null ? 0 : 26);
    painter.dispose();
    return width;
  }

  @override
  Widget build(BuildContext context) {
    final (fill, fg, ring) = colorsOf(tone);
    final radius = BorderRadius.circular(radiusOf(size));
    final text = Tx(
      label,
      style: ts(16, w: 700, lh: 24, c: fg),
      align: TextAlign.center,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    );
    final body = Container(
      height: heightOf(size),
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: fill,
        borderRadius: radius,
        border: ring == null ? null : Border.all(color: ring),
      ),
      alignment: Alignment.center,
      child: icon == null
          ? text
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                LucideIcon(icon!, size: 18, strokeWidth: 1.9, color: fg),
                const SizedBox(width: 8),
                Flexible(child: text),
              ],
            ),
    );
    return Tap(
      onTap: onTap,
      child: onTap == null ? Opacity(opacity: 0.45, child: body) : body,
    );
  }
}

/// Two buttons side by side when both labels fit, otherwise one per line
/// with [primary] on top: a label is never cut (larger text, long amounts
/// such as «ادفع 2,300 ريال»).
class ButtonPair extends StatelessWidget {
  const ButtonPair({super.key, required this.secondary, required this.primary, this.gap = 8});

  /// The side action, e.g. «إلغاء» (first in the row, under in the column).
  final AppButton secondary;

  /// The main action.
  final AppButton primary;
  final double gap;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, c) {
      final half = (c.maxWidth - gap) / 2;
      if (primary.fullWidth(context) <= half && secondary.fullWidth(context) <= half) {
        return Row(
          children: <Widget>[
            Expanded(child: secondary),
            SizedBox(width: gap),
            Expanded(child: primary),
          ],
        );
      }
      return Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          primary,
          SizedBox(height: gap),
          secondary,
        ],
      );
    },
  );
}
