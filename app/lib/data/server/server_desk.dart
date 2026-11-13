// The desk with a server (docs/api.md «المكتب»): «مهامي», «إدارة العملاء» with each client's notes, and the AI's
// summary of them (POST /ai/summaries). All of it is his alone: another account's answers 404.
//
//   GET    /tasks                       his tasks, open first, then by priority
//   POST   /tasks                       a task (once however often tried: Idempotency-Key)
//   PATCH  /tasks/{id}                  what changed; a new reminder time reminds again
//   POST   /tasks/{id}/done             `done` true · false: sent twice it stays as asked
//   DELETE /tasks/{id}
//   GET    /clients                     his clients, 30 a page, newest first, with their notes' count
//   POST · PATCH · DELETE /clients[/{id}]
//   GET · POST /clients/{id}/notes · DELETE /clients/{id}/notes/{note}
//
// Nothing goes out once another account is signed in (Store.stillAccount).

import 'dart:io';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';

/// A task's priority as the app names it, and as the server does.
const Map<String, String> _priOut = <String, String>{'مهم': 'high', 'عادي': 'medium', 'مؤجل': 'low'};
const Map<String, String> _priIn = <String, String>{'high': 'مهم', 'medium': 'عادي', 'low': 'مؤجل'};

/// A note's kind as the app names it, and as the server does. The server knows more kinds (from the site and the
/// panel): they are shown by their own names.
const Map<String, String> _kindOut = <String, String>{'قبول': 'interested', 'رفض': 'rejected', 'تفكير': 'followup'};
const Map<String, String> _kindIn = <String, String>{
  'interested': 'قبول',
  'rejected': 'رفض',
  'followup': 'تفكير',
  'cash': 'كاش',
  'offered': 'تم العرض',
  'closed': 'مغلق',
};

String _two(int n) => n.toString().padLeft(2, '0');

/// `YYYY-MM-DDTHH:mm` on this phone (what the form shows) from the server's time, and back.
String? _localMinute(Object? at) {
  final d = DateTime.tryParse('${at ?? ''}')?.toLocal();
  return d == null ? null : '${d.year}-${_two(d.month)}-${_two(d.day)}T${_two(d.hour)}:${_two(d.minute)}';
}

String? _serverTime(String? local) {
  final d = local == null || local.isEmpty ? null : DateTime.tryParse(local);
  return d?.toUtc().toIso8601String();
}

int _raw(String appId) => int.parse(appId.replaceFirst(RegExp('^[A-Z]+'), ''));

/// A note of a client or an owner as the app shows it.
Note deskNote(Map<String, Object?> j) =>
    Note(id: '${j['id'] ?? ''}', kind: _kindIn['${j['category']}'] ?? '${j['category'] ?? ''}', text: '${j['body'] ?? ''}');

/// A note's kind as the server names it.
String deskNoteCategory(String kind) => _kindOut[kind] ?? 'followup';

abstract final class ServerTasks {
  static String appId(int id) => 'T$id';

  static Task fromJson(Map<String, Object?> j) => Task(
    id: appId(j['id'] is int ? j['id']! as int : 0),
    title: '${j['title'] ?? ''}',
    body: j['body'] is String ? j['body']! as String : null,
    pri: _priIn['${j['priority']}'] ?? 'عادي',
    remindAt: _localMinute(j['remind_at']),
    done: j['done'] == true,
  );

  static Future<List<Task>> list() async => <Task>[for (final row in (await Api.client!.get('tasks')).list) fromJson(row)];

  static Map<String, Object?> body(Task t) => <String, Object?>{
    'title': t.title,
    'body': (t.body ?? '').isEmpty ? null : t.body,
    'priority': _priOut[t.pri] ?? 'medium',
    if ((t.remindAt ?? '').isNotEmpty) 'remind_at': _serverTime(t.remindAt),
  };

  static Future<Task> create(Task t, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    return fromJson((await Api.client!.post('tasks', body(t), operation)).map);
  }

  /// What changed from [before]: a reminder already passed is not sent again (the server asks a time to come).
  static Future<Task> update(Task t, {required Task before, required int epoch}) async {
    store.stillAccount(epoch);
    final b = <String, Object?>{
      if (t.title != before.title) 'title': t.title,
      if ((t.body ?? '') != (before.body ?? '')) 'body': (t.body ?? '').isEmpty ? null : t.body,
      if (t.pri != before.pri) 'priority': _priOut[t.pri] ?? 'medium',
      if ((t.remindAt ?? '') != (before.remindAt ?? '')) 'remind_at': _serverTime(t.remindAt),
    };
    if (b.isEmpty) return before;
    return fromJson((await Api.client!.patch('tasks/${_raw(t.id)}', b)).map);
  }

  static Future<Task> done(String id, {required bool done, required int epoch}) async {
    store.stillAccount(epoch);
    return fromJson((await Api.client!.post('tasks/${_raw(id)}/done', <String, Object?>{'done': done})).map);
  }

  static Future<void> remove(String id, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.delete('tasks/${_raw(id)}');
  }
}

abstract final class ServerClients {
  static String appId(int id) => 'K$id';
  static int rawId(String appId) => _raw(appId);

  static String _num(Object? v) => v is num ? (v == v.roundToDouble() ? '${v.round()}' : '$v') : '';

  /// A client of his book as the app shows it; his notes come with [notes].
  static Client fromJson(Map<String, Object?> j) {
    String list(Object? v, String Function(String) label) => v is List ? <String>[for (final x in v) label('$x')].join(' · ') : '';
    final budget = (_num(j['budget_min']), _num(j['budget_max']));
    return Client(
      id: appId(j['id'] is int ? j['id']! as int : 0),
      name: '${j['name'] ?? ''}',
      phone: j['phone'] is String ? j['phone']! as String : '',
      city: '${j['city'] ?? ''}',
      hood: list(j['neighborhoods'], (x) => x),
      types: list(j['property_types'], (x) => kTypeLabel[x] ?? x),
      budget: <String>[budget.$1, budget.$2].where((x) => x.isNotEmpty).join(' – '),
      notes: const <Note>[],
      notesCount: j['notes_count'] is int ? j['notes_count']! as int : null,
      purpose: j['purpose'] is String ? j['purpose']! as String : null,
      area: _num(j['area_min']).isEmpty ? null : _num(j['area_min']),
      rooms: j['rooms_min'] is int ? '${j['rooms_min']}' : null,
      description: j['description'] is String ? j['description']! as String : null,
    );
  }

  /// His clients, newest first (ten pages of 30 at most).
  static Future<List<Client>> list() async {
    final rows = <Client>[];
    for (var page = 1; page <= 10; page++) {
      final r = await Api.client!.get('clients', query: <String, String>{'page': '$page'});
      rows.addAll(<Client>[for (final row in r.list) fromJson(row)]);
      final meta = r.meta['page'];
      if (meta is! Map || meta['last'] is! int || (meta['last']! as int) <= page) break;
    }
    return rows;
  }

  static Future<Client> create(Map<String, Object?> body, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    return fromJson((await Api.client!.post('clients', body, operation)).map);
  }

  static Future<Client> update(String id, Map<String, Object?> body, {required int epoch}) async {
    store.stillAccount(epoch);
    return fromJson((await Api.client!.patch('clients/${_raw(id)}', body)).map);
  }

  static Future<void> remove(String id, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.delete('clients/${_raw(id)}');
  }

  /// His notes on [id] (`K12`).
  static const DeskNotes notes = DeskNotes('clients');
}

/// The notes of a client (`clients`) or an owner (`property-owners`): read, added once however often tried,
/// removed. The server keeps a note as written: it is not edited.
class DeskNotes {
  const DeskNotes(this.base);

  final String base;

  Future<List<Note>> of(String id) async => <Note>[for (final row in (await Api.client!.get('$base/${_raw(id)}/notes')).list) deskNote(row)];

  Future<Note> add(String id, String kind, String text, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('$base/${_raw(id)}/notes', <String, Object?>{'category': deskNoteCategory(kind), 'body': text}, operation);
    return deskNote(r.map);
  }

  Future<void> remove(String id, String note, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.delete('$base/${_raw(id)}/notes/$note');
  }
}

/// «تلخيص الذكاء» of a client's or an owner's notes, or of his tasks: the server's model, kept until the notes
/// change. Without a plan that includes it the server answers 402: [NeedsPlan].
abstract final class ServerSummaries {
  static Future<String> of(String scope, {String? id}) async {
    try {
      final r = (await Api.client!.post('ai/summaries', <String, Object?>{'scope': scope, if (id != null) 'id': _raw(id)})).map;
      final action = '${r['recommended_action'] ?? ''}'.trim();
      return <String>['${r['summary'] ?? ''}'.trim(), if (action.isNotEmpty) 'الخطوة التالية: $action'].where((x) => x.isNotEmpty).join('\n');
    } on ApiError catch (e) {
      if (e.status == 402) throw const NeedsPlan();
      rethrow;
    }
  }
}

/// The service is in the plans: «فعّل» leads to them.
class NeedsPlan implements Exception {
  const NeedsPlan();
}

/// A property in his private stock (GET /inventory): never public until he publishes it.
class StockItem {
  const StockItem({
    required this.id,
    required this.purpose,
    required this.type,
    required this.title,
    required this.city,
    required this.neighborhood,
    this.price,
    this.areaLand,
    this.areaBuilt,
    this.bedrooms,
    this.description,
    this.cover,
    this.listingId,
  });

  factory StockItem.fromJson(Map<String, Object?> j) {
    num? n(Object? v) => v is num ? v : null;
    return StockItem(
      id: 'S${j['id'] ?? 0}',
      purpose: '${j['purpose'] ?? 'sale'}',
      type: '${j['property_type'] ?? 'villa'}',
      title: '${j['title'] ?? ''}',
      city: '${j['city'] ?? ''}',
      neighborhood: '${j['neighborhood'] ?? ''}',
      price: n(j['price']),
      areaLand: n(j['area_land']),
      areaBuilt: n(j['area_built']),
      bedrooms: j['bedrooms'] is int ? j['bedrooms']! as int : null,
      description: j['description'] is String ? j['description']! as String : null,
      cover: j['cover_url'] is String ? j['cover_url']! as String : null,
      listingId: j['published_listing_id'] is int ? 'L${j['published_listing_id']}' : null,
    );
  }

  final String id;
  final Purpose purpose;
  final PType type;
  final String title;
  final String city;
  final String neighborhood;
  final num? price;
  final num? areaLand;
  final num? areaBuilt;
  final int? bedrooms;
  final String? description;
  final String? cover;

  /// The listing it became (`L12`), once published.
  final String? listingId;

  num? get area => type == 'land' ? areaLand : (areaBuilt ?? areaLand);
}

/// «المخزون» on the server: his alone, never indexed; «نشر كإعلان» goes through the listings' own path (the
/// same checks, REGA's licence and the wording), once: published again it answers 409.
abstract final class ServerStock {
  static Future<List<StockItem>> list() async => <StockItem>[for (final row in (await Api.client!.get('inventory')).list) StockItem.fromJson(row)];

  /// Uploads the photo at [path] (taken on this phone); its id on the server.
  static Future<int?> upload(String path, {required int epoch}) async {
    final file = File(path);
    if (!file.existsSync()) return null;
    final bytes = await file.readAsBytes();
    store.stillAccount(epoch);
    final r = await Api.client!.upload('media', field: 'photo', bytes: bytes, filename: file.uri.pathSegments.last);
    final id = r.map['id'];
    return id is int ? id : null;
  }

  static Future<StockItem> create(Map<String, Object?> body, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    return StockItem.fromJson((await Api.client!.post('inventory', body, operation)).map);
  }

  static Future<StockItem> update(String id, Map<String, Object?> body, {required int epoch}) async {
    store.stillAccount(epoch);
    return StockItem.fromJson((await Api.client!.patch('inventory/${_raw(id)}', body)).map);
  }

  static Future<void> remove(String id, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.delete('inventory/${_raw(id)}');
  }

  /// Publishes [id] with what a listing needs that the stock lacks ([more]: licence_number, price, description…):
  /// the listing (`L12`) and its status.
  static Future<(String, String)> publish(String id, Map<String, Object?> more, {required int epoch}) async {
    store.stillAccount(epoch);
    final r = (await Api.client!.post('inventory/${_raw(id)}/publish', more)).map;
    return ('L${r['listing_id']}', '${r['status'] ?? ''}');
  }
}
