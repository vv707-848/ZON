// Local data store (see the class documentation below).

import 'dart:convert';

import 'package:flutter/foundation.dart';

import 'package:shared_preferences/shared_preferences.dart';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/services/photo_store.dart';
import 'package:zon/core/simulations.dart';
import 'package:zon/core/utils/input.dart';
import 'package:zon/data/auth/password_hash.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/seed/seed_data.dart';

/// The app's local data store.
///
/// * Everything the user creates (listings, requests, clients, owners, tasks,
///   chats, likes, settings…) is saved on the device with shared_preferences,
///   one JSON value per key (the `_xx` keys below, all `zon.*`).
/// * **Public** data (published listings, published requests, listing edits)
///   is shared by every account on the device — like the server's public
///   tables. Each item keeps the [Session.id] of its author (`ownerId`).
/// * **Private** data (tasks, clients, owners, stock, chats, wish,
///   subscription, quotas, notifications…) is saved per account as
///   `<key>@<accountId>`, so signing in with another account never shows the
///   previous account's data (QA finding ACCESS-03).
/// * The sample data (data/seed) is merged in when reading, so the app is
///   never empty; the user's own items come first.
/// * Reads are synchronous (all values are loaded into memory by [init]);
///   writes are saved in the background and call [notifyListeners], so any
///   `ListenableBuilder(listenable: store)` rebuilds.
///
/// When a server is added, this class (with `data/auth`, `data/billing` and
/// `data/notifications`) is the only place that needs to change: keep the
/// method names and replace the bodies with API calls. The server then
/// enforces ownership and isolation itself (403 for another account's data).
class Store extends ChangeNotifier {
  Store._();

  static final Store instance = Store._();

  SharedPreferences? _prefs;
  final Map<String, String> _mem = <String, String>{};

  /// Loads every saved value into memory and migrates data saved by
  /// version 1.1. Call once before `runApp`.
  static Future<void> init() async {
    final s = instance;
    s._mem.clear();
    s._forgetServerRows();
    s._resetSessionState();
    try {
      s._prefs = await SharedPreferences.getInstance();
      for (final k in s._prefs!.getKeys()) {
        final v = s._prefs!.get(k);
        if (v is String) s._mem[k] = v;
      }
    } catch (_) {
      // Storage unavailable: keep working from memory.
    }
    final legacy = s._migrateLegacy();
    s._migrateOwnership(legacy);
    // photos (QA F05): adopt the old absolute paths whose file still exists,
    // then remove the photo files nothing refers to any more
    s._adoptOldPhotos();
    PhotoStore.sweep(s._mem.values.join('\n'));
  }

  // ---------------------------------------------------------------- raw I/O

  /// Raw access to a saved string (used by the screens' small preferences).
  String? getItem(String key) => _mem[key];

  void setItem(String key, String value) {
    _mem[key] = value;
    _prefs?.setString(key, value);
  }

  void removeItem(String key) {
    _mem.remove(key);
    _prefs?.remove(key);
  }

  T _read<T>(String key, T fallback, T Function(Object? json) decode) {
    final raw = _mem[_k(key)];
    if (raw == null) return fallback;
    try {
      return decode(jsonDecode(raw));
    } catch (_) {
      return fallback;
    }
  }

  void _write(String key, Object? value) {
    _dropCaches();
    setItem(_k(key), jsonEncode(jsonClean(value)));
    notifyListeners();
  }

  void _dropCaches() {
    _listingsCache = null;
    _listingMap = null;
    _inventoryCache = null;
  }

  // ---------------------------------------------------------------- keys
  // public (shared by all accounts on the device)
  static const _lk = 'zon.extra.listings.v1';
  static const _rk = 'zon.extra.requests.v1';
  static const _pk = 'zon.listing.patch.v1';
  static const _accountsKey = 'zon.accounts.v1';
  static const _session = 'zon.session.v1';

  // private (one value per account — see [_k])
  static const _ck = 'zon.crm.v1';
  static const _ok = 'zon.owners.v1';
  static const _tk = 'zon.tasks.v1';
  static const _ch = 'zon.chats.v1';
  static const _ik = 'zon.stock.v1';
  static const _mk = 'zon.msgs.v1';
  static const _fk = 'zon.sem.marks.v1';
  static const _wk = 'zon.wish.v1';
  static const _seenKey = 'zon.wish.seen.v1';
  static const _pingKey = 'zon.radar.ping';
  static const _flk = 'zon.follows.v1';

  /// Subscription value saved by version 1.1 (moved to `data/billing`).
  static const String legacyPlanKey = 'zon.sub.v1';

  /// Monthly test counter of version 1.1 (no longer used).
  static const _legacyBoostKey = 'zon.boost.v1';

  static const Set<String> _privateKeys = <String>{_ck, _ok, _tk, _ch, _ik, _mk, _fk, _wk, _seenKey, _pingKey, _flk, legacyPlanKey};

  /// Storage key of [base]: private keys get the signed-in account's id.
  String _k(String base) => _privateKeys.contains(base) ? scopedKey(base) : base;

  /// `<base>@<accountId>` (`@guest` when nobody is signed in).
  String scopedKey(String base) => '$base@${_accountId ?? 'guest'}';

  // ---------------------------------------------------------------- private JSON (billing, notifications, owner plan)

  /// `<base>@<accountId>` of a given account.
  String keyOf(String base, String accountId) => '$base@$accountId';

  /// A JSON value saved for an account under [key] (null when absent or
  /// unreadable): the signed-in account, or [accountId] — e.g. the account
  /// that started a payment, even when another one is signed in by the time
  /// the result arrives (QA F02). Used by `data/billing` and
  /// `data/notifications`.
  Object? readAccountJson(String key, {String? accountId}) {
    final raw = _mem[accountId == null ? scopedKey(key) : keyOf(key, accountId)];
    if (raw == null) return null;
    try {
      return jsonDecode(raw);
    } catch (_) {
      return null;
    }
  }

  void writeAccountJson(String key, Object? value, {String? accountId}) {
    setItem(accountId == null ? scopedKey(key) : keyOf(key, accountId), jsonEncode(jsonClean(value)));
    notifyListeners();
  }

  // ---------------------------------------------------------------- accounts

  /// Accounts created on this device (see `data/auth/local_auth.dart`).
  List<LocalAccount> accounts() => _read<List<LocalAccount>>(
    _accountsKey,
    <LocalAccount>[],
    (j) => (j as List).map((e) => LocalAccount.fromJson((e as Map).cast<String, Object?>())).toList(),
  );

  LocalAccount? accountByUsername(String username) {
    final u = username.trim().toLowerCase();
    if (u.isEmpty) return null;
    for (final a in accounts()) {
      if (a.username.toLowerCase() == u) return a;
    }
    return null;
  }

  LocalAccount? accountById(String id) {
    for (final a in accounts()) {
      if (a.id == id) return a;
    }
    return null;
  }

  void saveAccount(LocalAccount a) {
    final rows = accounts().where((x) => x.id != a.id).toList()..add(a);
    _write(_accountsKey, rows.map((e) => e.toJson()).toList());
  }

  // ---------------------------------------------------------------- session
  Session? _sessionMem;
  bool _sessionLoaded = false;
  String? _accountId;

  void _resetSessionState() {
    _sessionMem = null;
    _sessionLoaded = false;
    _accountId = null;
    _likes = null;
    _likesInit = false;
    _dropCaches();
  }

  /// The signed-in account, or null for a guest.
  Session? readSession() {
    if (_sessionLoaded) return _sessionMem;
    _sessionLoaded = true;
    final s = _read<Session?>(_session, null, (j) => j == null ? null : Session.fromJson((j as Map).cast<String, Object?>()));
    _sessionMem = s != null && s.id.isNotEmpty ? s : null;
    _accountId = _sessionMem?.id;
    return _sessionMem;
  }

  /// Id of the signed-in account (null for a guest).
  String? get accountId {
    readSession();
    return _accountId;
  }

  /// Opens [s] (sign-in / sign-up / profile edit). Use `LocalAuth` from the
  /// screens; it checks the password first.
  void writeSession(Session s) {
    final switched = _accountId != s.id;
    _sessionMem = s;
    _sessionLoaded = true;
    _accountId = s.id;
    if (switched) {
      _likes = null;
      _likesInit = false;
      _forgetServerRows();
    }
    _dropCaches();
    setItem(_session, jsonEncode(s.toJson()));
    notifyListeners();
  }

  /// Closes the session (the account and its data stay on the device).
  void clearSession() {
    _sessionMem = null;
    _sessionLoaded = true;
    _accountId = null;
    _likes = null;
    _likesInit = false;
    _forgetServerRows();
    _dropCaches();
    removeItem(_session);
    notifyListeners();
  }

  /// What the server sent was for the account signed in then (an advertiser's phones, a request's author as
  /// his plan let him see): another account, or none, gets its own.
  void _forgetServerRows() {
    _serverEpoch++;
    _server.clear();
    _serverDetailed.clear();
    _serverRequests.clear();
  }

  /// Version 1.1 kept one session with a plain password and saved the
  /// private data without an account. On the first start of this version:
  /// the session becomes an account (salted hash, no plain password), the
  /// session is saved without the password, and the unscoped private data is
  /// moved to that account. Data left by a signed-out user of 1.1 has no
  /// known owner: it stays on the device but is shown to no account.
  ///
  /// Returns the account made from the 1.1 session (null when there was
  /// none); it is marked `migratedFrom: 1.1` for [_migrateOwnership].
  LocalAccount? _migrateLegacy() {
    removeItem(_legacyBoostKey);
    final raw = _mem[_session];
    if (raw == null) return null;
    Map<String, Object?> j;
    try {
      j = (jsonDecode(raw) as Map).cast<String, Object?>();
    } catch (_) {
      removeItem(_session);
      return null;
    }
    final id = j['id'];
    if (id is String && id.isNotEmpty) return null; // already a 1.2 session
    final username = (j['username'] as String? ?? '').trim();
    final password = j['password'] as String? ?? '';
    var account = username.isEmpty ? null : accountByUsername(username);
    if (account == null) {
      final salt = newSalt();
      final phone = j['phone'] as String? ?? '';
      account = LocalAccount(
        id: newAccountId(),
        username: username.isNotEmpty ? username : 'user-${DateTime.now().millisecondsSinceEpoch}',
        name: j['name'] as String? ?? '',
        phone: normalizeSaudiMobile(phone) ?? phone,
        role: j['role'] as String? ?? 'seeker',
        city: j['city'] as String? ?? '',
        salt: salt,
        hash: password.isEmpty ? '' : hashPassword(password, salt),
        iterations: password.isEmpty ? 0 : kPasswordIterations,
        createdAt: DateTime.now().toIso8601String(),
        migratedFrom: '1.1',
      );
      final rows = accounts()..add(account);
      setItem(_accountsKey, jsonEncode(rows.map((e) => e.toJson()).toList()));
    }
    for (final base in _privateKeys) {
      final v = _mem[base];
      if (v == null) continue;
      final scoped = '$base@${account.id}';
      if (!_mem.containsKey(scoped)) setItem(scoped, v);
      removeItem(base);
    }
    setItem(_session, jsonEncode(account.toSession().toJson()));
    _resetSessionState();
    return account;
  }

  // ---------------------------------------------------------------- 1.1 ownership (QA F01)

  /// Set once the ownership of the 1.1 records has been settled.
  static const String _ownershipDone = 'zon.migration.ownership.v1';

  /// Recovery requests for 1.1 records nobody could be proven to own.
  static const String _claimsKey = 'zon.legacy.claims.v1';

  /// Once per device. Version 1.1 saved listings and requests without an
  /// owner, and wrote on each one the name and role of the session that
  /// created it. A record created on this device whose recorded author —
  /// name AND role, exactly — is the 1.1 session that became an account
  /// ([LocalAccount.migratedFrom]) is given to that account. Every other
  /// ownerless record of 1.1 becomes `unclaimed`: it stays public and
  /// unchanged, nobody may edit it, and it is never given to an account by
  /// itself — not even to one that later uses the same name (recovery:
  /// [requestLegacyRecovery]). Running it again changes nothing.
  void _migrateOwnership(LocalAccount? legacy) {
    if (_mem[_ownershipDone] != null) return;
    final proven = legacy ?? _legacyAccount();
    var claimed = 0;
    var unclaimed = 0;

    bool sameAuthor(Map<String, Object?> r, String nameKey, String roleKey) {
      final a = proven;
      if (a == null) return false;
      final name = a.name.trim();
      return name.isNotEmpty && (r[nameKey] as String?)?.trim() == name && r[roleKey] == a.role;
    }

    List<Map<String, Object?>>? settle(String key, String nameKey, String roleKey) {
      final raw = _mem[key];
      if (raw == null) return null;
      final List<Map<String, Object?>> rows;
      try {
        rows = (jsonDecode(raw) as List).map((e) => (e as Map).cast<String, Object?>()).toList();
      } catch (_) {
        return null;
      }
      var changed = false;
      for (var i = 0; i < rows.length; i++) {
        final r = rows[i];
        if (r['ownerId'] != null || r['unclaimed'] == true) continue;
        if (sameAuthor(r, nameKey, roleKey)) {
          rows[i] = <String, Object?>{...r, 'ownerId': proven!.id};
          claimed++;
        } else {
          rows[i] = <String, Object?>{...r, 'unclaimed': true};
          unclaimed++;
        }
        changed = true;
      }
      return changed ? rows : null;
    }

    final listings = settle(_lk, 'advertiser', 'advertiserRole');
    if (listings != null) setItem(_lk, jsonEncode(listings));
    final requests = settle(_rk, 'author', 'role');
    if (requests != null) setItem(_rk, jsonEncode(requests));
    setItem(
      _ownershipDone,
      jsonEncode(<String, Object?>{'at': DateTime.now().toIso8601String(), 'account': proven?.id, 'claimed': claimed, 'unclaimed': unclaimed}),
    );
    _dropCaches();
  }

  /// The single account made from a 1.1 session on this device (null when
  /// none, or when more than one would make it ambiguous).
  LocalAccount? _legacyAccount() {
    final marked = accounts().where((a) => a.migratedFrom == '1.1').toList();
    return marked.length == 1 ? marked.single : null;
  }

  /// 1.1 listings and requests whose author could not be proven (see
  /// [_migrateOwnership]). They are public and unchanged; nobody owns them.
  ({List<Listing> listings, List<RequestItem> requests}) unclaimedLegacy() => (
    listings: allListings().where((l) => l.unclaimed == true && l.ownerId == null).toList(),
    requests: allRequests().where((r) => r.unclaimed == true && r.ownerId == null).toList(),
  );

  /// The signed-in account asks for the unclaimed 1.1 data. NOTHING is
  /// transferred here: the request is kept so that, once the server is
  /// connected, support can check who wrote the records and give them to
  /// their proven author only.
  void requestLegacyRecovery() {
    final me = accountId;
    if (me == null || legacyRecoveryRequested()) return;
    final u = unclaimedLegacy();
    final rows = _legacyClaims()
      ..add(<String, Object?>{
        'accountId': me,
        'at': DateTime.now().toIso8601String(),
        'listings': u.listings.map((l) => l.id).toList(),
        'requests': u.requests.map((r) => r.id).toList(),
      });
    setItem(_claimsKey, jsonEncode(rows));
    notifyListeners();
  }

  /// Whether the signed-in account already asked (see [requestLegacyRecovery]).
  bool legacyRecoveryRequested() {
    final me = accountId;
    return me != null && _legacyClaims().any((c) => c['accountId'] == me);
  }

  List<Map<String, Object?>> _legacyClaims() {
    final raw = _mem[_claimsKey];
    if (raw == null) return <Map<String, Object?>>[];
    try {
      return (jsonDecode(raw) as List).map((e) => (e as Map).cast<String, Object?>()).toList();
    } catch (_) {
      return <Map<String, Object?>>[];
    }
  }

  // ---------------------------------------------------------------- listings
  List<Listing>? _listingsCache;
  Map<String, Listing>? _listingMap;
  List<Listing>? _inventoryCache;

  List<Listing> _extraListings() =>
      _read<List<Listing>>(_lk, <Listing>[], (j) => (j as List).map((e) => Listing.fromJson((e as Map).cast<String, Object?>())).toList());

  Map<String, Map<String, Object?>> _patches() => _read<Map<String, Map<String, Object?>>>(
    _pk,
    <String, Map<String, Object?>>{},
    (j) => (j as Map).map((k, v) => MapEntry(k as String, (v as Map).cast<String, Object?>())),
  );

  /// Listings and projects the server sent (feed pages and details, `L…` / `P…`), by id; a detail is never
  /// replaced by a card of the same row, which carries less.
  final Map<String, Listing> _server = <String, Listing>{};
  final Set<String> _serverDetailed = <String>{};

  /// Which account the rows of the server belong to: it changes when the account does (sign-out, another account).
  /// A caller reads it when it asks the server, and passes it to [keepServer] / [keepServerRequests] with the
  /// answer: an answer that comes after the account changed was for the one before, and is dropped.
  int get serverEpoch => _serverEpoch;
  int _serverEpoch = 0;

  /// For a sequence of requests begun under [epoch] (photos, then the listing…): throws [AccountChanged] when the
  /// account changed since, so none of the rest goes out under the new account. Read right before each request.
  void stillAccount(int epoch) {
    if (epoch != _serverEpoch) throw const AccountChanged();
  }

  /// Keeps rows of the server for the screens that read synchronously (cards, details, favourites); [epoch] is
  /// [serverEpoch] when they were asked for.
  void keepServer(Iterable<Listing> rows, {required int epoch, bool detailed = false}) {
    if (epoch != _serverEpoch) return;
    for (final row in rows) {
      if (!detailed && _serverDetailed.contains(row.id)) continue;
      _server[row.id] = row;
      if (detailed) _serverDetailed.add(row.id);
    }
    _dropCaches();
    notifyListeners();
  }

  /// Sample + user listings with the user's edits applied (cached until the
  /// next write). With a server: what it sent, and no sample.
  List<Listing> allListings() {
    final cached = _listingsCache;
    if (cached != null) return cached;
    final extra = _extraListings();
    final stockIds = inventory().map((l) => l.id).toSet();
    final overlay = _patches();
    final seed = Api.on ? _server.values.toList() : (kSimulations ? getListings() : const <Listing>[]);
    final seedIds = seed.map((s) => s.id).toSet();
    final merged = <Listing>[...seed, ...extra.where((l) => !seedIds.contains(l.id) && !stockIds.contains(l.id))];
    final map = <String, Listing>{};
    for (final l in merged) {
      final p = overlay[l.id];
      map[l.id] = p == null ? l : l.merged(p);
    }
    _listingMap = map;
    _listingsCache = map.values.toList(growable: false);
    return _listingsCache!;
  }

  /// A listing/project by id (also searches the signed-in broker's stock).
  Listing? listingById(String id) {
    if (_listingMap == null) allListings();
    final hit = _listingMap?[id];
    if (hit != null) return hit;
    for (final l in inventory()) {
      if (l.id == id) return l;
    }
    return null;
  }

  /// Whether the signed-in account owns [l] (published by it or in its
  /// private stock). Sample listings belong to nobody.
  bool ownsListing(Listing l) {
    final me = accountId;
    if (me == null) return false;
    if (l.ownerId == me) return true;
    // the private stock is stored under the account itself (also the stock
    // moved from version 1.1, saved without an owner id)
    return inventory().any((x) => x.id == l.id && (x.ownerId == null || x.ownerId == me));
  }

  /// Whether the signed-in account may edit the listing [id] (QA ACCESS-02:
  /// checked by the editor route and by every save, not only by hiding the
  /// edit button).
  bool canEditListing(String id) {
    final l = listingById(id);
    return l != null && ownsListing(l);
  }

  /// Listings and projects published by the signed-in account.
  List<Listing> myListings() {
    final me = accountId;
    if (me == null) return const <Listing>[];
    return allListings().where((l) => l.ownerId == me).toList();
  }

  /// Adds or replaces a user listing (newest first). A listing saved while
  /// signed in records the account as its owner. The photos chosen in the
  /// form are saved as the app's durable copies (QA F05).
  void saveListing(Listing l) {
    final me = accountId;
    // an unclaimed 1.1 listing never takes the owner of whoever saves it
    final mine = l.ownerId == null && me != null && l.unclaimed != true ? l.merged(<String, Object?>{'ownerId': me}) : l;
    final owned = Listing.fromJson(_mapPhotos(mine.toJson(), PhotoStore.commit));
    final extra = _extraListings().where((x) => x.id != owned.id).toList();
    extra.insert(0, owned);
    _write(_lk, extra.map((e) => e.toJson()).toList());
  }

  /// Changes some fields of a listing; a [kUndefined] value removes a field.
  /// Sample listings are not modified — the patch is stored next to them.
  /// New photos are saved as the app's durable copies (QA F05).
  void patchListing(String id, Map<String, Object?> changes) {
    final patch = _mapPhotos(changes, PhotoStore.commit);
    final raw = _read<List<Map<String, Object?>>>(
      _lk,
      <Map<String, Object?>>[],
      (j) => (j as List).map((e) => (e as Map).cast<String, Object?>()).toList(),
    );
    final i = raw.indexWhere((l) => l['id'] == id);
    if (i >= 0) {
      raw[i] = <String, Object?>{...raw[i], ...patch};
      _write(_lk, raw);
      return;
    }
    final stock = inventory();
    final si = stock.indexWhere((l) => l.id == id);
    if (si >= 0) {
      final rows = List<Listing>.of(stock);
      rows[si] = rows[si].merged(patch);
      _write(_ik, rows.map((e) => e.toJson()).toList());
      return;
    }
    final all = _patches();
    all[id] = <String, Object?>{...(all[id] ?? const <String, Object?>{}), ...patch};
    _write(_pk, all);
  }

  // ---------------------------------------------------------------- photos (QA F05)

  /// [json] — a listing, a stock item or a listing patch — with its photos
  /// (`images` and the `image` of each unit) mapped by [f].
  static Map<String, Object?> _mapPhotos(Map<String, Object?> json, String Function(String path) f) {
    final out = <String, Object?>{...json};
    final images = json['images'];
    if (images is List) out['images'] = <Object?>[for (final p in images) p is String ? f(p) : p];
    final units = json['units'];
    if (units is List) {
      out['units'] = <Object?>[
        for (final u in units)
          if (u is Map && u['image'] is String) <String, Object?>{...u.cast<String, Object?>(), 'image': f(u['image'] as String)} else u,
      ];
    }
    return out;
  }

  /// Photos saved by version 1.2.0 and earlier as absolute (temporary)
  /// paths: copied into the app's folder while their file still exists
  /// (`PhotoStore.adopt`). Public listings, listing edits and the stock of
  /// every account. Runs at each launch; a photo adopted once is a
  /// reference afterwards, so nothing is copied twice.
  void _adoptOldPhotos() {
    if (PhotoStore.root == null) return;
    for (final key in _mem.keys.toList()) {
      final list = key == _lk || key == _ik || key.startsWith('$_ik@');
      final edits = key == _pk;
      if (!list && !edits) continue;
      final raw = _mem[key]!;
      if (!raw.contains('"/')) continue; // no absolute path inside
      var changed = false;
      String adopt(String p) {
        final next = PhotoStore.adopt(p);
        if (next != p) changed = true;
        return next;
      }

      try {
        final j = jsonDecode(raw);
        Object? next;
        if (list && j is List) {
          next = <Object?>[for (final e in j) e is Map ? _mapPhotos(e.cast<String, Object?>(), adopt) : e];
        } else if (edits && j is Map) {
          next = j.map((k, v) => MapEntry(k, v is Map ? _mapPhotos(v.cast<String, Object?>(), adopt) : v));
        }
        if (changed && next != null) setItem(key, jsonEncode(next));
      } catch (_) {
        // unreadable value: left as it is
      }
    }
    _dropCaches();
  }

  // ---------------------------------------------------------------- requests
  List<RequestItem> _extraRequests() => _read<List<RequestItem>>(
    _rk,
    <RequestItem>[],
    (j) => (j as List).map((e) => RequestItem.fromJson((e as Map).cast<String, Object?>())).toList(),
  );

  /// Requests the server sent (the board, a request, «طلباتي», the broker's area), by id.
  final Map<String, RequestItem> _serverRequests = <String, RequestItem>{};

  /// Keeps requests of the server for the screens that look one up by its id; the newest copy wins. [epoch] is
  /// [serverEpoch] when they were asked for.
  void keepServerRequests(Iterable<RequestItem> rows, {required int epoch}) {
    if (epoch != _serverEpoch) return;
    for (final row in rows) {
      _serverRequests[row.id] = row;
    }
    notifyListeners();
  }

  /// User requests first, then the sample requests. With a server: what it sent, and no sample.
  List<RequestItem> allRequests() {
    if (Api.on) return _serverRequests.values.toList();
    final extra = _extraRequests();
    final ids = extra.map((r) => r.id).toSet();
    return <RequestItem>[...extra, if (kSimulations) ...kRequests.where((r) => !ids.contains(r.id))];
  }

  RequestItem? requestById(String id) {
    for (final r in allRequests()) {
      if (r.id == id) return r;
    }
    return null;
  }

  /// Requests published by the signed-in account.
  List<RequestItem> myRequests() {
    final me = accountId;
    if (me == null) return const <RequestItem>[];
    return allRequests().where((r) => r.ownerId == me).toList();
  }

  /// Whether the signed-in account wrote the request.
  bool ownsRequest(RequestItem r) => accountId != null && r.ownerId == accountId;

  /// Adds or replaces a request (newest first). A request saved while signed
  /// in records the account as its author and the publishing time.
  void saveRequest(RequestItem r) {
    final me = accountId;
    final patch = <String, Object?>{
      if (r.ownerId == null && me != null && r.unclaimed != true) 'ownerId': me,
      if (r.createdAt == null) 'createdAt': DateTime.now().toIso8601String(),
    };
    final item = patch.isEmpty ? r : r.merged(patch);
    final extra = _extraRequests().where((x) => x.id != item.id).toList();
    extra.insert(0, item);
    _write(_rk, extra.map((e) => e.toJson()).toList());
  }

  void patchRequest(String id, Map<String, Object?> patch) {
    final live = requestById(id);
    if (live == null) return;
    saveRequest(live.merged(patch));
  }

  // ---------------------------------------------------------------- clients
  static final List<Client> _seedClients = <Client>[
    const Client(
      id: 'c1',
      name: 'فيصل الدوسري',
      phone: '0551112233',
      city: 'الرياض',
      hood: 'النرجس',
      types: 'فيلا',
      budget: '83849',
      notes: <Note>[Note(id: 'n1', kind: 'رفض', text: 'الكبكات المشتري وقال إذا كان في حساب أو أي شيء ثاني أنا عندي رقم حساب ثاني بس')],
    ),
    const Client(
      id: 'c2',
      name: 'نورة العتيبي',
      phone: '0552223344',
      city: 'الرياض',
      hood: 'الملقا',
      types: 'شقة',
      budget: '900000',
      notes: <Note>[Note(id: 'n2', kind: 'تفكير', text: 'تبحث شقة عوائل حتى مليون')],
    ),
  ];

  /// The broker's clients (saved + samples).
  List<Client> clients() {
    final extra = _read<List<Client>>(_ck, <Client>[], (j) => (j as List).map((e) => Client.fromJson((e as Map).cast<String, Object?>())).toList());
    final ids = extra.map((c) => c.id).toSet();
    return <Client>[...extra, if (kSimulations) ..._seedClients.where((c) => !ids.contains(c.id))];
  }

  void saveClient(Client c) {
    final extra = _read<List<Client>>(
      _ck,
      <Client>[],
      (j) => (j as List).map((e) => Client.fromJson((e as Map).cast<String, Object?>())).toList(),
    ).where((x) => x.id != c.id).toList();
    extra.insert(0, c);
    _write(_ck, extra.map((e) => e.toJson()).toList());
  }

  // ---------------------------------------------------------------- owners
  static final List<Owner> _seedOwners = <Owner>[
    const Owner(
      id: 'o1',
      name: 'أبو خالد الملقا',
      phone: '0553001122',
      listingTitle: 'فيلا ركنية في الملقا',
      listingId: 'l1',
      notes: <Note>[Note(id: 'on1', kind: 'تفكير', text: 'يفضّل البيع كاش ويرفض البنك')],
    ),
    const Owner(
      id: 'o2',
      name: 'سلطان الروضة',
      phone: '0553003344',
      listingTitle: 'عمارة للبيع في الروضة',
      listingId: 'l6',
      notes: <Note>[Note(id: 'on2', kind: 'قبول', text: 'وافق على المعاينة الأحد')],
    ),
    const Owner(
      id: 'o3',
      name: 'أم فهد الحمراء',
      phone: '0553005566',
      listingTitle: 'محل تجاري في الحمراء',
      listingId: 'l7',
      notes: <Note>[Note(id: 'on3', kind: 'تأجيل', text: 'يرجع بعد العيد')],
    ),
  ];

  /// The broker's owners (saved + samples).
  List<Owner> owners() {
    final extra = _read<List<Owner>>(_ok, <Owner>[], (j) => (j as List).map((e) => Owner.fromJson((e as Map).cast<String, Object?>())).toList());
    final ids = extra.map((o) => o.id).toSet();
    return <Owner>[...extra, if (kSimulations) ..._seedOwners.where((o) => !ids.contains(o.id))];
  }

  void saveOwner(Owner o) {
    final extra = _read<List<Owner>>(
      _ok,
      <Owner>[],
      (j) => (j as List).map((e) => Owner.fromJson((e as Map).cast<String, Object?>())).toList(),
    ).where((x) => x.id != o.id).toList();
    extra.insert(0, o);
    _write(_ok, extra.map((e) => e.toJson()).toList());
  }

  // ---------------------------------------------------------------- tasks
  /// Saved tasks of the signed-in account; the three sample tasks are shown
  /// until the account saves its list for the first time (an empty saved
  /// list stays empty).
  List<Task> tasks() {
    if (_mem[_k(_tk)] != null) {
      return _read<List<Task>>(_tk, <Task>[], (j) => (j as List).map((e) => Task.fromJson((e as Map).cast<String, Object?>())).toList());
    }
    if (!kSimulations) return const <Task>[];
    return const <Task>[
      Task(id: 't1', title: 'متابعة عميل الملقا', pri: 'مهم', body: 'أكلم فيصل بخصوص الفيلا'),
      Task(id: 't2', title: 'تحديث عرض العليا', pri: 'عادي'),
      Task(id: 't3', title: 'معاينة مؤجلة الخميس', pri: 'مؤجل', body: 'النرجس — فيلا الركن'),
    ];
  }

  /// Saves the whole task list (add / edit / toggle / delete).
  void saveTasks(List<Task> rows) => _write(_tk, rows.map((e) => e.toJson()).toList());

  // ---------------------------------------------------------------- chats
  static const List<Chat> _seedChats = <Chat>[
    Chat(id: 'fahd', name: 'فهد العتيبي', role: 'broker', last: 'العرض جاهز للمعاينة الخميس', listingId: 'l1', at: '10:14'),
    Chat(id: 'dar', name: 'دار التمكين', role: 'developer', last: 'وحدتان متاحتان في البحيرات', listingId: 'p2', at: 'أمس'),
    Chat(id: 'sara', name: 'سارة القحطاني', role: 'seeker', last: 'أبي شقة عوائل بالعليا حتى مليون', at: 'أمس'),
    Chat(id: 'khaled', name: 'خالد الدوسري', role: 'owner', last: 'المالك موافق على التفاوض', listingId: 'l2', at: 'أحد'),
  ];

  /// Conversations, most recent first.
  List<Chat> chats() {
    final extra = _read<List<Chat>>(_ch, <Chat>[], (j) => (j as List).map((e) => Chat.fromJson((e as Map).cast<String, Object?>())).toList());
    final ids = extra.map((c) => c.id).toSet();
    return <Chat>[...extra, if (kSimulations) ..._seedChats.where((c) => !ids.contains(c.id))];
  }

  Chat? chatById(String id) {
    for (final c in chats()) {
      if (c.id == id) return c;
    }
    return null;
  }

  /// Moves (or adds) a conversation to the top of the list.
  void openChat(Chat c) {
    final rows = chats().where((x) => x.id != c.id).toList();
    Chat? prev;
    for (final x in chats()) {
      if (x.id == c.id) prev = x;
    }
    final merged = <String, Object?>{...?prev?.toJson(), ...c.toJson(), 'at': 'الآن'};
    rows.insert(0, Chat.fromJson(merged));
    _write(_ch, rows.map((e) => e.toJson()).toList());
  }

  List<Msg> messages(String id) {
    final all = _read<Map<String, List<Msg>>>(
      _mk,
      <String, List<Msg>>{},
      (j) => (j as Map).map((k, v) => MapEntry(k as String, (v as List).map((e) => Msg.fromJson((e as Map).cast<String, Object?>())).toList())),
    );
    return all[id] ?? <Msg>[];
  }

  void pushMessage(String id, Msg m) {
    final all = _read<Map<String, List<Msg>>>(
      _mk,
      <String, List<Msg>>{},
      (j) => (j as Map).map((k, v) => MapEntry(k as String, (v as List).map((e) => Msg.fromJson((e as Map).cast<String, Object?>())).toList())),
    );
    all[id] = <Msg>[...(all[id] ?? const <Msg>[]), m];
    _write(_mk, all.map((k, v) => MapEntry(k, v.map((e) => e.toJson()).toList())));
  }

  // ---------------------------------------------------------------- semantic marks
  Map<String, SemMark> loadSemMarks() => _read<Map<String, SemMark>>(
    _fk,
    <String, SemMark>{},
    (j) => (j as Map).map((k, v) => MapEntry(k as String, SemMark.fromJson((v as Map).cast<String, Object?>()))),
  );

  void saveSemMark(String id, SemMark mark) {
    final all = loadSemMarks();
    all[id] = mark;
    _write(_fk, all.map((k, v) => MapEntry(k, v.toJson())));
  }

  // ---------------------------------------------------------------- wish / radar
  Wish? loadWish() => _read<Wish?>(_wk, null, (j) => j == null ? null : Wish.fromJson((j as Map).cast<String, Object?>()));

  void saveWish(Wish w) => _write(_wk, w.toJson());

  void clearWish() {
    removeItem(_k(_wk));
    removeItem(_k(_seenKey));
    notifyListeners();
  }

  List<String> matchingIds(Wish wish, List<Listing> listings) => listings
      .where(
        (l) =>
            (wish.city == 'all' || l.city == wish.city) &&
            (wish.hoods.isEmpty || wish.hoods.contains(l.neighborhood)) &&
            (wish.types.isEmpty || wish.types.contains(l.propertyType)),
      )
      .map((l) => l.id)
      .toList();

  List<String> loadSeenHits() => _read<List<String>>(_seenKey, <String>[], (j) => (j as List).map((e) => e.toString()).toList());

  void markHitsSeen(List<String> ids) => _write(_seenKey, ids);

  /// Listings matching the saved wish that the account has not seen yet.
  List<String> freshMatches() {
    final w = loadWish();
    if (w == null || w.text.isEmpty) return const <String>[];
    final seen = loadSeenHits().toSet();
    return matchingIds(w, allListings()).where((id) => !seen.contains(id)).toList();
  }

  /// Whether the matching job (simulated by [pingRadar]) reported results.
  bool radarPinged() => _read<num>(_pingKey, 0, (j) => j is num ? j : 0) > 0;

  int matchBadge() {
    final w = loadWish();
    if (w == null || w.text.isEmpty) return 0;
    return freshMatches().length + (radarPinged() ? 1 : 0);
  }

  void pingRadar() => _write(_pingKey, 1);

  void clearRadarPing() => _write(_pingKey, 0);

  // ---------------------------------------------------------------- stock / inventory
  static const List<Listing> _seedStock = <Listing>[
    Listing(
      id: 'st-seed-1',
      kind: 'unit',
      title: 'فيلا — النرجس',
      purpose: 'sale',
      city: 'الرياض',
      neighborhood: 'النرجس',
      propertyType: 'villa',
      source: 'broker',
      price: 4200000,
      rooms: 5,
      baths: 4,
      area: 380,
      images: <String>['/listings/villa-1.jpg'],
      description: 'فيلا غير منشورة للمتابعة — مسودة في المخزون حتى تنشر.',
      advertiser: 'وسيط زون',
      advertiserRole: 'broker',
      license: '—',
      active: false,
    ),
    Listing(
      id: 'st-seed-2',
      kind: 'unit',
      title: 'شقة — العليا',
      purpose: 'rent',
      city: 'الرياض',
      neighborhood: 'العليا',
      propertyType: 'apartment',
      source: 'broker',
      price: 85000,
      rooms: 3,
      baths: 2,
      area: 165,
      images: <String>['/listings/apt-1.jpg'],
      description: 'شقة عوائل مسودة — تُنشر من المخزون بعد اكتمال الصور.',
      advertiser: 'وسيط زون',
      advertiserRole: 'broker',
      license: '—',
      occupancy: 'families',
      active: false,
    ),
  ];

  /// The signed-in account's private stock (saved + samples).
  List<Listing> inventory() {
    final cached = _inventoryCache;
    if (cached != null) return cached;
    final extra = _read<List<Listing>>(
      _ik,
      <Listing>[],
      (j) => (j as List).map((e) => Listing.fromJson((e as Map).cast<String, Object?>())).toList(),
    );
    final ids = extra.map((l) => l.id).toSet();
    // A sample draft that was published lives in the public listings now.
    final published = _extraListings().map((l) => l.id).toSet();
    _inventoryCache = <Listing>[...extra, if (kSimulations) ..._seedStock.where((s) => !ids.contains(s.id) && !published.contains(s.id))];
    return _inventoryCache!;
  }

  void saveStock(Listing l) {
    final me = accountId;
    final rows = inventory().where((x) => x.id != l.id).toList();
    final kept = Listing.fromJson(_mapPhotos(l.toJson(), PhotoStore.commit));
    rows.insert(0, kept.merged(<String, Object?>{'active': false, if (l.ownerId == null && me != null) 'ownerId': me}));
    _write(_ik, rows.map((e) => e.toJson()).toList());
  }

  void publishStock(String id) {
    Listing? item;
    for (final l in inventory()) {
      if (l.id == id) item = l;
    }
    if (item == null) return;
    _write(_ik, inventory().where((l) => l.id != id).map((e) => e.toJson()).toList());
    saveListing(item.merged(<String, Object?>{'active': true}));
  }

  // ---------------------------------------------------------------- follows
  static const List<Person> _seedFollowers = <Person>[
    Person(id: 'fahd', n: 'فهد العتيبي', r: 'وسيط', role: 'broker'),
    Person(id: 'dar', n: 'دار التمكين', r: 'مطوّر عقاري', role: 'developer'),
    Person(id: 'sara', n: 'سارة القحطاني', r: 'باحث', role: 'seeker'),
    Person(id: 'khaled', n: 'خالد الدوسري', r: 'مالك', role: 'owner'),
    Person(id: 'noura', n: 'نورة الشمري', r: 'باحث', role: 'seeker'),
    Person(id: 'aziz', n: 'عبدالعزيز المالكي', r: 'وسيط', role: 'broker'),
    Person(id: 'emar', n: 'إعمار الرياض', r: 'مطوّر عقاري', role: 'developer'),
    Person(id: 'laila', n: 'ليلى الحربي', r: 'مالك', role: 'owner'),
    Person(id: 'majed', n: 'ماجد السبيعي', r: 'وسيط', role: 'broker'),
    Person(id: 'hind', n: 'هند العتيبي', r: 'باحث', role: 'seeker'),
    Person(id: 'badr', n: 'بدر القحطاني', r: 'مالك', role: 'owner'),
    Person(id: 'waha', n: 'واحة النرجس', r: 'مطوّر عقاري', role: 'developer'),
  ];

  ({List<Person> followers, List<Person> following}) follows() {
    return _read<({List<Person> followers, List<Person> following})>(
      _flk,
      kSimulations
          ? (followers: List<Person>.of(_seedFollowers), following: List<Person>.of(_seedFollowers.take(8)))
          : (followers: <Person>[], following: <Person>[]),
      (j) {
        final m = (j as Map).cast<String, Object?>();
        List<Person> list(Object? v) => (v as List? ?? const <Object?>[]).map((e) => Person.fromJson((e as Map).cast<String, Object?>())).toList();
        return (followers: list(m['followers']), following: list(m['following']));
      },
    );
  }

  bool isFollowing(String name) => follows().following.any((x) => x.n == name);

  void toggleFollow(Person person) {
    final book = follows();
    final following = List<Person>.of(book.following);
    final i = following.indexWhere((x) => x.n == person.n);
    if (i >= 0) {
      following.removeAt(i);
    } else {
      following.insert(0, person);
    }
    _write(_flk, <String, Object?>{
      'followers': book.followers.map((p) => p.toJson()).toList(),
      'following': following.map((p) => p.toJson()).toList(),
    });
  }

  // ---------------------------------------------------------------- likes (kept in memory for the session)
  Set<String>? _likes;
  bool _likesInit = false;

  Set<String> likedSet() => Set<String>.of(_likes ?? const <String>{});

  void persistLikes(Set<String> next) {
    _likes = Set<String>.of(next);
    notifyListeners();
  }

  List<String> likedIds() {
    final s = likedSet();
    if (s.isNotEmpty) return s.toList();
    if (_likesInit || Api.on) return <String>[]; // sample likes are for the local build, never a server's
    final ids = allListings().take(5).map((l) => l.id).toList();
    _likes = ids.toSet();
    _likesInit = true;
    return ids;
  }
}

/// `encodeURIComponent(n.trim())`
String slugName(String n) => Uri.encodeComponent(n.trim());

final Store store = Store.instance;

/// The account changed (sign-out, another account) in the middle of a sequence begun under the one before
/// ([Store.stillAccount]); nothing of it is sent further, and its screen shows nothing of it.
class AccountChanged implements Exception {
  const AccountChanged();
}

/// Convenience alias used by widgets (`readSession()` in the design).
Session? readSession() => store.readSession();
