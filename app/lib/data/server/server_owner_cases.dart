// The owner's path with a server (docs/api.md «عرض المالك»), one property at a time:
//
//   GET  /owner-cases          his cases
//   POST /owner-cases          a new one by its deed: the brokerage contract is filed with REGA
//   GET  /owner-cases/{id}     where it stands — the server asks REGA for the contract's answer on every look
//
// contract (he approves it through Nafath) → fee (paid in the app: ServerPayments) → licence (issued by REGA) →
// listing (POST /listings with `owner_case_id`) → active → closed (sold).

import 'package:zon/core/net/api.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/store.dart';

/// One of his cases, as the server tells it.
class ServerOwnerCase {
  const ServerOwnerCase({
    required this.id,
    required this.deedNumber,
    required this.stage,
    required this.contractStatus,
    required this.commissionPercent,
    this.licenceNumber,
    this.listingId,
    this.feePrice,
  });

  factory ServerOwnerCase.fromJson(Map<String, Object?> j) {
    final licence = j['licence'] is Map ? (j['licence']! as Map).cast<String, Object?>() : null;
    final next = j['next'] is Map ? (j['next']! as Map).cast<String, Object?>() : null;
    return ServerOwnerCase(
      id: j['id'] is int ? j['id']! as int : 0,
      deedNumber: '${j['deed_number'] ?? ''}',
      stage: '${j['stage'] ?? ''}',
      contractStatus: '${j['contract_status'] ?? ''}',
      commissionPercent: double.tryParse('${j['commission_percent']}') ?? 0,
      licenceNumber: licence == null ? null : '${licence['number']}',
      listingId: j['listing_id'] is int ? 'L${j['listing_id']}' : null,
      feePrice: next?['action'] == 'pay_fee' ? '${next?['price'] ?? ''}' : null,
    );
  }

  final int id;
  final String deedNumber;

  /// `contract` · `fee` · `licence` · `listing` · `active` · `closed`.
  final String stage;

  /// `submitted` · `approved` · `rejected`…
  final String contractStatus;
  final double commissionPercent;
  final String? licenceNumber;
  final String? listingId;

  /// The licence fee in riyals as the server writes it ("50.00"), while it is due.
  final String? feePrice;

  /// Still on its way to a listing (not published, not closed).
  bool get open => const <String>{'contract', 'fee', 'licence', 'listing'}.contains(stage);
  bool get refused => stage == 'contract' && contractStatus != 'submitted' && contractStatus != 'approved';

  /// As the owner's path shows it (owner_cases.dart's OwnerCase).
  OwnerCase toCase() => OwnerCase(
    id: '$id',
    createdAt: '',
    deedNo: deedNumber,
    contractStatus: contractStatus,
    feePaid: const <String>{'licence', 'listing', 'active', 'closed'}.contains(stage),
    licenceNumber: licenceNumber,
    listingId: listingId,
    commissionPercent: commissionPercent,
  );
}

abstract final class ServerOwnerCases {
  /// His case still on its way, or null (the newest first).
  static Future<ServerOwnerCase?> current() async {
    for (final row in (await Api.client!.get('owner-cases')).list) {
      final c = ServerOwnerCase.fromJson(row);
      if (c.open) return c;
    }
    return null;
  }

  /// A new case for [deedNumber]: its contract filed with REGA. [operation] is the same on every try of one send;
  /// nothing goes out once the account is not [epoch]'s.
  static Future<ServerOwnerCase> open(String deedNumber, {required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    return ServerOwnerCase.fromJson((await Api.client!.post('owner-cases', <String, Object?>{'deed_number': deedNumber}, operation)).map);
  }

  /// Where [id] stands now (the server asks REGA for the contract's answer).
  static Future<ServerOwnerCase> get(int id) async => ServerOwnerCase.fromJson((await Api.client!.get('owner-cases/$id')).map);
}
