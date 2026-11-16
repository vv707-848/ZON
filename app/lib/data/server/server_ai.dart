// The AI services with a server (docs/api.md «الذكاء»). The app sends what the user chose and wrote, and shows
// what the server answers — it ranks nothing itself, and makes up no result:
//
//   POST /ai/search                                 offers for a need: each a listing and its score
//   POST /ai/search/requests                        a broker's: the requests his offer answers
//   POST /ai/matching                               the wish's results: the same type · the city within the budget · projects
//   POST /ai/matching/projects/{id}/searchers       a developer's: the seekers a project suits, each with its unit
//   GET  /ai/matching/projects/{id}/brokers         a developer's: the brokers of its city by their clients' count
//   POST /ai/chat · GET /ai/chat                    «زون»: an answer with its sources and where to go; the history
//   POST /ai/feedback                               «مطابق / غير مطابق» on a result, with its reason
//
// `meta.ai` says how an answer was obtained ([AiMeta]): by the model, or — when it did not answer — the database's
// own order; and `simulated` when the server's AI is the simulation (never in production): the page says so.
// A service outside the plan answers 402 ([NeedsPlan]); a plan's daily share used up, 429 ([QuotaReached]).
// Nothing is sent once another account is signed in (Store.stillAccount), and a result is rated once.

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_desk.dart' show NeedsPlan;
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_requests.dart';
import 'package:zon/data/store.dart';

/// How the server obtained an AI answer (`meta.ai`).
class AiMeta {
  const AiMeta({required this.status, this.simulated = false});

  factory AiMeta.of(Map<String, Object?> meta) {
    final ai = meta['ai'] is Map ? (meta['ai']! as Map).cast<String, Object?>() : const <String, Object?>{};
    return AiMeta(status: '${ai['status'] ?? ''}', simulated: ai['simulated'] == true);
  }

  /// `ok` · `cached` · `degraded` · `fallback` · `timeout` · `rejected` · `skipped`.
  final String status;

  /// The server's AI is the simulation (rules, no model): said under the results.
  final bool simulated;

  /// The model ranked these (now, or for the same question before).
  bool get byModel => status == 'ok' || status == 'cached';

  /// Nothing to ask the model about (no candidate, out of scope).
  bool get skipped => status == 'skipped';
}

/// A record and how well it fits, 0–1.
class Scored<T> {
  const Scored(this.item, this.score);

  final T item;
  final double score;
}

/// A ranked answer and how it was obtained.
class Ranked<T> {
  const Ranked(this.results, this.ai);

  final List<Scored<T>> results;
  final AiMeta ai;
}

/// A unit model of a project the wish suits.
class MatchedUnit {
  const MatchedUnit({required this.id, required this.name, required this.score, this.price, this.bedrooms, this.area});

  final int id;
  final String name;
  final double score;
  final num? price;
  final int? bedrooms;
  final num? area;
}

/// A project and the unit models of it the wish suits, the best first.
class MatchedProject {
  const MatchedProject({required this.project, required this.units});

  final Listing project;
  final List<MatchedUnit> units;
}

/// «مساعد المطابقة»: what the server found for the wish.
class Matching {
  const Matching({required this.matched, required this.broaden, required this.projects, required this.suggestRequest, required this.ai});

  /// The wish's own type, in its neighbourhoods.
  final List<Scored<Listing>> matched;

  /// «توسيع الإدراك»: what the budget buys in the whole city — other neighbourhoods and other types.
  final List<Scored<Listing>> broaden;
  final List<MatchedProject> projects;

  /// Nothing fits: the server suggests posting a request.
  final bool suggestRequest;
  final AiMeta ai;

  bool get empty => matched.isEmpty && broaden.isEmpty && projects.isEmpty;
}

/// A seeker a project suits (his public profile only), what he wished, and the unit model nearest to him.
class ProjectSeeker {
  const ProjectSeeker({
    required this.username,
    required this.name,
    required this.score,
    required this.neighborhoods,
    required this.types,
    this.budgetMax,
    this.notes = '',
    this.unitId,
    this.unitName,
  });

  final String username;
  final String name;
  final double score;
  final List<String> neighborhoods;
  final List<String> types;
  final num? budgetMax;
  final String notes;
  final int? unitId;
  final String? unitName;
}

/// A broker of the project's city, and how many of his clients it could suit (none of them shown).
class AreaBroker {
  const AreaBroker({required this.username, required this.name, required this.clients, required this.falVerified});

  final String username;
  final String name;
  final int clients;
  final bool falVerified;
}

/// A source of a chat answer: the library's document it quoted.
class ChatSource {
  const ChatSource({required this.title, this.quote = '', this.effective});

  final String title;
  final String quote;
  final String? effective;
}

/// «زون»'s answer.
class ChatReply {
  const ChatReply({
    required this.answer,
    required this.covered,
    required this.intent,
    required this.sources,
    required this.ai,
    this.action,
    this.lang = 'ar',
  });

  /// In the language he wrote in.
  final String answer;

  /// The server's reading of that language: `ar` · `en` · `ur`, or `xx` for any other.
  final String lang;

  /// False: the library does not cover the question, and the answer says so.
  final bool covered;
  final String intent;
  final List<ChatSource> sources;

  /// Where the answer leads in the app (`/account/plans`…), if anywhere.
  final String? action;
  final AiMeta ai;
}

/// One exchange of the chat's history.
class ChatTurn {
  const ChatTurn({required this.question, required this.answer});

  final String question;
  final String answer;
}

/// The plan's share of a service is used up for today (429 QUOTA_EXCEEDED): the server's own words.
class QuotaReached implements Exception {
  const QuotaReached(this.message);

  final String message;
}

abstract final class ServerAi {
  static double _score(Object? v) => v is num ? v.toDouble().clamp(0.0, 1.0) : 0.0;

  static List<Map<String, Object?>> _rows(Object? v) => <Map<String, Object?>>[
    if (v is List)
      for (final row in v)
        if (row is Map) row.cast<String, Object?>(),
  ];

  static Map<String, Object?> _map(Object? v) => v is Map ? v.cast<String, Object?>() : const <String, Object?>{};

  /// The plan's refusals, as what the pages show for them.
  static Future<T> _asked<T>(Future<T> Function() ask) async {
    try {
      return await ask();
    } on ApiError catch (e) {
      if (e.status == 402) throw const NeedsPlan();
      if (e.code == 'QUOTA_EXCEEDED') throw QuotaReached(e.forUser);
      rethrow;
    }
  }

  static List<Scored<Listing>> _listings(Object? rows) => <Scored<Listing>>[
    for (final row in _rows(rows))
      if (row['listing'] is Map) Scored<Listing>(ServerListings.fromListing(_map(row['listing'])), _score(row['score'])),
  ];

  /// What a search sends: the three he chose, and what he wrote if anything.
  static Map<String, Object?> need({required String city, required String neighborhood, required String type, String query = ''}) =>
      <String, Object?>{'city': city, 'neighborhood': neighborhood, 'property_type': type, if (query.trim().isNotEmpty) 'query': query.trim()};

  static Future<Ranked<Listing>> search(Map<String, Object?> need, {required int epoch}) => _asked(() async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('ai/search', need);
    return Ranked<Listing>(_listings(r.map['results']), AiMeta.of(r.meta));
  });

  static Future<Ranked<RequestItem>> searchRequests(Map<String, Object?> need, {required int epoch}) => _asked(() async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('ai/search/requests', need);
    return Ranked<RequestItem>(<Scored<RequestItem>>[
      for (final row in _rows(r.map['results']))
        if (row['request'] is Map) Scored<RequestItem>(ServerRequests.fromJson(_map(row['request'])), _score(row['score'])),
    ], AiMeta.of(r.meta));
  });

  static Future<Matching> matching({required int epoch}) => _asked(() async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('ai/matching');
    return Matching(
      matched: _listings(r.map['matched']),
      broaden: _listings(r.map['broaden']),
      projects: <MatchedProject>[
        for (final row in _rows(r.map['projects']))
          if (row['project'] is Map)
            MatchedProject(
              project: ServerListings.fromProject(_map(row['project'])),
              units: <MatchedUnit>[
                for (final u in _rows(row['units']))
                  if (_map(u['unit'])['id'] is int)
                    MatchedUnit(
                      id: _map(u['unit'])['id']! as int,
                      name: '${_map(u['unit'])['model_name'] ?? ''}',
                      score: _score(u['score']),
                      price: _map(u['unit'])['price'] is num ? _map(u['unit'])['price']! as num : null,
                      bedrooms: _map(u['unit'])['bedrooms'] is int ? _map(u['unit'])['bedrooms']! as int : null,
                      area: <Object?>[_map(u['unit'])['area_built'], _map(u['unit'])['area_land']].whereType<num>().firstOrNull,
                    ),
              ],
            ),
      ],
      suggestRequest: r.map['suggest_request'] == true,
      ai: AiMeta.of(r.meta),
    );
  });

  /// The seekers a published project of his suits; [project] is its number on the server.
  static Future<Ranked<ProjectSeeker>> searchers(int project, {required int epoch}) => _asked(() async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('ai/matching/projects/$project/searchers');
    return Ranked<ProjectSeeker>(<Scored<ProjectSeeker>>[
      for (final row in r.list)
        if (row['seeker'] is Map)
          Scored<ProjectSeeker>(
            ProjectSeeker(
              username: '${_map(row['seeker'])['username'] ?? ''}',
              name: '${_map(row['seeker'])['name'] ?? ''}',
              score: _score(row['score']),
              neighborhoods: <String>[for (final n in (_map(row['wish'])['neighborhoods'] as List?) ?? const <Object?>[]) '$n'],
              types: <String>[for (final t in (_map(row['wish'])['property_types'] as List?) ?? const <Object?>[]) '$t'],
              budgetMax: _map(row['wish'])['budget_max'] is num ? _map(row['wish'])['budget_max']! as num : null,
              notes: '${_map(row['wish'])['notes'] ?? ''}',
              unitId: _map(row['unit'])['id'] is int ? _map(row['unit'])['id']! as int : null,
              unitName: _map(row['unit'])['model_name'] is String ? _map(row['unit'])['model_name']! as String : null,
            ),
            _score(row['score']),
          ),
    ], AiMeta.of(r.meta));
  });

  static Future<List<AreaBroker>> brokers(int project, {required int epoch}) async {
    store.stillAccount(epoch);
    final r = await Api.client!.get('ai/matching/projects/$project/brokers');
    return <AreaBroker>[
      for (final row in r.list)
        if (row['broker'] is Map)
          AreaBroker(
            username: '${_map(row['broker'])['username'] ?? ''}',
            name: '${_map(row['broker'])['name'] ?? ''}',
            clients: row['clients'] is int ? row['clients']! as int : 0,
            falVerified: _map(row['broker'])['fal_verified'] == true,
          ),
    ];
  }

  static Future<ChatReply> chat(String message, {required int epoch}) => _asked(() async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('ai/chat', <String, Object?>{'message': message.trim()});
    return ChatReply(
      answer: '${r.map['answer'] ?? ''}',
      covered: r.map['covered'] == true,
      intent: '${r.map['intent'] ?? ''}',
      lang: '${r.map['lang'] ?? 'ar'}',
      sources: <ChatSource>[
        for (final s in _rows(r.map['sources']))
          ChatSource(
            title: '${s['title'] ?? ''}',
            quote: '${s['quote_ar'] ?? ''}',
            effective: s['effective_date'] is String ? s['effective_date']! as String : null,
          ),
      ],
      action: r.map['action'] is String ? r.map['action']! as String : null,
      ai: AiMeta.of(r.meta),
    );
  });

  /// What was asked and answered before, the oldest first.
  static Future<List<ChatTurn>> history() async {
    final r = await Api.client!.get('ai/chat');
    return <ChatTurn>[for (final row in r.list) ChatTurn(question: '${row['question'] ?? ''}', answer: '${row['answer'] ?? ''}')];
  }

  /// «مطابق / غير مطابق» on a result, with what was asked for ([query]). [context] is `search` · `matching` · `requests`; [type] `listing` · `request`
  /// · `project_unit` · `searcher`. The server asks for the reason of «غير مطابق».
  static Future<void> feedback({
    required String context,
    required String type,
    required int id,
    required bool isMatch,
    required String reason,
    required int epoch,
    Map<String, Object?>? query,
  }) async {
    store.stillAccount(epoch);
    await Api.client!.post('ai/feedback', <String, Object?>{
      'context': context,
      'result_type': type,
      'result_id': id,
      'is_match': isMatch,
      if (reason.trim().isNotEmpty) 'reason': reason.trim(),
      'query': ?query,
    });
  }
}
