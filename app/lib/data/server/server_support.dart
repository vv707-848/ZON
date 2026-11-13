// «الدعم» with a server (docs/api.md «الدعم»):
//
//   GET  /support-tickets                      his tickets, the latest written first, 30 a page
//   POST /support-tickets                      a complaint, a suggestion or a question (a visitor's with his name
//                                              and number): its reference and the announced response time
//   GET  /support-tickets/{reference}          the thread, every message («فريق زون» is `zon`, never a name);
//                                              reading it marks it read
//   POST /support-tickets/{reference}/messages his answer; a closed ticket opens again
//
// A ticket and a reply are sent once however often tried (Idempotency-Key); nothing goes out once another account
// is signed in (Store.stillAccount).

import 'package:zon/core/net/api.dart';
import 'package:zon/data/store.dart';

/// What a user may open himself, with its label.
const List<(String, String)> kTicketTypes = <(String, String)>[('complaint', 'شكوى'), ('suggestion', 'اقتراح'), ('question', 'استفسار')];

/// Every type a ticket can be (staff open `notice`).
const Map<String, String> kTicketTypeLabel = <String, String>{
  'complaint': 'شكوى',
  'suggestion': 'اقتراح',
  'question': 'استفسار',
  'notice': 'رسالة من فريق زون',
};

const Map<String, String> kTicketStatusLabel = <String, String>{'open': 'مفتوحة', 'answered': 'تم الرد', 'closed': 'مغلقة'};

/// One message of a thread.
class SupportMsg {
  const SupportMsg({required this.zon, required this.body, this.at});

  factory SupportMsg.fromJson(Map<String, Object?> j) =>
      SupportMsg(zon: j['author'] == 'zon', body: '${j['body'] ?? ''}', at: DateTime.tryParse('${j['at'] ?? ''}')?.toLocal());

  /// Written by «فريق زون» (otherwise by him).
  final bool zon;
  final String body;
  final DateTime? at;
}

/// A ticket as the server tells it; [messages] only in its thread.
class SupportTicket {
  const SupportTicket({
    required this.reference,
    required this.type,
    required this.status,
    required this.byZon,
    required this.unread,
    this.subject,
    this.aboutListing,
    this.aboutTitle,
    this.last,
    this.resolution,
    this.createdAt,
    this.messages = const <SupportMsg>[],
  });

  factory SupportTicket.fromJson(Map<String, Object?> j) {
    final about = j['about'] is Map ? (j['about']! as Map).cast<String, Object?>() : null;
    final last = j['last_message'] is Map ? (j['last_message']! as Map).cast<String, Object?>() : null;
    final subject = j['subject'] is String && (j['subject']! as String).trim().isNotEmpty ? j['subject']! as String : null;
    return SupportTicket(
      reference: '${j['reference'] ?? ''}',
      type: '${j['type'] ?? ''}',
      status: '${j['status'] ?? ''}',
      byZon: j['opened_by'] == 'zon',
      unread: j['unread'] == true,
      subject: subject,
      aboutListing: about?['type'] == 'listing' && about?['id'] is int ? about!['id']! as int : null,
      aboutTitle: about?['title'] is String ? about!['title']! as String : null,
      last: last == null ? null : SupportMsg(zon: last['author'] == 'zon', body: '${last['excerpt'] ?? ''}', at: DateTime.tryParse('${last['at'] ?? ''}')?.toLocal()),
      resolution: j['resolution'] is String ? j['resolution']! as String : null,
      createdAt: DateTime.tryParse('${j['created_at'] ?? ''}')?.toLocal(),
      messages: <SupportMsg>[
        if (j['messages'] is List)
          for (final m in j['messages']! as List)
            if (m is Map) SupportMsg.fromJson(m.cast<String, Object?>()),
      ],
    );
  }

  final String reference;
  final String type;
  final String status;

  /// Opened by «فريق زون» («رسالة من فريق زون»).
  final bool byZon;
  final bool unread;
  final String? subject;

  /// The listing it is about (its number on the server), and its title.
  final int? aboutListing;
  final String? aboutTitle;
  final SupportMsg? last;

  /// What was done — a closed ticket's.
  final String? resolution;
  final DateTime? createdAt;
  final List<SupportMsg> messages;

  bool get closed => status == 'closed';

  /// Its subject, or what it is.
  String get title => subject ?? kTicketTypeLabel[type] ?? 'تذكرة';
}

/// A page of tickets, and whether more remain.
class TicketPage {
  const TicketPage(this.tickets, {required this.hasMore});

  final List<SupportTicket> tickets;
  final bool hasMore;
}

abstract final class ServerSupport {
  static Future<TicketPage> list({int page = 1}) async {
    final r = await Api.client!.get('support-tickets', query: <String, String>{'page': '$page'});
    final meta = r.meta['page'];
    final last = meta is Map && meta['last'] is int ? meta['last']! as int : page;
    return TicketPage(<SupportTicket>[for (final row in r.list) SupportTicket.fromJson(row)], hasMore: last > page);
  }

  static Future<SupportTicket> show(String reference) async =>
      SupportTicket.fromJson((await Api.client!.get('support-tickets/${Uri.encodeComponent(reference)}')).map);

  /// What [open] sends: the server's fields, without the empty ones — a visitor's [name] and [phone] too.
  static Map<String, Object?> body({required String type, required String subject, required String text, String? name, String? phone}) =>
      <String, Object?>{
        'type': type,
        if (subject.trim().isNotEmpty) 'subject': subject.trim(),
        'body': text.trim(),
        if (name != null) 'name': name.trim(),
        'phone': ?phone,
      };

  /// A new ticket: its reference and the hours «فريق زون» answers within.
  static Future<(String, int?)> open(Map<String, Object?> body, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final r = (await Api.client!.post('support-tickets', body, operation)).map;
    return ('${r['reference'] ?? ''}', r['response_hours'] is int ? r['response_hours']! as int : null);
  }

  /// His answer: the thread as it now is.
  static Future<SupportTicket> reply(String reference, String text, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('support-tickets/${Uri.encodeComponent(reference)}/messages', <String, Object?>{'body': text}, operation);
    return SupportTicket.fromJson(r.map);
  }
}
