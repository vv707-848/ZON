// Reading what the user typed: Arabic digits, numbers and phone numbers.
//
// Arabic keyboards type ٠١٢٣٤٥٦٧٨٩ (and Persian keyboards ۰۱۲۳۴۵۶۷۸۹).
// Every number field passes its text through [toAsciiDigits] before parsing,
// so «٥٠٠٠٠٠» is read as 500000 instead of being dropped as "not a digit".

/// Converts Arabic-Indic and Eastern Arabic digits to 0–9, and the Arabic
/// decimal / thousands separators (٫ ٬) to "." and ",".
String toAsciiDigits(String s) {
  if (s.isEmpty) return s;
  final out = StringBuffer();
  for (final r in s.runes) {
    if (r >= 0x0660 && r <= 0x0669) {
      out.writeCharCode(0x30 + r - 0x0660); // ٠–٩
    } else if (r >= 0x06F0 && r <= 0x06F9) {
      out.writeCharCode(0x30 + r - 0x06F0); // ۰–۹
    } else if (r == 0x066B) {
      out.write('.'); // ٫
    } else if (r == 0x066C) {
      out.write(','); // ٬
    } else {
      out.writeCharCode(r);
    }
  }
  return out.toString();
}

/// Only the digits of [v], Arabic digits converted ("١٢a3" → "123").
String digitsOnly(String v) => toAsciiDigits(v).replaceAll(RegExp(r'[^0-9]'), '');

/// Reads a number typed by the user: Arabic digits, spaces and thousands
/// separators are accepted ("٥٠٠٬٠٠٠" → 500000, "1,250.5" → 1250.5).
/// Returns null for an empty or invalid text — never 0 by accident.
double? parseUserNumber(String v) {
  final t = toAsciiDigits(v).replaceAll(RegExp(r'[\s,_]'), '').trim();
  if (t.isEmpty) return null;
  if (!RegExp(r'^-?\d+(\.\d+)?$').hasMatch(t)) return null;
  final n = double.tryParse(t);
  if (n == null || n.isNaN || n.isInfinite) return null;
  return n;
}

/// A Saudi mobile number in the form `05XXXXXXXX`, or null when [input] is
/// not one. Accepts +966 / 00966 / 966 prefixes, spaces, dashes and Arabic
/// digits. (Sending the SMS code waits for the server.)
String? normalizeSaudiMobile(String input) {
  var s = toAsciiDigits(input).replaceAll(RegExp(r'[\s\-().]'), '');
  if (s.startsWith('+966')) {
    s = '0${s.substring(4)}';
  } else if (s.startsWith('00966')) {
    s = '0${s.substring(5)}';
  } else if (s.startsWith('966')) {
    s = '0${s.substring(3)}';
  } else if (s.length == 9 && s.startsWith('5')) {
    s = '0$s';
  }
  return RegExp(r'^05\d{8}$').hasMatch(s) ? s : null;
}
