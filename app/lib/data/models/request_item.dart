// Search requests published by seekers, brokers, owners and developers.

import 'package:zon/data/models/types.dart';

/// A search request ("طلب"): what someone wants to buy or rent.
class RequestItem {
  const RequestItem({
    required this.id,
    required this.purpose,
    required this.city,
    required this.neighborhoods,
    required this.types,
    required this.priceMin,
    required this.priceMax,
    required this.areaMin,
    required this.areaMax,
    required this.roomsMin,
    required this.roomsMax,
    required this.description,
    required this.author,
    required this.role,
    this.urgent,
    this.ownerId,
    this.authorUsername,
    this.createdAt,
    this.unclaimed,
    this.closed,
    this.canAddToClients,
  });

  final String id;
  final Purpose purpose;
  final String city;
  final List<String> neighborhoods;
  final List<PType> types;
  final double priceMin;
  final double priceMax;
  final double areaMin;
  final double areaMax;
  final double roomsMin;
  final double roomsMax;
  final String description;
  final String author;
  final Role role;

  /// «عاجل» — set only after the quota or the payment succeeded.
  final bool? urgent;

  /// Account id of the author (`acc-…`); sample requests have none.
  final String? ownerId;

  /// The author's username on the server: a conversation with him.
  final String? authorUsername;

  /// ISO-8601 time of publishing (user requests).
  final String? createdAt;

  /// A request saved by version 1.1 whose author could not be proven on
  /// upgrade: public and unchanged, owned by nobody (QA F01).
  final bool? unclaimed;

  /// Closed by its author: off the board, still in «طلباتي» (a request of the server).
  final bool? closed;

  /// The viewer may add it to his clients («أضف إلى إدارة العملاء»), as the server says.
  final bool? canAddToClients;

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'purpose': purpose,
    'city': city,
    'neighborhoods': neighborhoods,
    'types': types,
    'priceMin': priceMin,
    'priceMax': priceMax,
    'areaMin': areaMin,
    'areaMax': areaMax,
    'roomsMin': roomsMin,
    'roomsMax': roomsMax,
    'description': description,
    'author': author,
    'role': role,
    if (urgent != null) 'urgent': urgent,
    if (ownerId != null) 'ownerId': ownerId,
    if (authorUsername != null) 'authorUsername': authorUsername,
    if (createdAt != null) 'createdAt': createdAt,
    if (unclaimed != null) 'unclaimed': unclaimed,
    if (closed != null) 'closed': closed,
    if (canAddToClients != null) 'canAddToClients': canAddToClients,
  };

  factory RequestItem.fromJson(Map<String, Object?> j) => RequestItem(
    id: j['id'] as String? ?? '',
    purpose: j['purpose'] as String? ?? 'sale',
    city: j['city'] as String? ?? '',
    neighborhoods: (j['neighborhoods'] as List?)?.map((e) => e.toString()).toList() ?? const <String>[],
    types: (j['types'] as List?)?.map((e) => normalizeType(e.toString())).toSet().toList() ?? const <String>[],
    priceMin: numOrNull(j['priceMin']) ?? 0,
    priceMax: numOrNull(j['priceMax']) ?? 0,
    areaMin: numOrNull(j['areaMin']) ?? 0,
    areaMax: numOrNull(j['areaMax']) ?? 0,
    roomsMin: numOrNull(j['roomsMin']) ?? 0,
    roomsMax: numOrNull(j['roomsMax']) ?? 0,
    description: j['description'] as String? ?? '',
    author: j['author'] as String? ?? '',
    role: j['role'] as String? ?? 'seeker',
    urgent: j['urgent'] as bool?,
    ownerId: j['ownerId'] as String?,
    authorUsername: j['authorUsername'] as String?,
    createdAt: j['createdAt'] as String?,
    unclaimed: j['unclaimed'] as bool?,
    closed: j['closed'] as bool?,
    canAddToClients: j['canAddToClients'] as bool?,
  );

  /// A copy with the JSON fields of [patch] replaced.
  RequestItem merged(Map<String, Object?> patch) =>
      RequestItem.fromJson((jsonClean(<String, Object?>{...toJson(), ...patch}) as Map).cast<String, Object?>());
}
