// Arabic dates and times (Gregorian months as used in Saudi Arabia).

/// يناير … ديسمبر
const List<String> kArMonths = <String>[
  'يناير',
  'فبراير',
  'مارس',
  'أبريل',
  'مايو',
  'يونيو',
  'يوليو',
  'أغسطس',
  'سبتمبر',
  'أكتوبر',
  'نوفمبر',
  'ديسمبر',
];

/// Weekday names by `DateTime.weekday` (1 = Monday … 7 = Sunday).
const List<String> kArWeekdays = <String>['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];

/// "12 أكتوبر" (with the year when [year] is true: "12 أكتوبر 2026").
String arDate(DateTime d, {bool year = false}) => '${d.day} ${kArMonths[d.month - 1]}${year ? ' ${d.year}' : ''}';

/// "4:05 م" — 12-hour clock with ص (morning) / م (evening).
String arTime(DateTime d) {
  final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
  final m = d.minute.toString().padLeft(2, '0');
  return '$h:$m ${d.hour < 12 ? 'ص' : 'م'}';
}

/// "12 أكتوبر 2026، 4:05 م"
String arDateTime(DateTime d) => '${arDate(d, year: true)}، ${arTime(d)}';

/// Short time of a list row: "الآن", "4:05 م" (today), "أمس", the weekday
/// within a week, else "12 أكتوبر".
String arRelative(DateTime t, DateTime now) {
  final diff = now.difference(t);
  if (!diff.isNegative && diff.inMinutes < 1) return 'الآن';
  final day = DateTime(t.year, t.month, t.day);
  final today = DateTime(now.year, now.month, now.day);
  final days = today.difference(day).inDays;
  if (days == 0) return arTime(t);
  if (days == 1) return 'أمس';
  if (days > 1 && days < 7) return kArWeekdays[t.weekday - 1];
  return arDate(t, year: t.year != now.year);
}
