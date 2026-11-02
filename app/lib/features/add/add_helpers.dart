// Pure helpers shared by the add and edit forms (no widgets here).
//
// * text → number conversions for the form fields,
// * defaults for a new project unit,
// * the AI wording placeholder ([polishCopy]).

import 'dart:math';

import 'package:zon/core/utils/input.dart';
import 'package:zon/data/catalog/catalog.dart';

export 'package:zon/core/utils/input.dart' show digitsOnly, normalizeSaudiMobile, parseUserNumber, toAsciiDigits;

// ------------------------------------------------------------------ numbers

/// The number typed in a field (Arabic digits accepted), or 0 when it is
/// empty or not a number.
double numOr0(String v) => parseUserNumber(v) ?? 0;

/// A price typed in a form (QA EDIT-02 / F04): empty → 0 (kept as before —
/// a zero price waits for a product decision, QA LIST-02); a number ≥ 0 →
/// that number (Arabic digits are read); anything else → an error message,
/// never a silent 0.
({double? value, String? error}) parsePrice(String text) {
  final t = text.trim();
  if (t.isEmpty) return (value: 0, error: null);
  final n = parseUserNumber(t);
  if (n == null) return (value: null, error: 'أدخل السعر بالأرقام');
  if (n < 0) return (value: null, error: 'السعر لا يكون سالبًا');
  return (value: n, error: null);
}

/// Error for a "from / to" pair (budget, area, rooms) when both are typed
/// and "from" is larger, e.g. "في الميزانية: «من» أكبر من «إلى»". An empty
/// or zero "to" means no upper limit.
String? rangeError(String label, String min, String max) {
  final a = parseUserNumber(min);
  final b = parseUserNumber(max);
  if (a != null && b != null && b > 0 && a > b) return 'في $label: «من» أكبر من «إلى»';
  return null;
}

/// Format check of an advertising licence number: 6–12 digits. (The real
/// check with REGA waits for the server; the UI says so.)
bool licenceFormatOk(String v) => RegExp(r'^\d{6,12}$').hasMatch(toAsciiDigits(v.trim()));

/// The number typed in a field, or null when it is empty or zero.
double? optionalNum(String v) {
  final n = numOr0(v);
  return n == 0 ? null : n;
}

/// A stored number as it should appear inside a text field
/// (null → empty, 5.0 → "5", 2.5 → "2.5").
String editableNumber(num? n) => n == null ? '' : plainNumber(n);

// ------------------------------------------------------------------ defaults

final Random _rnd = Random();

/// Random 5-character id suffix (letters and digits) for new units.
String randomId5() => List<String>.generate(5, (_) => _rnd.nextInt(36).toRadixString(36)).join();

/// The first neighbourhood of [city] (Riyadh when the city is empty).
String firstHood(String city) => kCities[city.isNotEmpty ? city : 'الرياض']?.first ?? '';

/// A new project unit of type [ptype] pre-filled with typical values
/// (only the fields that [specsForUnit] allows for that type).
UnitModel emptyUnit([PType ptype = 'apartment']) {
  final s = specsForUnit(ptype);
  return UnitModel(
    id: 'u-${randomId5()}',
    name: '',
    propertyType: ptype,
    rooms: s.rooms ? 3 : null,
    baths: s.rooms ? 2 : null,
    halls: s.rooms ? 1 : null,
    area: 140,
    landArea: s.land ? 400 : null,
    builtArea: s.built ? 140 : null,
    landUse: s.landUse ? 'residential' : null,
    price: 0,
    sold: false,
    image: typeImage(ptype),
    description: '',
  );
}

// ------------------------------------------------------------------ AI wording

final RegExp _endMark = RegExp(r'[.؟!]$');

/// What the kind of text is: a listing, a project or a project unit.
enum CopyKind { listing, project, unit }

/// Placeholder for the AI wording service used by the add forms.
///
/// It tidies the user's text and appends a sentence describing how the AI
/// would phrase it. Replace the body with a call to the real AI endpoint when
/// the backend is ready — the forms only depend on this signature.
String polishCopy(String raw, [CopyKind kind = CopyKind.listing]) {
  final t = raw.trim().replaceAll(RegExp(r'\s+'), ' ');
  if (kind == CopyKind.project) {
    if (t.isEmpty) return 'يتميز المشروع بقربه من الخدمات اليومية والمدارس والمساجد — دون ذكر عدد الوحدات أو المساحات.';
    final body = _endMark.hasMatch(t) ? t : '$t.';
    return '$body صياغة تركّز على ما يميّز المشروع من موقع وخدمات — بلا أرقام وحدات أو مساحات.';
  }
  if (t.isEmpty) return 'وصف بصياغة معلن معتمدة: الموقع والمدخل والتشطيب — دون تكرار المدينة أو النوع أو المساحة.';
  final body = _endMark.hasMatch(t) ? t : '$t.';
  return '$body صياغة مرتّبة للباحث: المدخل والإطلالة والقرب من الخدمات والتشطيب — بلا تكرار للخانات.';
}

/// The lighter wording used by the edit screens (only tidies the text).
String tidyCopy(String raw, CopyKind kind) {
  final t = raw.trim().replaceAll(RegExp(r'\s+'), ' ');
  if (kind == CopyKind.project) {
    if (t.isEmpty) return 'يتميز المشروع بقربه من الخدمات اليومية والمدارس والمساجد.';
    return _endMark.hasMatch(t) ? t : '$t.';
  }
  if (t.isEmpty) return 'وحدة بمواصفات واضحة وتشطيب مرتّب.';
  return _endMark.hasMatch(t) ? t : '$t.';
}
