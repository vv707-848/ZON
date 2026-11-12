// Matching assistant and social data.

import 'package:zon/data/models/types.dart';

/// The user's "match / no match" mark on a search result, with a reason.
class SemMark {
  const SemMark({required this.v, required this.reason});

  /// "match" | "nomatch"
  final String v;
  final String reason;

  Map<String, Object?> toJson() => <String, Object?>{'v': v, 'reason': reason};

  factory SemMark.fromJson(Map<String, Object?> j) => SemMark(v: j['v'] as String? ?? 'match', reason: j['reason'] as String? ?? '');
}

/// The seeker's saved wish (city, neighbourhoods, types, budget, text) that
/// drives the matching assistant.
class Wish {
  const Wish({required this.city, required this.hoods, required this.types, required this.budget, required this.text, this.id});

  /// Its id on the server (a wish saved there); null on this phone only.
  final String? id;

  final String city;
  final List<String> hoods;
  final List<PType> types;
  final String budget;
  final String text;

  Map<String, Object?> toJson() => <String, Object?>{'city': city, 'hoods': hoods, 'types': types, 'budget': budget, 'text': text, 'id': ?id};

  factory Wish.fromJson(Map<String, Object?> j) => Wish(
    id: j['id'] is String ? j['id']! as String : null,
    city: j['city'] as String? ?? '',
    hoods: (j['hoods'] as List?)?.map((e) => e.toString()).toList() ?? const <String>[],
    types: (j['types'] as List?)?.map((e) => normalizeType(e.toString())).toSet().toList() ?? const <String>[],
    budget: j['budget'] as String? ?? '',
    text: j['text'] as String? ?? '',
  );
}

/// A follower / followed account.
class Person {
  const Person({required this.id, required this.n, required this.r, required this.role});

  final String id;
  final String n;
  final String r;
  final Role role;

  Map<String, Object?> toJson() => <String, Object?>{'id': id, 'n': n, 'r': r, 'role': role};

  factory Person.fromJson(Map<String, Object?> j) =>
      Person(id: j['id'] as String? ?? '', n: j['n'] as String? ?? '', r: j['r'] as String? ?? '', role: j['role'] as String? ?? 'broker');
}
