// Sorting helpers.

/// Sorts without reordering equal items (Dart's `List.sort` is not stable).
List<T> stableSort<T>(List<T> list, int Function(T a, T b) cmp) {
  final indexed = <(int, T)>[for (var i = 0; i < list.length; i++) (i, list[i])];
  indexed.sort((a, b) {
    final c = cmp(a.$2, b.$2);
    return c != 0 ? c : a.$1.compareTo(b.$1);
  });
  return indexed.map((e) => e.$2).toList();
}

/// Case-insensitive comparison of ids ("l10" vs "L2"), ties broken by case.
int compareIds(String a, String b) {
  final la = a.toLowerCase();
  final lb = b.toLowerCase();
  final c = la.compareTo(lb);
  if (c != 0) return c < 0 ? -1 : 1;
  return a.compareTo(b).sign;
}
