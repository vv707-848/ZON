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

  static Note _note(Map<String, Object?> j) =>
      Note(id: '${j['id'] ?? ''}', kind: _kindIn['${j['category']}'] ?? '${j['category'] ?? ''}', text: '${j['body'] ?? ''}');

  static Future<List<Note>> notes(String id) async => <Note>[for (final row in (await Api.client!.get('clients/${_raw(id)}/notes')).list) _note(row)];

  static Future<Note> addNote(String id, String kind, String text, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('clients/${_raw(id)}/notes', <String, Object?>{'category': _kindOut[kind] ?? 'followup', 'body': text}, operation);
    return _note(r.map);
  }

  static Future<void> removeNote(String id, String note, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.delete('clients/${_raw(id)}/notes/$note');
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
