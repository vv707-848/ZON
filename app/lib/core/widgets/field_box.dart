// Text input used by every form of the app.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:zon/core/theme/theme.dart';

/// A text field drawn inside a fixed box ([decoration], [padding], [height]).
///
/// The placeholder uses the text colour at 50 % opacity unless
/// [placeholderColor] is given. Set [multiline] for a text area that starts at
/// the top and wraps.
class FieldBox extends StatelessWidget {
  const FieldBox({
    super.key,
    this.controller,
    this.focusNode,
    this.placeholder,
    required this.style,
    this.placeholderColor,
    this.placeholderWeight,
    this.height = 48,
    this.padding = const EdgeInsets.symmetric(horizontal: 14),
    this.decoration,
    this.dir,
    this.textAlign,
    this.obscure = false,
    this.keyboardType,
    this.inputFormatters,
    this.onChanged,
    this.onSubmitted,
    this.multiline = false,
    this.readOnly = false,
    this.enabled = true,
    this.textInputAction,
    this.autofocus = false,
    this.scrollPadding = const EdgeInsets.all(20),
  });

  final TextEditingController? controller;
  final FocusNode? focusNode;
  final String? placeholder;
  final TextStyle style;
  final Color? placeholderColor;

  /// Placeholder weight (400–700); the text weight when null.
  final int? placeholderWeight;

  /// Outer height (null → grows with the text for multi-line fields).
  final double? height;
  final EdgeInsets padding;
  final BoxDecoration? decoration;

  /// Text direction (null → the app's RTL). Use LTR for phone numbers etc.
  final TextDirection? dir;

  /// Null → the form's start side (right in Arabic), also for LTR fields.
  final TextAlign? textAlign;
  final bool obscure;
  final TextInputType? keyboardType;
  final List<TextInputFormatter>? inputFormatters;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;

  /// Text area: starts at the top and wraps onto new lines.
  final bool multiline;
  final bool readOnly;
  final bool enabled;
  final TextInputAction? textInputAction;
  final bool autofocus;

  /// Room kept around the caret when the page scrolls the field into view
  /// (e.g. when the keyboard opens). Larger at the bottom to also reveal
  /// what sits under the field.
  final EdgeInsets scrollPadding;

  @override
  Widget build(BuildContext context) {
    final color = style.color ?? AppColors.ink;
    var hint = style.copyWith(color: placeholderColor ?? color.withValues(alpha: color.a * 0.5));
    if (placeholderWeight != null) hint = hint.copyWith(fontWeight: FontWeight(placeholderWeight!));
    final ambient = Directionality.of(context);
    final direction = dir ?? ambient;
    // A field typed in the other direction (e.g. digits in an Arabic form)
    // still lines up with its label on the form's start side.
    final align = textAlign ?? (direction == ambient ? TextAlign.start : (ambient == TextDirection.rtl ? TextAlign.right : TextAlign.left));
    final field = TextField(
      controller: controller,
      focusNode: focusNode,
      style: style,
      textDirection: direction,
      textAlign: align,
      obscureText: obscure,
      keyboardType: multiline ? TextInputType.multiline : keyboardType,
      inputFormatters: inputFormatters,
      onChanged: onChanged,
      onSubmitted: onSubmitted,
      readOnly: readOnly,
      enabled: enabled,
      autofocus: autofocus,
      scrollPadding: scrollPadding,
      textInputAction: textInputAction ?? (multiline ? TextInputAction.newline : null),
      maxLines: multiline ? null : 1,
      expands: multiline && height != null,
      textAlignVertical: multiline ? TextAlignVertical.top : TextAlignVertical.center,
      cursorColor: AppColors.forest,
      cursorWidth: 2,
      decoration: InputDecoration.collapsed(hintText: placeholder, hintStyle: hint),
    );
    return Container(
      height: height,
      padding: padding,
      decoration: decoration,
      alignment: multiline ? AlignmentDirectional.topStart : AlignmentDirectional.centerStart,
      child: Directionality(textDirection: direction, child: field),
    );
  }
}
