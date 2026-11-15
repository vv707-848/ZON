// The store's own purchase sheet (App Store · Google Play) through the official in_app_purchase plugin.
//
// The phone only opens the sheet and hands the server what the store gave: the transaction id on the App Store,
// the purchase token on Google Play. The server reads the purchase back from the store itself and applies it
// (lib/data/server/server_store.dart); the phone never decides that something was paid. A purchase is finished
// on the phone (StoreKit's finish, Play's acknowledgement) only after the server applied it — an unfinished one is
// delivered again by the store on the next start, and sent again from there.
//
// The account's store token (a UUID) goes with every purchase: StoreKit 2 keeps it as appAccountToken and Play as
// obfuscatedAccountId, and the server applies a purchase only to the account it names.

import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:in_app_purchase/in_app_purchase.dart';

/// What the store gave for one purchase.
@immutable
class StoreReceipt {
  const StoreReceipt({required this.productId, required this.reference, this.native});

  final String productId;

  /// The transaction id (App Store) or the purchase token (Google Play): what the server reads back.
  final String reference;

  /// The plugin's own record, to finish it with.
  final Object? native;
}

/// How the sheet ended.
sealed class SheetOutcome {
  const SheetOutcome();
}

final class SheetBought extends SheetOutcome {
  const SheetBought(this.receipt);

  final StoreReceipt receipt;
}

/// He closed the sheet: nothing was bought.
final class SheetCancelled extends SheetOutcome {
  const SheetCancelled();
}

/// Waiting for the store (a parent's approval, a slow payment method): it arrives later, by itself.
final class SheetPending extends SheetOutcome {
  const SheetPending();
}

final class SheetFailed extends SheetOutcome {
  const SheetFailed(this.message);

  final String message;
}

abstract class StoreSheet {
  /// The store can sell on this phone now (signed in to the store, billing available).
  Future<bool> available();

  /// Opens the store's sheet for [productId]. [subscription]: a plan (kept) — else a boost (bought again later).
  Future<SheetOutcome> buy(String productId, {required String accountToken, required bool subscription});

  /// Tells the store the purchase was delivered.
  Future<void> finish(StoreReceipt receipt);

  /// Purchases the store delivers outside a sheet: unfinished ones on start, a pending one once approved.
  Stream<StoreReceipt> get delivered;
}

/// The real sheet, on an iPhone or an Android phone.
class InAppStoreSheet implements StoreSheet {
  InAppStoreSheet() : _iap = InAppPurchase.instance {
    _subscription = _iap.purchaseStream.listen(_onUpdates); // as early as possible: the plugin's own rule
  }

  final InAppPurchase _iap;
  late final StreamSubscription<List<PurchaseDetails>> _subscription;
  final StreamController<StoreReceipt> _delivered = StreamController<StoreReceipt>.broadcast();
  final Map<String, Completer<SheetOutcome>> _waiting = <String, Completer<SheetOutcome>>{};

  static bool get _apple => defaultTargetPlatform == TargetPlatform.iOS;

  @override
  Stream<StoreReceipt> get delivered => _delivered.stream;

  @override
  Future<bool> available() => _iap.isAvailable();

  @override
  Future<SheetOutcome> buy(String productId, {required String accountToken, required bool subscription}) async {
    if (_waiting.containsKey(productId)) return const SheetFailed('شراء هذا المنتج جارٍ بالفعل.');
    final found = await _iap.queryProductDetails(<String>{productId});
    if (found.error != null || found.productDetails.isEmpty) return const SheetFailed('هذا المنتج غير متاح في المتجر الآن.');
    final param = PurchaseParam(productDetails: found.productDetails.first, applicationUserName: accountToken);
    final waiting = _waiting[productId] = Completer<SheetOutcome>();
    try {
      final opened = subscription
          ? await _iap.buyNonConsumable(purchaseParam: param)
          // consumed by the server once applied (Play) — never on the phone before it
          : await _iap.buyConsumable(purchaseParam: param, autoConsume: false);
      if (!opened) _settle(productId, const SheetFailed('لم يفتح المتجر نافذة الشراء.'));
    } catch (_) {
      _settle(productId, const SheetFailed('لم يفتح المتجر نافذة الشراء.'));
    }
    return waiting.future;
  }

  void _settle(String productId, SheetOutcome outcome) {
    final waiting = _waiting.remove(productId);
    if (waiting != null && !waiting.isCompleted) waiting.complete(outcome);
  }

  void _onUpdates(List<PurchaseDetails> updates) {
    for (final p in updates) {
      switch (p.status) {
        case PurchaseStatus.pending:
          _settle(p.productID, const SheetPending());
        case PurchaseStatus.canceled:
          _settle(p.productID, const SheetCancelled());
        case PurchaseStatus.error:
          _settle(p.productID, SheetFailed(p.error?.message.isNotEmpty == true ? 'رفض المتجر الشراء.' : 'تعذّر الشراء من المتجر.'));
        case PurchaseStatus.purchased || PurchaseStatus.restored:
          final reference = _apple ? (p.purchaseID ?? '') : p.verificationData.serverVerificationData;
          if (reference.isEmpty) {
            _settle(p.productID, const SheetFailed('لم يعطِ المتجر رقماً للشراء.'));
            continue;
          }
          final receipt = StoreReceipt(productId: p.productID, reference: reference, native: p);
          if (_waiting.containsKey(p.productID)) {
            _settle(p.productID, SheetBought(receipt));
          } else {
            _delivered.add(receipt);
          }
      }
    }
  }

  @override
  Future<void> finish(StoreReceipt receipt) async {
    final p = receipt.native;
    if (p is PurchaseDetails && p.pendingCompletePurchase) await _iap.completePurchase(p);
  }

  @visibleForTesting
  Future<void> dispose() async {
    await _subscription.cancel();
    await _delivered.close();
  }
}
