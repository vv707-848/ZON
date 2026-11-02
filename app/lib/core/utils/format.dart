// Number and area formatting shared by the whole app.

/// Formats a number with thousands separators and up to three decimals:
/// 4200000 → "4,200,000", 2.5 → "2.5". Used for prices, areas and counts.
String sar(num n) {
  if (n.isNaN) return 'NaN';
  if (n.isInfinite) return n > 0 ? '∞' : '-∞';
  final neg = n < 0;
  final abs = n.abs();
  var s = abs.toStringAsFixed(3);
  var intPart = s.split('.')[0];
  var frac = s.split('.')[1];
  frac = frac.replaceAll(RegExp(r'0+$'), '');
  final buf = StringBuffer();
  for (var i = 0; i < intPart.length; i++) {
    if (i > 0 && (intPart.length - i) % 3 == 0) buf.write(',');
    buf.write(intPart[i]);
  }
  final res = frac.isEmpty ? buf.toString() : '$buf.$frac';
  return (neg && res != '0') ? '-$res' : res;
}

/// Same as [sar] (kept for readability where a count, not money, is shown).
String loc(num n) => sar(n);

/// "450 م²", or "—" when unknown.
String areaLabel(num? n) => n == null ? '—' : '${sar(n)} م²';

/// A number without a trailing ".0" (5.0 → "5", 2.5 → "2.5").
String plainNumber(num v) => v == v.roundToDouble() ? v.round().toString() : v.toString();
