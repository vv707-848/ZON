// Form fields of the account services (labelled inputs and drop-downs).
//
// Buttons use `AppButton` and dialogs `showAppDialog` (core/widgets).

import 'package:flutter/material.dart';

import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// Box of the service form fields (48 px, rounded, hairline border).
final BoxDecoration kSelDeco = BoxDecoration(
  color: AppColors.surface,
  borderRadius: BorderRadius.circular(16),
  border: Border.all(color: AppColors.line),
);

TextStyle get kSelText => ts(15, lh: 21.5, c: AppColors.ink);

/// A small label above its control.
class SvcField extends StatelessWidget {
  const SvcField({super.key, required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx(label, style: ts(13, w: 600, lh: 20, c: AppColors.muted)),
        const SizedBox(height: 6),
        child,
      ],
    );
  }
}

/// Text input of a service form.
Widget selInput({
  TextEditingController? controller,
  String? placeholder,
  TextDirection? dir,
  TextInputType? keyboard,
  ValueChanged<String>? onChanged,
}) {
  return FieldBox(
    controller: controller,
    placeholder: placeholder,
    style: kSelText,
    height: 48,
    padding: const EdgeInsets.symmetric(horizontal: 16),
    decoration: kSelDeco,
    dir: dir,
    keyboardType: keyboard,
    onChanged: onChanged,
  );
}

/// Drop-down of a service form.
Widget selSelect({required String value, required List<Opt> options, required ValueChanged<String> onChanged, bool enabled = true}) {
  return SelectBox(
    value: value,
    options: options,
    onChanged: onChanged,
    enabled: enabled,
    style: kSelText,
    height: 48,
    padding: const EdgeInsets.symmetric(horizontal: 16),
    decoration: kSelDeco,
  );
}
