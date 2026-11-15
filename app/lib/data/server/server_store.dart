// Plans and buying in the app with a server (docs/api.md «الباقات والدفع والفواتير»):
//
//   GET  /packages           the plans on sale with their prices and what each includes; the single services' prices
//   GET  /subscription       his plan, its last day and its balances
//   GET  /invoices           his invoices
//   GET  /store/products     what his role may buy in the app, his store token, and which stores this server simulates
//   POST /store/check        before the store's sheet: can this be bought now, for this listing or request?
//   POST /store/purchases    after the sheet: the store's reference — applied once, on the store's own word
//
// In the apps a plan or a boost is bought through the store (App Store · Google Play), never by card. The store's
// real sheet is not in this build yet: only a store the server says it simulates can be bought from here, with
// the reference its simulation reads (`sim:{account token}:{id}`), and the page says the payment is a trial.
// The reference of one purchase stays the same on every try, so a purchase sent again is the same payment.

import 'package:flutter/foundation.dart';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/app_identity.dart';
import 'package:zon/data/store.dart';

/// A plan as the server sells it.
class PlanOffer {
  const PlanOffer({required this.code, required this.audience, required this.name, required this.period, required this.price, required this.purchasable, required this.features});

  factory PlanOffer.fromJson(Map<String, Object?> j) => PlanOffer(
    code: '${j['code'] ?? ''}',
    audience: '${j['audience'] ?? ''}',
    name: '${j['name'] ?? ''}',
    period: '${j['period'] ?? ''}',
    price: '${j['price'] ?? ''}',
    purchasable: j['purchasable'] == true,
    features: <String>[
      if (j['features'] is List)
        for (final f in j['features']! as List) '$f',
    ],
  );

  final String code;

  /// The role it is for (`broker` · `seeker` · `owner` · `developer`).
  final String audience;
  final String name;

  /// `month` · `year` (and what a plan that is not sold by period says).
  final String period;

  /// In riyals, as the server writes it ("280.00").
  final String price;

  /// On sale: a free plan, or one that comes with a contract, is not.
  final bool purchasable;
  final List<String> features;
}

/// GET /packages: the plans, and the single services' prices by their purpose (`featured`, `urgent_listing`…).
class Offers {
  const Offers({required this.plans, required this.oneOff, required this.ownerCommissionPercent});

  factory Offers.fromJson(Map<String, Object?> j) => Offers(
    plans: <PlanOffer>[
      if (j['plans'] is List)
        for (final p in j['plans']! as List)
          if (p is Map) PlanOffer.fromJson(p.cast<String, Object?>()),
    ],
    oneOff: j['one_off'] is Map ? <String, String>{for (final e in (j['one_off']! as Map).entries) '${e.key}': '${e.value}'} : const <String, String>{},
    ownerCommissionPercent: '${j['owner_commission_percent'] ?? ''}',
  );

  final List<PlanOffer> plans;
  final Map<String, String> oneOff;

  /// Zon's share of an owner's sale, in percent ("0.5").
  final String ownerCommissionPercent;

  /// The plans [role] can buy, in the server's order.
  List<PlanOffer> onSaleFor(String role) => <PlanOffer>[
    for (final p in plans)
      if (p.audience == role && p.purchasable) p,
  ];
}

/// GET /subscription: his plan now.
class MyPlan {
  const MyPlan({required this.code, required this.name, required this.subscribed, required this.balances, this.endsAt});

  factory MyPlan.fromJson(Map<String, Object?> j) {
    final plan = j['plan'] is Map ? (j['plan']! as Map).cast<String, Object?>() : const <String, Object?>{};
    return MyPlan(
      code: '${plan['code'] ?? ''}',
      name: '${plan['name'] ?? ''}',
      subscribed: plan['subscribed'] == true,
      endsAt: DateTime.tryParse('${j['ends_at'] ?? ''}')?.toLocal(),
      balances: j['balances'] is Map
          ? <String, int>{
              for (final e in (j['balances']! as Map).entries)
                if (e.value is int) '${e.key}': e.value as int,
            }
          : const <String, int>{},
    );
  }

  final String code;
  final String name;

  /// A paid period is running (a free plan is a plan too, and is not one).
  final bool subscribed;
  final DateTime? endsAt;

  /// What is left of each boost the plan includes (`featured`, `urgent_listing`, `urgent_request`).
  final Map<String, int> balances;
}

class InvoiceRow {
  const InvoiceRow({required this.number, required this.total, this.issuedAt});

  final String number;

  /// In riyals, as the server writes it.
  final String total;
  final DateTime? issuedAt;
}

/// GET /store/products: what the account may buy in the app.
class StoreShelf {
  const StoreShelf({required this.accountToken, required this.products, required this.simulated});

  /// Attached to each purchase: the store says back whose it is.
  final String accountToken;

  /// (product id, purpose, package).
  final List<(String, String, String?)> products;

  /// The stores this server simulates (`apple`, `google`).
  final Set<String> simulated;

  /// The product that sells [purpose] (and, for a plan, [package]); null when the account may not buy it.
  String? productFor(String purpose, {String? package}) {
    for (final (id, p, pkg) in products) {
      if (p == purpose && (package == null || pkg == package)) return id;
    }
    return null;
  }
}

/// One purchase on its way: made once, and sent again unchanged while its answer is not known.
class StoreOrder {
  const StoreOrder({required this.store, required this.productId, required this.reference, this.target});

  final String store;
  final String productId;
  final String reference;
  final Map<String, Object?>? target;
}

abstract final class ServerStore {
  /// The store of this phone.
  static String get platformStore => defaultTargetPlatform == TargetPlatform.iOS ? 'apple' : 'google';

  static Future<Offers> offers() async => Offers.fromJson((await Api.client!.get('packages')).map);

  static Future<MyPlan> plan() async => MyPlan.fromJson((await Api.client!.get('subscription')).map);

  /// His invoices, newest first (the first page: the last twenty).
  static Future<List<InvoiceRow>> invoices() async => <InvoiceRow>[
    for (final row in (await Api.client!.get('invoices')).list)
      InvoiceRow(number: '${row['number'] ?? ''}', total: '${row['total'] ?? ''}', issuedAt: DateTime.tryParse('${row['issued_at'] ?? ''}')?.toLocal()),
  ];

  static Future<StoreShelf> shelf() async {
    final j = (await Api.client!.get('store/products')).map;
    return StoreShelf(
      accountToken: '${j['account_token'] ?? ''}',
      products: <(String, String, String?)>[
        if (j['products'] is List)
          for (final p in j['products']! as List)
            if (p is Map) ('${p['product_id'] ?? ''}', '${p['purpose'] ?? ''}', p['package'] is String ? p['package'] as String : null),
      ],
      simulated: <String>{
        if (j['simulated'] is List)
          for (final s in j['simulated']! as List) '$s',
      },
    );
  }

  /// Before the sheet: an ApiError when [productId] cannot be bought now for [target] (409 already running…).
  static Future<void> check(String productId, {Map<String, Object?>? target, required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.post('store/check', <String, Object?>{'product_id': productId, 'target': ?target});
  }

  /// A purchase of [productId] from this phone's store when the server simulates it; null when it does not —
  /// the store's real sheet is not in this build.
  static StoreOrder? simulatedOrder(StoreShelf shelf, String productId, {Map<String, Object?>? target}) => shelf.simulated.contains(platformStore)
      ? StoreOrder(store: platformStore, productId: productId, reference: 'sim:${shelf.accountToken}:${newId(8)}', target: target)
      : null;

  /// After the sheet: the payment's status as the server applied it — `paid`, or `unapplied` (the store charged,
  /// and what it paid for could not be given: the store refunds it). An ApiError 422 when the store does not
  /// vouch for the purchase.
  static Future<String> purchase(StoreOrder order, {required int epoch}) async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('store/purchases', <String, Object?>{
      'store': order.store,
      'product_id': order.productId,
      'reference': order.reference,
      'target': ?order.target,
    });
    return '${r.map['status'] ?? ''}';
  }
}
