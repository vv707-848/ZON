// Buying a plan or a boost in the app with a server (lib/data/server/server_store.dart): the product his role may
// buy → the server's check that it can be bought now → the price, and the payment only when he taps pay → the
// purchase sent to the server, which applies it on the store's word.
//
// Nothing changes before the server says «paid». The purchase made for one dialog is sent unchanged on every try,
// so a try whose answer was lost is the same payment.
//
// A store the server simulates: the page's own dialog, which says the payment is a trial. Otherwise the store's
// own sheet is the confirmation (it shows the price and asks for the store's password or face): what the store
// gave goes to the server, and he is told what the server applied — or, when its answer did not come, that the
// purchase is applied once the store delivers it again. On a phone without the store's sheet nothing is sent.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/server/server_store.dart';
import 'package:zon/data/server/store_sheet.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/billing_flow.dart';

/// Buys [purpose] (`subscription` with its [package], or a boost for its [target]) and says whether the server
/// applied it. [price] is in riyals as the server wrote it ("100.00"); null when it did not say. The price is
/// added to [title] when known.
Future<bool> buyFromStore(
  BuildContext context, {
  required String purpose,
  String? package,
  Map<String, Object?>? target,
  required String icon,
  required String title,
  required String message,
  required String? price,
}) async {
  final epoch = store.serverEpoch;
  final StoreShelf shelf;
  final String? product;
  try {
    shelf = await ServerStore.shelf();
    product = shelf.productFor(purpose, package: package);
    if (product != null) await ServerStore.check(product, target: target, epoch: epoch);
  } on AccountChanged {
    return false;
  } on ApiError catch (e) {
    if (context.mounted && epoch == store.serverEpoch) {
      await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يبدأ الشراء', message: e.forUser);
    }
    return false;
  }
  if (!context.mounted || epoch != store.serverEpoch) return false;
  if (product == null) {
    await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يبدأ الشراء', message: 'هذه الخدمة لا تُباع لحسابك الآن.');
    return false;
  }
  final order = ServerStore.simulatedOrder(shelf, product, target: target);
  final sheet = ServerStore.sheet;
  if (order == null && sheet != null && await sheet.available()) {
    if (!context.mounted) return false;
    return _fromSheet(context, sheet, shelf, product, purpose: purpose, target: target, epoch: epoch);
  }
  if (!context.mounted) return false;
  final amount = double.tryParse(price ?? '')?.round();
  return showPayDialog(
    context,
    icon: icon,
    title: amount == null ? title : '$title — ${riyals(amount)}',
    message: message,
    amount: amount ?? 0,
    payLabel: amount == null ? 'ادفع' : null,
    simulated: order != null,
    unavailable: 'الشراء من المتجر غير متاح في هذه النسخة بعد. لم يُخصم شيء ولم يتغير شيء.',
    pay: () async {
      if (order == null) return false;
      try {
        return await ServerStore.purchase(order, epoch: epoch) == 'paid';
      } on ApiError {
        return false;
      } on AccountChanged {
        return false;
      }
    },
  );
}

/// The store's own sheet, then the server.
Future<bool> _fromSheet(
  BuildContext context,
  StoreSheet sheet,
  StoreShelf shelf,
  String product, {
  required String purpose,
  required Map<String, Object?>? target,
  required int epoch,
}) async {
  final account = store.readSession()?.id;
  if (account == null) return false;
  ServerStore.remember(product, account: account, target: target);
  final outcome = await sheet.buy(product, accountToken: shelf.accountToken, subscription: purpose == 'subscription');
  if (!context.mounted) return false;
  Future<void> say(String title, String message, {bool ok = false}) =>
      showAppNotice(context, icon: ok ? Lu.check : Lu.circleAlert, title: title, message: message);
  switch (outcome) {
    case SheetCancelled():
      return false;
    case SheetPending():
      await say('بانتظار المتجر', 'لم يكتمل الدفع بعد. يُطبَّق الشراء حين يؤكده المتجر، ولم يتغير شيء قبل ذلك.');
      return false;
    case SheetFailed(:final message):
      await say('لم يتم الشراء', '$message لم يُخصم شيء ولم يتغير شيء.');
      return false;
    case SheetBought(:final receipt):
      try {
        final status = await ServerStore.deliver(receipt, epoch: epoch);
        if (!context.mounted) return status == 'paid';
        if (status == 'paid') return true;
        await say(
          'لم يُطبَّق الشراء',
          status == 'unapplied'
              ? 'دُفع في المتجر ولم يمكن تطبيقه الآن، ويُسترد المبلغ من المتجر.'
              : 'لم يُطبَّق الشراء على هذا الحساب.',
        );
        return false;
      } on AccountChanged {
        return false;
      } on ApiError catch (e) {
        if (!context.mounted) return false;
        // a lost answer or a refusal: the purchase stays with the store, unfinished, and is sent again
        await say('لم يصل تأكيد زون', e.status == 0 ? 'دُفع في المتجر، ويُطبَّق الشراء حين يصل الخادم. لا تشترِه مرة ثانية.' : e.forUser);
        return false;
      }
  }
}

