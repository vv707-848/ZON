// The seeker's wish on the server (docs/api.md «الرغبات»), in the app's own model (Wish):
//
//   GET    /preferences          his wishes (the app shows one: the active one)
//   POST   /preferences          a new wish — at sign-up, or the first in «مساعد المطابقة»
//   PUT    /preferences/{id}     the wish changed
//   DELETE /preferences/{id}     «إيقاف المطابقة»
//
// A seeker without a wish is asked for one before the rest of the app (428 PREFERENCE_REQUIRED). The server
// matches and notifies from it twice a day; what the app does not ask for (a budget, rooms) stays empty there.

import 'package:zon/core/net/api.dart';
import 'package:zon/data/models/models.dart';

/// The seeker's wish on the server.
abstract final class ServerWishes {
  /// The active wish; null when he has none.
  static Future<Wish?> mine() async {
    final rows = (await Api.client!.get('preferences')).list;
    for (final row in rows) {
      if (row['is_active'] == true) return fromJson(row);
    }
    return null;
  }

  /// Saves [wish]: a new one, or the one it is ([Wish.id]). [operation] is the same on every try of one save.
  static Future<Wish> save(Wish wish, {required String operation}) async {
    final id = wish.id;
    final r = id == null ? await Api.client!.post('preferences', form(wish), operation) : await Api.client!.put('preferences/$id', form(wish));
    return fromJson(r.map);
  }

  static Future<void> remove(Wish wish) async {
    if (wish.id != null) await Api.client!.delete('preferences/${wish.id}');
  }

  /// The wish as the server reads it (its text is the notes the matching reads).
  static Map<String, Object?> form(Wish w) => <String, Object?>{
    'city': w.city,
    'neighborhoods': w.hoods,
    'property_types': w.types,
    'notes': w.text,
  };

  static Wish fromJson(Map<String, Object?> j) => Wish(
    id: j['id'] == null ? null : '${j['id']}',
    city: j['city'] is String ? j['city']! as String : '',
    hoods: <String>[
      if (j['neighborhoods'] is List)
        for (final h in j['neighborhoods']! as List)
          if (h is String) h,
    ],
    types: <String>{
      if (j['property_types'] is List)
        for (final t in j['property_types']! as List)
          if (t is String) normalizeType(t),
    }.toList(),
    budget: '',
    text: j['notes'] is String ? j['notes']! as String : '',
  );
}
