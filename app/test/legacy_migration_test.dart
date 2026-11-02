// QA F01 — upgrading from version 1.1 (v2): listings and requests saved
// then had no owner. They must stay with the account that wrote them, the
// upgrade must not run twice, and records whose author cannot be proven are
// never handed to an account.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/settings_view.dart';

import 'support/test_env.dart';

/// The session record of version 1.1 (with its plain password).
String _session11({String name = 'فهد العتيبي', String username = 'fahd', String role = 'broker'}) => jsonEncode(<String, Object>{
  'name': name,
  'username': username,
  'password': 'old-password-1',
  'phone': '0551234567',
  'role': role,
  'city': 'الرياض',
});

/// A listing as version 1.1 saved it: author name and role, no owner.
Map<String, Object?> _listing11(String id, {required String advertiser, String role = 'broker', String kind = 'unit'}) {
  final j = Listing(
    id: id,
    kind: kind,
    title: 'عرض من الإصدار السابق',
    purpose: 'sale',
    city: 'الرياض',
    neighborhood: 'الملقا',
    propertyType: 'villa',
    source: role,
    price: 2500000,
    rooms: 5,
    baths: 4,
    area: 400,
    images: const <String>['/listings/villa-1.jpg'],
    description: 'وصف',
    advertiser: advertiser,
    advertiserRole: role,
    license: '7200000001',
    active: true,
  ).toJson();
  expect(j.containsKey('ownerId'), isFalse, reason: '1.1 had no owner field');
  return j;
}

Map<String, Object?> _request11(String id, {required String author, String role = 'broker'}) => const RequestItem(
  id: '',
  purpose: 'sale',
  city: 'الرياض',
  neighborhoods: <String>['الملقا'],
  types: <String>['villa'],
  priceMin: 2000000,
  priceMax: 3000000,
  areaMin: 300,
  areaMax: 500,
  roomsMin: 4,
  roomsMax: 6,
  description: 'طلب من الإصدار السابق',
  author: '',
  role: 'broker',
).merged(<String, Object?>{'id': id, 'author': author, 'role': role}).toJson();

void main() {
  group('a 1.1 device with one known account', () {
    Map<String, Object> device() => <String, Object>{
      'zon.session.v1': _session11(),
      'zon.extra.listings.v1': jsonEncode(<Object?>[_listing11('u-1700000000001', advertiser: 'فهد العتيبي')]),
      'zon.extra.requests.v1': jsonEncode(<Object?>[_request11('r-1700000000002', author: 'فهد العتيبي')]),
      'zon.stock.v1': jsonEncode(<Object?>[_listing11('st-1700000000003', advertiser: 'فهد العتيبي')]),
    };

    test('keeps its listings, requests, stock and edit rights', () async {
      await freshDevice(device());
      final me = store.readSession()!;
      expect(me.username, 'fahd');
      expect(store.myListings().map((l) => l.id), contains('u-1700000000001'));
      expect(store.myRequests().map((r) => r.id), <String>['r-1700000000002']);
      expect(store.canEditListing('u-1700000000001'), isTrue);
      expect(store.ownsRequest(store.requestById('r-1700000000002')!), isTrue);
      expect(store.inventory().map((l) => l.id), contains('st-1700000000003'), reason: 'the private stock moved with the account');
      expect(store.canEditListing('st-1700000000003'), isTrue, reason: 'and stays editable');
      expect(store.unclaimedLegacy().listings, isEmpty);

      // after signing out and in again, still the same
      LocalAuth.signOut();
      expect(LocalAuth.signIn('fahd', 'old-password-1').ok, isTrue);
      expect(store.myListings().map((l) => l.id), contains('u-1700000000001'));
      expect(store.myRequests().map((r) => r.id), <String>['r-1700000000002']);
    });

    test('running the upgrade again changes nothing and duplicates nothing', () async {
      await freshDevice(device());
      final listingsBefore = store.getItem('zon.extra.listings.v1');
      final requestsBefore = store.getItem('zon.extra.requests.v1');
      final accountsBefore = store.accounts().length;
      await restartApp();
      await restartApp();
      expect(store.getItem('zon.extra.listings.v1'), listingsBefore);
      expect(store.getItem('zon.extra.requests.v1'), requestsBefore);
      expect(store.accounts(), hasLength(accountsBefore));
      expect(store.myListings().where((l) => l.id == 'u-1700000000001'), hasLength(1));
    });
  });

  group('records whose author cannot be proven', () {
    test('another author on the same device: shown, unchanged, owned by nobody', () async {
      await freshDevice(<String, Object>{
        'zon.session.v1': _session11(),
        'zon.extra.listings.v1': jsonEncode(<Object?>[
          _listing11('u-1700000000010', advertiser: 'فهد العتيبي'),
          _listing11('u-1700000000011', advertiser: 'سالم القحطاني', role: 'owner'),
          // same name, different role: not proof either
          _listing11('u-1700000000012', advertiser: 'فهد العتيبي', role: 'owner'),
        ]),
        'zon.extra.requests.v1': jsonEncode(<Object?>[_request11('r-1700000000013', author: 'نورة الشهري', role: 'seeker')]),
      });
      expect(store.myListings().map((l) => l.id), <String>['u-1700000000010']);
      expect(store.myRequests(), isEmpty);
      expect(store.canEditListing('u-1700000000011'), isFalse);
      expect(store.canEditListing('u-1700000000012'), isFalse);
      // not hidden: still public and unchanged
      final other = store.listingById('u-1700000000011')!;
      expect(other.unclaimed, isTrue);
      expect(other.ownerId, isNull);
      expect(other.title, 'عرض من الإصدار السابق');
      expect(store.allListings().map((l) => l.id), contains('u-1700000000011'));
      final u = store.unclaimedLegacy();
      expect(u.listings.map((l) => l.id), unorderedEquals(<String>['u-1700000000011', 'u-1700000000012']));
      expect(u.requests.map((r) => r.id), <String>['r-1700000000013']);
    });

    test('an account made later with the same name gets nothing', () async {
      await freshDevice(<String, Object>{
        'zon.session.v1': _session11(),
        'zon.extra.listings.v1': jsonEncode(<Object?>[_listing11('u-1700000000020', advertiser: 'سالم القحطاني', role: 'owner')]),
      });
      LocalAuth.signOut();
      signUp('salem.q', role: 'owner', name: 'سالم القحطاني');
      expect(store.myListings(), isEmpty);
      expect(store.canEditListing('u-1700000000020'), isFalse);
      // saving it through the store does not make it his either
      store.saveListing(store.listingById('u-1700000000020')!);
      expect(store.listingById('u-1700000000020')!.ownerId, isNull);
      await restartApp();
      expect(store.myListings(), isEmpty);
    });

    test('no 1.1 session on the device (signed out then): nothing is assigned', () async {
      await freshDevice(<String, Object>{
        'zon.extra.listings.v1': jsonEncode(<Object?>[_listing11('u-1700000000030', advertiser: 'فهد العتيبي')]),
        'zon.extra.requests.v1': jsonEncode(<Object?>[_request11('r-1700000000031', author: 'فهد العتيبي')]),
      });
      signUp('fahd.new', name: 'فهد العتيبي');
      expect(store.myListings(), isEmpty);
      expect(store.myRequests(), isEmpty);
      expect(store.unclaimedLegacy().listings, hasLength(1));
    });

    testWidgets('settings show them with a recovery request that moves nothing', (tester) async {
      await freshDevice(<String, Object>{
        'zon.session.v1': _session11(),
        'zon.extra.listings.v1': jsonEncode(<Object?>[_listing11('u-1700000000040', advertiser: 'سالم القحطاني', role: 'owner')]),
      });
      await pumpApp(tester, '/account/settings');
      expect(find.byType(SettingsView), findsOneWidget);
      expect(find.text('بيانات من الإصدار السابق'), findsOneWidget);
      expect(find.text('عروض: 1 · طلبات: 0'), findsOneWidget);
      await tapText(tester, 'طلب استعادة');
      expect(find.textContaining('سُجّل طلبك'), findsOneWidget);
      expect(store.legacyRecoveryRequested(), isTrue);
      expect(store.listingById('u-1700000000040')!.ownerId, isNull, reason: 'a request is not a transfer');
      expect(store.myListings(), isEmpty);
    });
  });
}
