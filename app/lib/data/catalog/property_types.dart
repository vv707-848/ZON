// Property types of زون — the single source of truth.
//
// Every list, filter, form and label in the app reads the types from here, so
// adding or renaming a type is a change in this file only:
//   1. add the key to [kPropertyTypes] (the order is the order users see),
//   2. add its label to [kTypeLabel] and [kTypePlural],
//   3. choose its specification template in [specsForType],
//   4. give it a default photo in [kTypeImage].
//
// Final list (2026-10): شقة، استوديو، دور مستقل، فيلا، بيت شعبي، استراحة،
// أرض، عمارة، محل، مكتب.

import 'package:zon/data/models/models.dart';

/// All property types, in display order.
const List<PType> kPropertyTypes = <PType>[
  'apartment', // شقة
  'studio', // استوديو
  'floor', // دور مستقل — a whole floor with its own entrance (not a flat in a building)
  'villa', // فيلا
  'house', // بيت شعبي — traditional single-family house
  'resthouse', // استراحة — rest house / leisure compound
  'land', // أرض
  'building', // عمارة
  'shop', // محل
  'office', // مكتب
];

/// Singular label of each type.
const Map<PType, String> kTypeLabel = <PType, String>{
  'apartment': 'شقة',
  'studio': 'استوديو',
  'floor': 'دور مستقل',
  'villa': 'فيلا',
  'house': 'بيت شعبي',
  'resthouse': 'استراحة',
  'land': 'أرض',
  'building': 'عمارة',
  'shop': 'محل',
  'office': 'مكتب',
};

/// Plural label (used for projects: "وحدات شقق", "النوع: فلل").
const Map<PType, String> kTypePlural = <PType, String>{
  'apartment': 'شقق',
  'studio': 'استوديوهات',
  'floor': 'أدوار مستقلة',
  'villa': 'فلل',
  'house': 'بيوت شعبية',
  'resthouse': 'استراحات',
  'land': 'أراضي',
  'building': 'عمائر',
  'shop': 'محلات',
  'office': 'مكاتب',
};

/// Types a developer project can be made of (its main type).
const List<PType> kProjectTypes = <PType>['apartment', 'studio', 'floor', 'villa', 'house', 'resthouse', 'land', 'building'];

/// Types a single unit inside a project can have.
const List<PType> kProjectUnitTypes = <PType>['apartment', 'studio', 'floor', 'villa', 'house', 'resthouse', 'land'];

/// Default photo of a new listing/unit until the user adds real photos.
const Map<PType, String> kTypeImage = <PType, String>{
  'apartment': '/listings/apt-1.jpg',
  'studio': '/listings/studio-1.jpg',
  'floor': '/listings/floor-1.jpg',
  'villa': '/listings/villa-1.jpg',
  'house': '/listings/house-1.jpg',
  'resthouse': '/listings/resthouse-1.jpg',
  'land': '/listings/land-1.jpg',
  'building': '/listings/tower-1.jpg',
  'shop': '/listings/shop-1.jpg',
  'office': '/listings/office-1.jpg',
};

/// Default photo for [type] (falls back to the apartment photo).
String typeImage(PType? type) => kTypeImage[type] ?? kTypeImage['apartment']!;

/// Which specification fields a type has (forms + detail pages).
///
/// Templates:
/// * **Standalone home** — villa, independent floor (دور مستقل), traditional
///   house (بيت شعبي), rest house (استراحة): land area, built area, rooms /
///   halls / bathrooms, age, and families/singles when rented.
/// * **Unit in a building** — apartment, studio, office: built area, floor
///   level, age, rooms (not for studios), families/singles (not for offices).
/// * **Land** — land area and land use.
/// * **Building** — land + built area, number of floors, age, annual income.
/// * **Shop** — built area and age.
SpecFlags specsForType(PType t) {
  switch (t) {
    case 'villa':
    case 'floor':
    case 'house':
    case 'resthouse':
      return const SpecFlags(
        land: true,
        built: true,
        rooms: true,
        landUse: false,
        floorLevel: false,
        floors: false,
        age: true,
        occupancy: true,
        income: false,
      );
    case 'land':
      return const SpecFlags(
        land: true,
        built: false,
        rooms: false,
        landUse: true,
        floorLevel: false,
        floors: false,
        age: false,
        occupancy: false,
        income: false,
      );
    case 'building':
      return const SpecFlags(
        land: true,
        built: true,
        rooms: false,
        landUse: false,
        floorLevel: false,
        floors: true,
        age: true,
        occupancy: false,
        income: true,
      );
    case 'shop':
      return const SpecFlags(
        land: false,
        built: true,
        rooms: false,
        landUse: false,
        floorLevel: false,
        floors: false,
        age: true,
        occupancy: false,
        income: false,
      );
    default: // apartment, studio, office
      return SpecFlags(
        land: false,
        built: true,
        rooms: t != 'studio',
        landUse: false,
        floorLevel: true,
        floors: false,
        age: true,
        occupancy: t != 'office',
        income: false,
      );
  }
}

/// The subset of [SpecFlags] that a project unit uses.
class UnitSpecs {
  const UnitSpecs({required this.land, required this.built, required this.rooms, required this.landUse});

  final bool land, built, rooms, landUse;
}

UnitSpecs specsForUnit(PType t) {
  final s = specsForType(t);
  return UnitSpecs(land: s.land, built: s.built, rooms: s.rooms, landUse: s.landUse);
}
