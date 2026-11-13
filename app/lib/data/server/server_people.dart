// Accounts as the server tells them (docs/api.md «الملف العام» · «المتابعة» · «البلاغات»):
//
//   GET    /customers/{username}             an advertiser's public page: identity, stars, follows, live listings
//   GET    /customers/{username}/listings    his live listings, 20 a page
//   POST   /customers/{username}/follow      follow (DELETE: stop)
//   POST   /customers/{username}/rating      his stars, one per pair, changeable
//   POST   /customers/{username}/block       block (DELETE: undo); the blocked side is never told
//   GET    /me/followers · /me/following     the accounts that follow him · that he follows
//   POST   /reports                          «بلاغ» on a listing, a project, a request, an account or a message
//   POST   /favorites · DELETE /favorites/{type}/{id} · GET /favorites   «إعجاب» on a listing or a project, and his list
//
// A seeker has no public page: the server answers 404, as for an account that blocked him. Nothing goes out once
// another account is signed in (Store.stillAccount).

import 'package:zon/core/net/api.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/store.dart';

/// An advertiser's public page.
class Profile {
  const Profile({
    required this.id,
    required this.username,
    required this.name,
    required this.role,
    this.falVerified = false,
    this.falNumber,
    this.city,
    this.memberSince,
    this.nafathVerified = false,
    this.ratingAverage = 0,
    this.ratingCount = 0,
    this.myRating,
    this.followers = 0,
    this.following = 0,
    this.listings = 0,
    this.isFollowing = false,
  });

  factory Profile.fromJson(Map<String, Object?> j) {
    final rating = j['rating'] is Map ? (j['rating']! as Map).cast<String, Object?>() : const <String, Object?>{};
    int count(Object? v) => v is int ? v : 0;
    return Profile(
      id: count(j['id']),
      username: '${j['username'] ?? ''}',
      name: '${j['name'] ?? ''}',
      role: '${j['role'] ?? 'broker'}',
      falVerified: j['fal_verified'] == true,
      falNumber: j['fal_number'] is String ? j['fal_number']! as String : null,
      city: j['city'] is String ? j['city']! as String : null,
      memberSince: DateTime.tryParse('${j['member_since'] ?? ''}'),
      nafathVerified: j['nafath_verified'] == true,
      ratingAverage: rating['average'] is num ? (rating['average']! as num).toDouble() : 0,
      ratingCount: count(rating['count']),
      myRating: j['my_rating'] is int ? j['my_rating']! as int : null,
      followers: count(j['followers_count']),
      following: count(j['following_count']),
      listings: count(j['listings_count']),
      isFollowing: j['is_following'] == true,
    );
  }

  /// The account on the server (what a report names).
  final int id;
  final String username;
  final String name;
  final Role role;
  final bool falVerified;
  final String? falNumber;
  final String? city;
  final DateTime? memberSince;
  final bool nafathVerified;
  final double ratingAverage;
  final int ratingCount;
  final int? myRating;
  final int followers;
  final int following;
  final int listings;
  final bool isFollowing;

  Profile copyWith({bool? isFollowing, int? followers, int? myRating, int? ratingCount, double? ratingAverage}) => Profile(
    id: id,
    username: username,
    name: name,
    role: role,
    falVerified: falVerified,
    falNumber: falNumber,
    city: city,
    memberSince: memberSince,
    nafathVerified: nafathVerified,
    ratingAverage: ratingAverage ?? this.ratingAverage,
    ratingCount: ratingCount ?? this.ratingCount,
    myRating: myRating ?? this.myRating,
    followers: followers ?? this.followers,
    following: following,
    listings: listings,
    isFollowing: isFollowing ?? this.isFollowing,
  );
}

/// A page of his listings, and whether more remain.
class ProfileListings {
  const ProfileListings(this.items, {required this.hasMore});

  final List<Listing> items;
  final bool hasMore;
}

abstract final class ServerPeople {
  static String _at(String username) => 'customers/${Uri.encodeComponent(username)}';

  static Future<Profile> profile(String username) async => Profile.fromJson((await Api.client!.get(_at(username))).map);

  static Future<ProfileListings> listings(String username, {int page = 1}) async {
    final r = await Api.client!.get('${_at(username)}/listings', query: <String, String>{'page': '$page'});
    final meta = r.meta['page'];
    final last = meta is Map && meta['last'] is int ? meta['last']! as int : page;
    return ProfileListings(<Listing>[for (final row in r.list) ServerListings.fromListing(row)], hasMore: page < last);
  }

  /// Follows [username] ([on]) or stops; what the server says he does now.
  static Future<bool> follow(String username, {required bool on, required int epoch}) async {
    store.stillAccount(epoch);
    final r = on ? await Api.client!.post('${_at(username)}/follow') : await Api.client!.delete('${_at(username)}/follow');
    return r.map['following'] == true;
  }

  /// His [stars] (1–5) for [username]; the stars the server kept.
  static Future<int> rate(String username, int stars, {required int epoch}) async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('${_at(username)}/rating', <String, Object?>{'stars': stars});
    return r.map['stars'] is int ? r.map['stars']! as int : stars;
  }

  static Future<void> block(String username, {required bool on, required int epoch}) async {
    store.stillAccount(epoch);
    if (on) {
      await Api.client!.post('${_at(username)}/block');
    } else {
      await Api.client!.delete('${_at(username)}/block');
    }
  }

  /// The accounts that follow him ([followers]) or that he follows.
  static Future<List<Person>> people({required bool followers}) async => <Person>[
    for (final row in (await Api.client!.get(followers ? 'me/followers' : 'me/following')).list)
      Person(
        id: '${row['username'] ?? ''}',
        n: '${row['name'] ?? ''}',
        r: kRoleLabel['${row['role'] ?? ''}'] ?? '',
        role: '${row['role'] ?? 'broker'}',
      ),
  ];

  /// «بلاغ» on [target] — a listing, project or request by its app id (`L12` · `P3` · `R7`), or an account
  /// ([account], its id on the server) — with what he wrote; one per reporter and target, so again is harmless.
  static Future<void> report(String details, {String? target, int? account, required int epoch}) async {
    final (type, id) = account != null
        ? ('customer', account)
        : switch (target?.substring(0, 1)) {
            'L' => ('listing', int.tryParse(target!.substring(1))),
            'P' => ('project', int.tryParse(target!.substring(1))),
            'R' => ('request', int.tryParse(target!.substring(1))),
            _ => ('', null),
          };
    if (id == null) throw ArgumentError.value(target, 'target');
    store.stillAccount(epoch);
    await Api.client!.post('reports', <String, Object?>{'target_type': type, 'target_id': id, 'details': details.trim()});
  }
}

/// «إعجاب» on the server: one per account and offer.
abstract final class ServerFavorites {
  /// Likes [appId] (`L12` · `P3`) or stops ([on]); whether it is liked now, as the server says.
  static Future<bool> set(String appId, {required bool on, required int epoch}) async {
    final type = appId.startsWith('P') ? 'project' : 'listing';
    final id = int.parse(ServerListings.rawId(appId));
    store.stillAccount(epoch);
    final r = on ? await Api.client!.post('favorites', <String, Object?>{'type': type, 'id': id}) : await Api.client!.delete('favorites/$type/$id');
    return r.map['favorite'] == true;
  }

  /// What he liked that is still public: listings, then projects.
  static Future<List<Listing>> list() async {
    final r = (await Api.client!.get('favorites')).map;
    List<Map<String, Object?>> rows(Object? v) => v is List
        ? <Map<String, Object?>>[
            for (final x in v)
              if (x is Map) x.cast<String, Object?>(),
          ]
        : const <Map<String, Object?>>[];
    return <Listing>[
      for (final row in rows(r['listings'])) ServerListings.fromListing(row),
      for (final row in rows(r['projects'])) ServerListings.fromProject(row),
    ];
  }
}
