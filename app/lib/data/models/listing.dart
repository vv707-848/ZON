// Listings (single properties and projects) and their units.

import 'package:zon/data/models/types.dart';

/// Which specification fields a property type uses (see `specsForType`).
class SpecFlags {
  const SpecFlags({
    required this.land,
    required this.built,
    required this.rooms,
    required this.landUse,
    required this.floorLevel,
    required this.floors,
    required this.age,
    required this.occupancy,
    required this.income,
  });

  /// land area · built area · rooms/halls/baths · land use · floor level ·
  /// number of floors · age · families/singles · annual income.
  final bool land, built, rooms, landUse, floorLevel, floors, age, occupancy, income;
}

/// One unit model inside a developer project (e.g. "نموذج B1").
class UnitModel {
  const UnitModel({
    required this.id,
    required this.name,
    required this.rooms,
    required this.baths,
    required this.halls,
    required this.area,
    required this.price,
    required this.sold,
    required this.image,
    required this.description,
    this.propertyType,
    this.landUse,
    this.landArea,
    this.builtArea,
    this.paymentMethod,
    this.license,
    this.licenseVerified,
  });

  final String id;
  final String name;
  final double? rooms;
  final double? baths;
  final double? halls;
  final double? area;
  final double price;
  final bool sold;
  final String image;
  final String description;
  final PType? propertyType;
  final String? landUse;
  final double? landArea;
  final double? builtArea;
  final String? paymentMethod;

  /// Advertising licence number of THIS unit (ready projects). Each unit has
  /// its own number and its own verification; a project-level number is never
  /// copied onto the units.
  final String? license;

  /// Whether [license] passed the check (format now; REGA when connected).
  final bool? licenseVerified;

  /// The licence to show on the unit page: only when the project is ready
  /// and this unit has a verified number of its own (off-plan → none).
  String? shownLicense({required bool projectReady}) {
    final n = license?.trim() ?? '';
    if (!projectReady || n.isEmpty || licenseVerified != true) return null;
    return n;
  }

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'name': name,
    'rooms': rooms,
    'baths': baths,
    'halls': halls,
    'area': area,
    'price': price,
    'sold': sold,
    'image': image,
    'description': description,
    if (propertyType != null) 'propertyType': propertyType,
    if (landUse != null) 'landUse': landUse,
    if (landArea != null) 'landArea': landArea,
    if (builtArea != null) 'builtArea': builtArea,
    if (paymentMethod != null) 'paymentMethod': paymentMethod,
    if (license != null) 'license': license,
    if (licenseVerified != null) 'licenseVerified': licenseVerified,
  };

  factory UnitModel.fromJson(Map<String, Object?> j) => UnitModel(
    id: j['id'] as String? ?? '',
    name: j['name'] as String? ?? '',
    rooms: numOrNull(j['rooms']),
    baths: numOrNull(j['baths']),
    halls: numOrNull(j['halls']),
    area: numOrNull(j['area']),
    price: numOrNull(j['price']) ?? 0,
    sold: j['sold'] == true,
    image: j['image'] as String? ?? '',
    description: j['description'] as String? ?? '',
    propertyType: j['propertyType'] == null ? null : normalizeType(j['propertyType']! as String),
    landUse: j['landUse'] as String?,
    landArea: numOrNull(j['landArea']),
    builtArea: numOrNull(j['builtArea']),
    paymentMethod: j['paymentMethod'] as String?,
    license: j['license'] as String?,
    licenseVerified: j['licenseVerified'] as bool?,
  );

  /// A copy with the JSON fields of [patch] replaced.
  UnitModel copyWith(Map<String, Object?> patch) => UnitModel.fromJson(<String, Object?>{...toJson(), ...patch});
}

/// An offer: a single property (`kind == 'unit'`) or a developer project
/// with [units] (`kind == 'project'`).
class Listing {
  const Listing({
    required this.id,
    required this.kind,
    required this.title,
    required this.purpose,
    required this.city,
    required this.neighborhood,
    required this.propertyType,
    required this.source,
    required this.price,
    required this.rooms,
    required this.baths,
    required this.area,
    required this.images,
    required this.description,
    required this.advertiser,
    required this.advertiserRole,
    required this.license,
    this.available,
    this.sold,
    this.isPremium,
    this.delivery,
    this.landUse,
    this.units,
    this.lat,
    this.lng,
    this.annualIncome,
    this.floors,
    this.halls,
    this.negotiable,
    this.hasDeed,
    this.bankOk,
    this.occupancy,
    this.floorLevel,
    this.floorNumber,
    this.ageYears,
    this.landArea,
    this.builtArea,
    this.mapsLink,
    this.active,
    this.phones,
    this.ownerId,
    this.premiumUntil,
    this.urgentUntil,
    this.unclaimed,
  });

  final String id;
  final Kind kind;
  final String title;
  final Purpose purpose;
  final String city;
  final String neighborhood;
  final PType propertyType;
  final Role source;
  final double price;
  final double? rooms;
  final double? baths;
  final double? area;
  final List<String> images;
  final String description;
  final String advertiser;
  final Role advertiserRole;
  final double? available;

  /// Number of sold units (projects) or a 0/1 "sold" flag (unit listings).
  final double? sold;
  final String license;
  final bool? isPremium;
  final String? delivery;
  final String? landUse;
  final List<UnitModel>? units;
  final double? lat;
  final double? lng;
  final double? annualIncome;
  final double? floors;
  final double? halls;
  final bool? negotiable;
  final bool? hasDeed;
  final bool? bankOk;
  final Occupancy? occupancy;
  final FloorLevel? floorLevel;
  final double? floorNumber;
  final double? ageYears;
  final double? landArea;
  final double? builtArea;
  final String? mapsLink;
  final bool? active;
  final List<String>? phones;

  /// Account id of the publisher (`acc-…`). Only this account may edit,
  /// boost or hide the listing. Sample listings have none (nobody owns them).
  final String? ownerId;

  /// End of a paid / quota featuring («مميز»), ISO-8601. Sample listings use
  /// [isPremium] without an end.
  final String? premiumUntil;

  /// End of an «عاجل» boost, ISO-8601.
  final String? urgentUntil;

  /// A listing saved by version 1.1 (no owner then) whose author could not
  /// be proven on upgrade: it stays public and unchanged, nobody may edit it,
  /// and it is never given to an account automatically (QA F01).
  final bool? unclaimed;

  /// Whether the listing is shown as «مميز» at [now].
  bool featuredAt(DateTime now) {
    final until = premiumUntil == null ? null : DateTime.tryParse(premiumUntil!);
    if (until != null) return now.isBefore(until);
    return isPremium == true;
  }

  /// Whether the listing is shown as «عاجل» at [now].
  bool urgentAt(DateTime now) {
    final until = urgentUntil == null ? null : DateTime.tryParse(urgentUntil!);
    return until != null && now.isBefore(until);
  }

  /// Whether the project counts any sold unit (`sold` is set and not 0).
  bool get soldFlag => (sold ?? 0) != 0;

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'kind': kind,
    'title': title,
    'purpose': purpose,
    'city': city,
    'neighborhood': neighborhood,
    'propertyType': propertyType,
    'source': source,
    'price': price,
    'rooms': rooms,
    'baths': baths,
    'area': area,
    'images': images,
    'description': description,
    'advertiser': advertiser,
    'advertiserRole': advertiserRole,
    'license': license,
    if (available != null) 'available': available,
    if (sold != null) 'sold': sold,
    if (isPremium != null) 'isPremium': isPremium,
    if (delivery != null) 'delivery': delivery,
    if (landUse != null) 'landUse': landUse,
    if (units != null) 'units': units!.map((u) => u.toJson()).toList(),
    if (lat != null) 'lat': lat,
    if (lng != null) 'lng': lng,
    if (annualIncome != null) 'annualIncome': annualIncome,
    if (floors != null) 'floors': floors,
    if (halls != null) 'halls': halls,
    if (negotiable != null) 'negotiable': negotiable,
    if (hasDeed != null) 'hasDeed': hasDeed,
    if (bankOk != null) 'bankOk': bankOk,
    if (occupancy != null) 'occupancy': occupancy,
    if (floorLevel != null) 'floorLevel': floorLevel,
    if (floorNumber != null) 'floorNumber': floorNumber,
    if (ageYears != null) 'ageYears': ageYears,
    if (landArea != null) 'landArea': landArea,
    if (builtArea != null) 'builtArea': builtArea,
    if (mapsLink != null) 'mapsLink': mapsLink,
    if (active != null) 'active': active,
    if (phones != null) 'phones': phones,
    if (ownerId != null) 'ownerId': ownerId,
    if (premiumUntil != null) 'premiumUntil': premiumUntil,
    if (urgentUntil != null) 'urgentUntil': urgentUntil,
    if (unclaimed != null) 'unclaimed': unclaimed,
  };

  factory Listing.fromJson(Map<String, Object?> j) {
    final soldRaw = j['sold'];
    return Listing(
      id: j['id'] as String? ?? '',
      kind: j['kind'] as String? ?? 'unit',
      title: j['title'] as String? ?? '',
      purpose: j['purpose'] as String? ?? 'sale',
      city: j['city'] as String? ?? '',
      neighborhood: j['neighborhood'] as String? ?? '',
      propertyType: normalizeType(j['propertyType'] as String? ?? 'villa'),
      source: j['source'] as String? ?? 'broker',
      price: numOrNull(j['price']) ?? 0,
      rooms: numOrNull(j['rooms']),
      baths: numOrNull(j['baths']),
      area: numOrNull(j['area']),
      images: (j['images'] as List?)?.map((e) => e.toString()).toList() ?? const <String>[],
      description: j['description'] as String? ?? '',
      advertiser: j['advertiser'] as String? ?? '',
      advertiserRole: j['advertiserRole'] as String? ?? 'broker',
      license: j['license'] as String? ?? '',
      available: numOrNull(j['available']),
      sold: soldRaw is bool ? (soldRaw ? 1 : 0) : numOrNull(soldRaw),
      isPremium: j['isPremium'] as bool?,
      delivery: j['delivery'] as String?,
      landUse: j['landUse'] as String?,
      units: (j['units'] as List?)?.map((e) => UnitModel.fromJson((e as Map).cast<String, Object?>())).toList(),
      lat: numOrNull(j['lat']),
      lng: numOrNull(j['lng']),
      annualIncome: numOrNull(j['annualIncome']),
      floors: numOrNull(j['floors']),
      halls: numOrNull(j['halls']),
      negotiable: j['negotiable'] as bool?,
      hasDeed: j['hasDeed'] as bool?,
      bankOk: j['bankOk'] as bool?,
      occupancy: j['occupancy'] as String?,
      floorLevel: j['floorLevel'] as String?,
      floorNumber: numOrNull(j['floorNumber']),
      ageYears: numOrNull(j['ageYears']),
      landArea: numOrNull(j['landArea']),
      builtArea: numOrNull(j['builtArea']),
      mapsLink: j['mapsLink'] as String?,
      active: j['active'] as bool?,
      phones: (j['phones'] as List?)?.map((e) => e.toString()).toList(),
      ownerId: j['ownerId'] as String?,
      premiumUntil: j['premiumUntil'] as String?,
      urgentUntil: j['urgentUntil'] as String?,
      unclaimed: j['unclaimed'] as bool?,
    );
  }

  /// A copy with the JSON fields of [patch] replaced ([kUndefined] removes a field).
  Listing merged(Map<String, Object?> patch) =>
      Listing.fromJson((jsonClean(<String, Object?>{...toJson(), ...patch}) as Map).cast<String, Object?>());
}
