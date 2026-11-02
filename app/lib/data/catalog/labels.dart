// Arabic labels for the stored codes (roles, purposes, delivery, land use…)
// and which account services each role can see.

import 'package:zon/core/utils/format.dart';
import 'package:zon/data/models/models.dart';

/// Phone numbers shown for developer projects without their own numbers.
const List<String> kProjectPhones = <String>['0114012200', '0551234001', '920003301'];

/// Listing source (who published it), as shown in the home filter.
const Map<String, String> kSourceLabel = <String, String>{'broker': 'الوسطاء', 'owner': 'ملاك أفراد', 'seeker': 'باحثون', 'developer': 'مطورين'};

/// Account role names.
const Map<String, String> kRoleLabel = <String, String>{'broker': 'وسيط', 'owner': 'مالك', 'seeker': 'باحث', 'developer': 'مطوّر عقاري'};

/// Which services of the account page each role can open
/// (e.g. a seeker has no clients / owners / inventory).
const Map<String, List<Role>> kSvcRoles = <String, List<Role>>{
  'matching': <Role>['seeker', 'developer'],
  'search': <Role>['seeker', 'broker', 'owner', 'developer'],
  'clients': <Role>['broker', 'developer'],
  'owners': <Role>['broker'],
  'inventory': <Role>['broker', 'developer'],
  'tasks': <Role>['seeker', 'broker', 'owner', 'developer'],
  'requests': <Role>['seeker', 'broker', 'owner', 'developer'],
  'listings': <Role>['broker', 'owner', 'developer'],
  'settings': <Role>['seeker', 'broker', 'owner', 'developer'],
  'plans': <Role>['seeker', 'broker', 'owner'],
  'people': <Role>['seeker', 'broker', 'owner', 'developer'],
};

/// Project delivery status.
const Map<String, String> kDeliveryLabel = <String, String>{'ready': 'جاهز', 'offplan': 'على الخارطة', 'soon': 'على الخارطة'};

/// Land use (land listings and land projects).
const Map<String, String> kLandUseLabel = <String, String>{
  'residential': 'سكني',
  'commercial': 'تجاري',
  'industrial': 'صناعي',
  'mixed': 'تجاري سكني',
};

/// Listing purpose.
const Map<Purpose, String> kPurposeLabel = <Purpose, String>{'sale': 'للبيع', 'rent': 'للإيجار السنوي'};

/// Floor of a unit inside a building (apartments, studios, offices).
const Map<FloorLevel, String> kFloorLabel = <FloorLevel, String>{
  'ground': 'الأرضي',
  'first': 'الأول',
  'second': 'الثاني',
  'annex': 'الملحق',
  'number': 'رقم',
};

/// Readable floor: "الأرضي", "الأول"… or "الدور 7" when a number is given.
String floorLabel(FloorLevel? level, num? n) {
  if (level == 'ground') return 'الأرضي';
  if (level == 'annex') return 'الملحق';
  if (level == 'first') return 'الأول';
  if (level == 'second') return 'الثاني';
  if (level == 'number' || (n != null && n > 0)) return n != null && n > 0 ? 'الدور ${plainNumber(n)}' : 'رقم';
  return kFloorLabel[level ?? 'ground'] ?? 'الأرضي';
}

/// Who may rent (families / singles).
const Map<Occupancy, String> kOccupancyLabel = <Occupancy, String>{'families': 'عوائل', 'singles': 'عزاب'};
