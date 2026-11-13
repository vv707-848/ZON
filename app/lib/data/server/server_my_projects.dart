// A developer's projects on the server (docs/api.md «المشاريع»), in the app's own model (Listing, UnitModel):
//
//   POST   /projects                            a project (draft): its text goes to the wording
//   POST   /projects/{id}/units                 a unit model with its count; its text goes to the wording too
//   PATCH  /projects/{id} · /units/{u}          his draft again, after a send that stopped midway; DELETE a model
//   POST   /projects/{id}/units/{u}/licence     a ready project's licence for that model, asked of REGA
//   GET    /projects/{id}/review                the wording of the project and of each model, beside his texts
//   POST   /projects/{id}/review                his decision on the project's: approve · edit (his text) · reject (his own)
//   POST   /projects/{id}/units/review          his decision on each model's
//   POST   /projects/{id}/publish               published once every text is decided (and, ready, every model licensed)
//   GET    /projects/mine                       «مشاريعي», every state
//   GET    /projects/{id}                       his project for his editor (his own texts, each model's count)
//   POST   /projects/{id}/units/{u}/sold        units of a model sold, at the price he says
//   POST   /projects/{id}/pause · /resume       «إخفاء» · «إظهار» (publishing asked again)
//
// A sequence begun under one account sends nothing once another is signed in (Store.stillAccount).

import 'package:zon/core/net/api.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_my_listings.dart';
import 'package:zon/data/server/server_pages.dart';
import 'package:zon/data/store.dart';

/// One text and its wording, as GET /projects/{id}/review gives it.
class TextWording {
  const TextWording({required this.textReview, required this.raw, this.suggested, this.unitId, this.name = ''});

  factory TextWording.fromJson(Map<String, Object?> j) => TextWording(
    textReview: j['text_review'] is String ? j['text_review']! as String : '',
    raw: j['raw'] is String ? j['raw']! as String : '',
    suggested: j['suggested'] is String && (j['suggested']! as String).isNotEmpty ? j['suggested']! as String : null,
    unitId: j['id'] is int ? j['id']! as int : null,
    name: j['model_name'] is String ? j['model_name']! as String : '',
  );

  /// `pending` while it is prepared; `ready` when a suggestion waits for him; `auto` when the AI kept his own
  /// text; decided otherwise (`approved` · `edited` · `rejected`).
  final String textReview;
  final String raw;
  final String? suggested;

  /// A model's id on the server (null for the project's own text).
  final int? unitId;
  final String name;

  bool get preparing => textReview == 'pending';
  bool get waiting => textReview == 'ready' && suggested != null;
}

/// The wording of a project and of its models.
class ProjectWording {
  const ProjectWording({required this.project, required this.units, this.status = 'draft'});

  /// The project's status: a draft is published from its review; a published one keeps its state.
  final String status;
  final TextWording project;
  final List<TextWording> units;

  bool get preparing => project.preparing || units.any((u) => u.preparing);
}

/// A model of his project as his editor reads it (GET /projects/{id}): his own text, its count available.
class MyUnit {
  const MyUnit({
    required this.id,
    required this.name,
    required this.type,
    required this.price,
    required this.available,
    required this.text,
    required this.textReview,
    this.licence,
  });

  factory MyUnit.fromJson(Map<String, Object?> j) {
    final licence = j['licence'] is Map ? (j['licence']! as Map).cast<String, Object?>() : null;
    return MyUnit(
      id: j['id'] is int ? j['id']! as int : 0,
      name: '${j['model_name'] ?? ''}',
      type: '${j['property_type'] ?? ''}',
      price: j['price'] is num ? (j['price']! as num).toDouble() : double.tryParse('${j['price']}'),
      available: j['available_count'] is int ? j['available_count']! as int : 0,
      text: '${j['desc_raw'] ?? j['description'] ?? ''}',
      textReview: '${j['text_review'] ?? ''}',
      licence: licence == null ? null : '${licence['number']}',
    );
  }

  final int id;
  final String name;
  final String type;
  final double? price;
  final int available;

  /// His own text (not the wording decided for the public).
  final String text;
  final String textReview;
  final String? licence;
}

/// His project as his editor reads it.
class MyProject {
  const MyProject({
    required this.id,
    required this.status,
    required this.title,
    required this.text,
    required this.mapsLink,
    required this.delivery,
    required this.propertyType,
    required this.textReview,
    required this.units,
  });

  factory MyProject.fromJson(Map<String, Object?> j) => MyProject(
    id: j['id'] is int ? j['id']! as int : 0,
    status: '${j['status'] ?? ''}',
    title: '${j['title'] ?? ''}',
    text: '${j['desc_raw'] ?? j['description'] ?? ''}',
    mapsLink: j['maps_link'] is String ? j['maps_link']! as String : '',
    delivery: '${j['delivery'] ?? ''}',
    propertyType: '${j['property_type'] ?? ''}',
    textReview: '${j['text_review'] ?? ''}',
    units: <MyUnit>[
      if (j['units'] is List)
        for (final u in j['units']! as List)
          if (u is Map) MyUnit.fromJson(u.cast<String, Object?>()),
    ],
  );

  final int id;
  final String status;
  final String title;
  final String text;
  final String mapsLink;
  final String delivery;
  final String propertyType;
  final String textReview;
  final List<MyUnit> units;

  bool get ready => delivery == 'ready';

  /// A text waits for his decision (after an edit).
  bool get wordingWaits => textReview == 'ready' || units.any((u) => u.textReview == 'ready');
}

/// His decision on a text.
enum TextDecision { approve, edit, reject }

abstract final class ServerMyProjects {
  /// «مشاريعي»: every project of his, every page, with its status.
  static Future<List<Listing>> mine() async {
    final rows = <Listing>[];
    for (var page = 1; page <= 50; page++) {
      final r = await Api.client!.get('projects/mine', query: <String, String>{'page': '$page'});
      rows.addAll(<Listing>[for (final row in r.list) ServerListings.fromProject(row)]);
      if (ServerPage.paging(r.meta, page).last <= page) break;
    }
    return rows;
  }

  /// A new project (a draft): [body] as [form] writes it. [operation] is the same on every try of one send.
  static Future<int> create(Map<String, Object?> body, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final id = (await Api.client!.post('projects', body, operation)).map['id'];
    if (id is! int) throw StateError('the server gave no project id');
    return id;
  }

  /// A unit model of project [project]: [body] as [unitForm] writes it.
  static Future<int> addUnit(int project, Map<String, Object?> body, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final id = (await Api.client!.post('projects/$project/units', body, operation)).map['id'];
    if (id is! int) throw StateError('the server gave no unit id');
    return id;
  }

  /// His draft changed after a send that stopped midway: its fields again.
  static Future<void> update(int project, Map<String, Object?> body, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.patch('projects/$project', body);
  }

  static Future<void> updateUnit(int project, int unit, Map<String, Object?> body, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.patch('projects/$project/units/$unit', body);
  }

  /// A model he took out of his draft after it was sent.
  static Future<void> removeUnit(int project, int unit, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.delete('projects/$project/units/$unit');
  }

  /// A ready project's licence for model [unit], asked of REGA (an ApiError 422 says why it is refused).
  static Future<void> licence(int project, int unit, String number, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.post('projects/$project/units/$unit/licence', <String, Object?>{'licence_number': number});
  }

  /// His project with his own texts and each model's count, for his editor.
  static Future<MyProject> detail(int project) async => MyProject.fromJson((await Api.client!.get('projects/$project')).map);

  /// [count] units of model [unit] sold, at [salePrice] each (the contract's commission is reckoned on it).
  static Future<MyUnit> sell(int project, int unit, {required int count, String? salePrice}) async => MyUnit.fromJson(
    (await Api.client!.post('projects/$project/units/$unit/sold', <String, Object?>{
      'count': count,
      'sale_price': ?salePrice,
    })).map,
  );

  /// «تمييز» of his published project: free while his free ones last, otherwise 402 with the price.
  static Future<void> feature(String appId, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.post('projects/${ServerListings.rawId(appId)}/boost', <String, Object?>{'kind': 'featured'});
  }

  static Future<ProjectWording> review(int project) async {
    final j = (await Api.client!.get('projects/$project/review')).map;
    final units = j['units'];
    return ProjectWording(
      status: j['status'] is String ? j['status']! as String : 'draft',
      project: TextWording.fromJson(j['project'] is Map ? (j['project']! as Map).cast<String, Object?>() : const <String, Object?>{}),
      units: <TextWording>[
        if (units is List)
          for (final u in units)
            if (u is Map) TextWording.fromJson(u.cast<String, Object?>()),
      ],
    );
  }

  static Future<void> decideProject(int project, TextDecision decision, {String? text}) =>
      Api.client!.post('projects/$project/review', <String, Object?>{'decision': decision.name, 'text': ?text});

  /// His decision on each model waiting: unit id → (decision, his text for `edit`).
  static Future<void> decideUnits(int project, Map<int, (TextDecision, String?)> decisions) =>
      Api.client!.post('projects/$project/units/review', <String, Object?>{
        'decisions': <Map<String, Object?>>[
          for (final e in decisions.entries) <String, Object?>{'unit_id': e.key, 'decision': e.value.$1.name, 'text': ?e.value.$2},
        ],
      });

  static Future<Listing> publish(int project) => _act(project, 'publish');
  static Future<Listing> pause(String appId) => _act(int.parse(ServerListings.rawId(appId)), 'pause');
  static Future<Listing> resume(String appId) => _act(int.parse(ServerListings.rawId(appId)), 'resume');

  static Future<Listing> _act(int project, String action) async =>
      ServerListings.fromProject((await Api.client!.post('projects/$project/$action')).map);

  /// A project's fields as the server reads them. [totalUnits] is the units of all its models.
  static Map<String, Object?> form({
    required String title,
    required String city,
    required String neighborhood,
    required String propertyType,
    required String delivery,
    required String? landUse,
    required String description,
    required String mapsLink,
    required int totalUnits,
    required List<int> mediaIds,
  }) => <String, Object?>{
    'title': title.trim(),
    'city': city,
    'neighborhood': neighborhood,
    'property_type': propertyType,
    'delivery': delivery,
    'land_use': ?landUse,
    'total_units': totalUnits,
    'description': description.trim(),
    'maps_link': mapsLink.trim().isEmpty ? null : mapsLink.trim(),
    ...ServerMyListings.photos(mediaIds),
  };

  /// A model's fields as the server reads them: only those its type uses; what he left empty is sent empty.
  static Map<String, Object?> unitForm(UnitModel u, {required String type, required double? price, required int count, required List<int> mediaIds}) {
    final s = specsForUnit(type);
    num? n(double? v) => v == null || v.isNaN || v == 0 ? null : (v == v.roundToDouble() ? v.toInt() : v);
    int? i(double? v) => v == null || v.isNaN ? null : v.round();
    return <String, Object?>{
      'model_name': u.name.trim(),
      'property_type': type,
      if (s.land) 'area_land': n(u.landArea),
      if (s.built) 'area_built': n(u.builtArea),
      if (s.rooms) ...<String, Object?>{'bedrooms': i(u.rooms), 'bathrooms': i(u.baths), 'halls': i(u.halls)},
      if (s.landUse) 'land_use': u.landUse ?? 'residential',
      'price': price == null ? null : (price == price.roundToDouble() ? price.toInt() : price),
      'available_count': count,
      'description': u.description.trim(),
      'media_ids': mediaIds,
    };
  }
}
