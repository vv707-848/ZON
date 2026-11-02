// Number fields that accept Arabic digits.

import 'package:flutter/services.dart';

import 'package:zon/core/utils/input.dart';

/// Keeps digits only, converting Arabic-Indic digits (٠-٩, ۰-۹) to 0–9 as
/// they are typed — instead of dropping them like
/// `FilteringTextInputFormatter.digitsOnly` does (QA finding REQ-03).
class AsciiDigitsFormatter extends TextInputFormatter {
  const AsciiDigitsFormatter({this.allowDecimal = false, this.allowMinus = false});

  /// Also keep one decimal point ("." or the Arabic "٫").
  final bool allowDecimal;

  /// Keep a leading minus so the form can refuse it with a clear message
  /// (prices: QA finding EDIT-02) instead of silently dropping it.
  final bool allowMinus;

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final ascii = toAsciiDigits(newValue.text);
    final buf = StringBuffer();
    var dot = false;
    for (final ch in ascii.split('')) {
      if (RegExp(r'[0-9]').hasMatch(ch)) {
        buf.write(ch);
      } else if (allowDecimal && ch == '.' && !dot) {
        dot = true;
        buf.write(ch);
      } else if (allowMinus && (ch == '-' || ch == '−') && buf.isEmpty) {
        buf.write('-');
      }
    }
    final text = buf.toString();
    if (text == newValue.text) return newValue;
    // Keep the caret at the same distance from the end of the text.
    final fromEnd = newValue.text.length - newValue.selection.extentOffset.clamp(0, newValue.text.length);
    final caret = (text.length - fromEnd).clamp(0, text.length);
    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: caret),
    );
  }
}
