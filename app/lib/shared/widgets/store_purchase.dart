// Buying a plan or a boost in the app with a server (lib/data/server/server_store.dart): the product his role may
// buy → the server's check that it can be bought now → the price, and the payment only when he taps pay → the
// purchase sent to the server, which applies it on the store's word.
//
// Nothing changes before the server says «paid». The purchase made for one dialog is sent unchanged on every try,
// so a try whose answer was lost is the same payment. The store's real sheet is not in this build: a server whose
// store is not simulated is told so plainly, and nothing is sent.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/server/server_store.dart';
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
    if (context.mounted && epoch == store.serverEpoch)
      await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يبدأ الشراء', message: e.forUser);
    return false;
  }
  if (!context.mounted || epoch != store.serverEpoch) return false;
  if (product == null) {
    await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يبدأ الشراء', message: 'هذه الخدمة لا تُباع لحسابك الآن.');
    return false;
  }
  final order = ServerStore.simulatedOrder(shelf, product, target: target);
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
