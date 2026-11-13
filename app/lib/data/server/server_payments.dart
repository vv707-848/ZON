// A payment in the app with a server (docs/api.md «الدفع»):
//
//   POST /checkout                  `purpose` (and its `target`): the server prices it, and gives what the
//                                   provider's sheet needs (`client`); an unpaid one of the last 30 minutes is reused
//   POST /payments/{id}/confirm     after the sheet: the server asks the provider itself — only its answer counts
//
// The provider's sheet (Moyasar) is not in this build yet: only a server in simulation (`client.simulation`)
// can be paid here, and it is said so plainly. A real provider's payment says it is not available.

import 'package:zon/core/net/api.dart';

/// A payment the server started.
class Checkout {
  const Checkout({required this.id, required this.amount, required this.simulation, this.reference, this.declineReference});

  factory Checkout.fromJson(Map<String, Object?> j) {
    final client = j['client'] is Map ? (j['client']! as Map).cast<String, Object?>() : const <String, Object?>{};
    return Checkout(
      id: '${j['payment_id'] ?? ''}',
      amount: '${j['amount'] ?? ''}',
      simulation: client['simulation'] == true,
      reference: client['reference'] is String ? client['reference']! as String : null,
      declineReference: client['decline_reference'] is String ? client['decline_reference']! as String : null,
    );
  }

  final String id;

  /// In riyals, as the server writes it ("50.00").
  final String amount;

  /// The server's payments are its simulation: nothing is charged.
  final bool simulation;

  /// The simulation's answer to give back (paid), and its declined one.
  final String? reference;
  final String? declineReference;

  /// Whether this build can pay it: only the simulation for now (the provider's sheet is not in it yet).
  bool get payable => simulation && reference != null;
}

abstract final class ServerPayments {
  /// Starts paying [purpose] for [target] (`{'type': 'owner_case', 'id': 7}`).
  static Future<Checkout> start(String purpose, {Map<String, Object?>? target}) async =>
      Checkout.fromJson((await Api.client!.post('checkout', <String, Object?>{'purpose': purpose, 'target': ?target})).map);

  /// The provider's answer for [checkout], asked by the server: true when it is paid.
  static Future<bool> confirm(Checkout checkout, String reference) async =>
      (await Api.client!.post('payments/${checkout.id}/confirm', <String, Object?>{'reference': reference})).map['status'] == 'paid';
}
