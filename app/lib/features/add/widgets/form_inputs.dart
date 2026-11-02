// Inputs of the add / edit forms.
//
// Two families, matching the two looks of the forms:
// * Proj… — label row above a boxed input (the long listing / project forms);
// * Ios…  — a soft card with an icon tile, the label and the value inside
//           (the short steps: owner info, request form).

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';

// ------------------------------------------------------------------ controlled field

/// A text field whose text always follows [value] (a controlled input): when
/// the parent rewrites the value, the field shows the new text.
class CtlField extends StatefulWidget {
  const CtlField({
    super.key,
    required this.value,
    required this.onChanged,
    required this.style,
    this.placeholder,
    this.placeholderColor,
    this.placeholderWeight,
    this.height = 48,
    this.padding = const EdgeInsets.symmetric(horizontal: 14),
    this.decoration,
    this.dir,
    this.textAlign,
    this.multiline = false,
    this.keyboardType,
    this.maxLength,
  });

  final String value;
  final ValueChanged<String> onChanged;
  final TextStyle style;
  final String? placeholder;
  final Color? placeholderColor;
  final int? placeholderWeight;
  final double? height;
  final EdgeInsets padding;
  final BoxDecoration? decoration;
  final TextDirection? dir;
  final TextAlign? textAlign;
  final bool multiline;
  final TextInputType? keyboardType;
  final int? maxLength;

  @override
  State<CtlField> createState() => _CtlFieldState();
}

class _CtlFieldState extends State<CtlField> {
  late final TextEditingController _c = TextEditingController(text: widget.value);

  @override
  void didUpdateWidget(CtlField old) {
    super.didUpdateWidget(old);
    if (widget.value != _c.text) {
      _c.value = TextEditingValue(
        text: widget.value,
        selection: TextSelection.collapsed(offset: widget.value.length),
      );
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FieldBox(
      controller: _c,
      placeholder: widget.placeholder,
      style: widget.style,
      placeholderColor: widget.placeholderColor,
      placeholderWeight: widget.placeholderWeight,
      height: widget.height,
      padding: widget.padding,
      decoration: widget.decoration,
      dir: widget.dir,
      textAlign: widget.textAlign,
      multiline: widget.multiline,
      keyboardType: widget.keyboardType,
      inputFormatters: widget.maxLength == null ? null : <TextInputFormatter>[LengthLimitingTextInputFormatter(widget.maxLength)],
      onChanged: widget.onChanged,
    );
  }
}

// ------------------------------------------------------------------ long forms

/// Form field of the long forms: icon + label row, the control below it, and
/// an optional hint line.
class ProjField extends StatelessWidget {
  const ProjField({
    super.key,
    required this.icon,
    required this.label,
    required this.child,
    this.hint,
    this.count,
    this.textarea = false,
    this.twoRows = false,
  });

  /// Lucide icon name (see `Lu`).
  final String icon;
  final String label;
  final Widget child;
  final String? hint;

  /// Character counter ("n/500") shown inside the text area's bottom corner.
  final String? count;
  final bool textarea;

  /// Compact field without the hint row (used in three-column rows).
  final bool twoRows;

  @override
  Widget build(BuildContext context) {
    final labelRow = Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Padding(
          padding: EdgeInsets.only(top: textarea ? 0 : 0.75),
          child: SizedBox(
            width: 18,
            height: 18,
            child: Center(child: LucideIcon(icon, size: 16, strokeWidth: 1.75, color: kAddIc)),
          ),
        ),
        const SizedBox(width: 6),
        Expanded(
          child: Tx(label, style: ts(14, w: 600, lh: 21, c: AppColors.deep)),
        ),
      ],
    );
    final hintW = hint == null
        ? null
        : Tx(
            hint!,
            style: textarea ? ts(12, w: 500, lh: 17, c: kAddHintTa) : ts(13, w: 500, lh: 18, c: kAddPh),
          );
    final control = count == null
        ? child
        : Stack(
            children: <Widget>[
              child,
              PositionedDirectional(
                end: 14,
                bottom: 8,
                child: IgnorePointer(
                  child: Tx(count!, style: ts(12, w: 500, lh: 18, c: kAddPh), softWrap: false),
                ),
              ),
            ],
          );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        labelRow,
        const SizedBox(height: 6),
        control,
        if (!twoRows && hintW != null) ...<Widget>[const SizedBox(height: 6), hintW],
      ],
    );
  }
}

/// Text input of the long forms (48 px box).
Widget projInput({
  required String value,
  required ValueChanged<String> onChanged,
  String? placeholder,
  TextDirection? dir,
  TextAlign? align,
  TextInputType? keyboard,
}) {
  return CtlField(
    value: value,
    onChanged: onChanged,
    style: kProjText,
    placeholder: placeholder,
    placeholderColor: kAddPh,
    placeholderWeight: 500,
    height: 48,
    padding: const EdgeInsets.symmetric(horizontal: 14),
    decoration: kProjBox,
    dir: dir,
    textAlign: align,
    keyboardType: keyboard,
  );
}

/// Text area of the long forms (three lines, with room for the counter).
Widget projTextarea({required String value, required ValueChanged<String> onChanged, String? placeholder, int? maxLength}) {
  return CtlField(
    value: value,
    onChanged: onChanged,
    style: ts(16, w: 500, lh: 24, c: AppColors.deep),
    placeholder: placeholder,
    placeholderColor: kAddPh,
    placeholderWeight: 500,
    height: 118,
    padding: const EdgeInsets.fromLTRB(14, 12, 14, 32),
    decoration: kProjBox,
    multiline: true,
    maxLength: maxLength,
  );
}

/// Drop-down of the long forms (48 px box).
Widget projSelect({required String value, required List<Opt> options, required ValueChanged<String> onChanged}) {
  return SelectBox(
    value: value,
    options: options,
    onChanged: onChanged,
    style: kProjText,
    height: 48,
    padding: const EdgeInsets.symmetric(horizontal: 14),
    decoration: kProjBox,
    arrowColor: kAddIc,
  );
}

// ------------------------------------------------------------------ short forms

/// Card field of the short forms: icon tile, label and the value inside one
/// soft card.
class IosField extends StatelessWidget {
  const IosField({super.key, required this.icon, required this.label, required this.child, this.textarea = false});

  final String icon;
  final String label;
  final Widget child;
  final bool textarea;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minHeight: 76),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: kStoneBox,
      child: Row(
        crossAxisAlignment: textarea ? CrossAxisAlignment.start : CrossAxisAlignment.center,
        children: <Widget>[
          SizedBox(
            width: 40,
            child: Align(alignment: AlignmentDirectional.centerStart, child: iosIconTile(icon)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Tx(label, style: ts(14, w: 600, lh: 21, c: AppColors.deep)),
                const SizedBox(height: 2),
                child,
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// 36 px green icon tile of the card fields. [flipped] turns the icon upside
/// down (open state of a picker).
Widget iosIconTile(String icon, {bool flipped = false}) {
  final glyph = LucideIcon(icon, size: 18, strokeWidth: 1.75, color: AppColors.white);
  return Container(
    width: 36,
    height: 36,
    decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(12)),
    alignment: Alignment.center,
    child: flipped ? Transform.rotate(angle: 3.141592653589793, child: glyph) : glyph,
  );
}

/// Text input inside an [IosField].
Widget iosInput({required String value, required ValueChanged<String> onChanged, String? placeholder, TextDirection? dir, TextInputType? keyboard}) {
  return CtlField(
    value: value,
    onChanged: onChanged,
    style: kIosText,
    placeholder: placeholder,
    placeholderColor: kAddPh,
    placeholderWeight: 500,
    height: 26,
    padding: EdgeInsets.zero,
    dir: dir,
    keyboardType: keyboard,
  );
}

/// Text area inside an [IosField].
Widget iosTextarea({required String value, required ValueChanged<String> onChanged, String? placeholder}) {
  return CtlField(
    value: value,
    onChanged: onChanged,
    style: ts(15, w: 500, lh: 22, c: AppColors.deep),
    placeholder: placeholder,
    placeholderColor: kAddPh,
    placeholderWeight: 500,
    height: 56,
    padding: EdgeInsets.zero,
    multiline: true,
  );
}

/// Drop-down inside an [IosField].
Widget iosSelect({required String value, required List<Opt> options, required ValueChanged<String> onChanged}) {
  return SelectBox(
    value: value,
    options: options,
    onChanged: onChanged,
    style: kIosText,
    height: 26,
    padding: EdgeInsets.zero,
    arrowColor: AppColors.deep,
  );
}
