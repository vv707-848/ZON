// Broker CRM: clients, owners and their notes.

import 'package:zon/data/models/types.dart';

/// A follow-up note on a client/owner; [kind] is تفكير / قبول / رفض.
class Note {
  const Note({required this.id, required this.kind, required this.text});

  final String id;
  final String kind;
  final String text;

  Map<String, Object?> toJson() => <String, Object?>{'id': id, 'kind': kind, 'text': text};

  factory Note.fromJson(Map<String, Object?> j) =>
      Note(id: j['id'] as String? ?? '', kind: j['kind'] as String? ?? '', text: j['text'] as String? ?? '');
}

/// A broker's client (CRM).
class Client {
  const Client({
    required this.id,
    required this.name,
    required this.phone,
    required this.city,
    required this.hood,
    required this.types,
    required this.budget,
    required this.notes,
    this.notesCount,
    this.purpose,
    this.area,
    this.rooms,
    this.description,
  });

  final String id;
  final String name;
  final String phone;
  final String city;
  final String hood;
  final String types;
  final String budget;
  final List<Note> notes;

  /// How many notes the server keeps for him (his notes come apart); null without a server.
  final int? notesCount;
  final Purpose? purpose;
  final String? area;
  final String? rooms;
  final String? description;

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'name': name,
    'phone': phone,
    'city': city,
    'hood': hood,
    'types': types,
    'budget': budget,
    'notes': notes.map((n) => n.toJson()).toList(),
    if (purpose != null) 'purpose': purpose,
    if (area != null) 'area': area,
    if (rooms != null) 'rooms': rooms,
    if (description != null) 'description': description,
  };

  factory Client.fromJson(Map<String, Object?> j) => Client(
    id: j['id'] as String? ?? '',
    name: j['name'] as String? ?? '',
    phone: j['phone'] as String? ?? '',
    city: j['city'] as String? ?? '',
    hood: j['hood'] as String? ?? '',
    types: j['types'] as String? ?? '',
    budget: j['budget'] as String? ?? '',
    notes: (j['notes'] as List?)?.map((e) => Note.fromJson((e as Map).cast<String, Object?>())).toList() ?? const <Note>[],
    purpose: j['purpose'] as String?,
    area: j['area'] as String?,
    rooms: j['rooms'] as String?,
    description: j['description'] as String?,
  );

  Client withNotes(List<Note> next) => Client(
    id: id,
    name: name,
    phone: phone,
    city: city,
    hood: hood,
    types: types,
    budget: budget,
    notes: next,
    purpose: purpose,
    area: area,
    rooms: rooms,
    description: description,
  );
}

/// A property owner managed by a broker.
class Owner {
  const Owner({required this.id, required this.name, required this.phone, required this.listingTitle, this.listingId, required this.notes});

  final String id;
  final String name;
  final String phone;
  final String listingTitle;
  final String? listingId;
  final List<Note> notes;

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'name': name,
    'phone': phone,
    'listingTitle': listingTitle,
    if (listingId != null) 'listingId': listingId,
    'notes': notes.map((n) => n.toJson()).toList(),
  };

  factory Owner.fromJson(Map<String, Object?> j) => Owner(
    id: j['id'] as String? ?? '',
    name: j['name'] as String? ?? '',
    phone: j['phone'] as String? ?? '',
    listingTitle: j['listingTitle'] as String? ?? '',
    listingId: j['listingId'] as String?,
    notes: (j['notes'] as List?)?.map((e) => Note.fromJson((e as Map).cast<String, Object?>())).toList() ?? const <Note>[],
  );

  Owner withNotes(List<Note> next) => Owner(id: id, name: name, phone: phone, listingTitle: listingTitle, listingId: listingId, notes: next);
}
