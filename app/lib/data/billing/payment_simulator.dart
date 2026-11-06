// SIMULATED payments — this build has NO payment gateway.
//
// Nothing here contacts a bank, Apple, Google or any provider, and no money
// moves. Every "pay" button of the app goes through [PaymentSimulator.charge]
// so the place to plug the real payment is this one function. When the real
// service is connected, the server verifies the payment and grants the
// subscription / boost / licence; the app only shows the result.
//
// How a purchase is classified by Apple / Google (in-app purchase or an
// external payment) is decided when the real service is built and reviewed —
// see docs/ARCHITECTURE.md. This file makes no promise about it.

import 'package:flutter/foundation.dart';

import 'package:zon/core/simulations.dart';

export 'package:zon/core/simulations.dart';

/// Outcome of a simulated charge.
enum PaymentStatus { paid, failed, unavailable }

/// What [PaymentSimulator.charge] returns.
class PaymentReceipt {
  const PaymentReceipt(this.status, {this.reference, this.amount = 0});

  final PaymentStatus status;

  /// `SIM-…` for a simulated payment.
  final String? reference;
  final int amount;

  bool get ok => status == PaymentStatus.paid;
}

/// The single entry point for payments (simulation — see the file comment).
abstract final class PaymentSimulator {
  /// Tests can force the next result (e.g. a failed payment).
  @visibleForTesting
  static PaymentStatus? nextStatus;

  /// Delay that imitates a network round trip.
  @visibleForTesting
  static Duration latency = const Duration(milliseconds: 400);

  /// Tests can hold every charge until this future completes (e.g. to
  /// switch the signed-in account while a payment is on its way).
  @visibleForTesting
  static Future<void>? gate;

  static int _seq = 0;

  /// "Charges" [amount] SAR for [purpose]. Never moves money.
  static Future<PaymentReceipt> charge({required int amount, required String purpose}) async {
    if (!kSimulations) return const PaymentReceipt(PaymentStatus.unavailable);
    if (latency > Duration.zero) await Future<void>.delayed(latency);
    final hold = gate;
    if (hold != null) await hold;
    final forced = nextStatus;
    nextStatus = null;
    if (forced != null && forced != PaymentStatus.paid) return PaymentReceipt(forced, amount: amount);
    _seq++;
    return PaymentReceipt(PaymentStatus.paid, reference: 'SIM-${DateTime.now().millisecondsSinceEpoch}-$_seq', amount: amount);
  }
}
