// Shared value types and JSON helpers of the data layer.
//
// The models store small enumerations as plain strings (the same values that
// are saved on the device), so these typedefs document the allowed values.

/// Account role: "broker" | "owner" | "seeker" | "developer".
typedef Role = String;

/// Listing purpose: "sale" | "rent".
typedef Purpose = String;

/// Listing kind: "unit" (a single property) | "project" (a developer project).
typedef Kind = String;

/// Property type key — the list lives in `data/catalog/property_types.dart`:
/// apartment | studio | floor | villa | house | resthouse | land | building | shop | office.
typedef PType = String;

/// Floor of a unit inside a building: ground | first | second | annex | number.
typedef FloorLevel = String;

/// Who may rent: families | singles.
typedef Occupancy = String;

/// Property types that existed in version 1 and were removed, mapped to the
/// closest current type so data saved by older builds still opens correctly.
const Map<String, PType> kRetiredTypes = <String, PType>{'townhouse': 'villa', 'duplex': 'villa', 'penthouse': 'apartment', 'room': 'apartment'};

/// Current type key for a stored value (see [kRetiredTypes]).
PType normalizeType(String type) => kRetiredTypes[type] ?? type;

/// Marker used inside patch maps for "remove this key": the key is dropped
/// when the patch is serialized (like JavaScript's `undefined`).
const Object kUndefined = _Undefined();

class _Undefined {
  const _Undefined();
}

/// Removes [kUndefined] entries (recursively) before JSON encoding.
Object? jsonClean(Object? v) {
  if (v is Map) {
    final out = <String, Object?>{};
    v.forEach((k, val) {
      if (identical(val, kUndefined)) return;
      out[k as String] = jsonClean(val);
    });
    return out;
  }
  if (v is List) return v.where((e) => !identical(e, kUndefined)).map(jsonClean).toList();
  return v;
}

/// Reads a number from JSON that may hold a num, a numeric string or a bool.
double? numOrNull(Object? v) {
  if (v == null) return null;
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v);
  if (v is bool) return v ? 1 : 0;
  return null;
}
