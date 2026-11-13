// A broker's property owners on the server (docs/api.md «إدارة الملاك»): the card the listing form saves, and his
// owners' book — each with his listing, his notes and their AI summary (ServerSummaries, scope `owner`).
//
//   GET    /property-owners                his owners, newest first
//   POST   /property-owners                an owner's card (once however often tried), then its first note
//   DELETE /property-owners/{id}
//   GET · POST /property-owners/{id}/notes · DELETE /property-owners/{id}/notes/{note}
//
// Nothing goes out once another account is signed in (Store.stillAccount).

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_desk.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/store.dart';

/// A broker's property owners on the server.
abstract final class ServerOwners {
  static String appId(int id) => 'O$id';

  /// His notes on an owner (`O12`).
  static const DeskNotes notes = DeskNotes('property-owners');

  /// An owner of his book as the app shows it; his listing (`L12`) when he named it.
  static Owner fromJson(Map<String, Object?> j) => Owner(
    id: appId(j['id'] is int ? j['id']! as int : 0),
    name: '${j['name'] ?? ''}',
    phone: j['phone'] is String ? j['phone']! as String : '',
    listingTitle: j['listing_title'] is String ? j['listing_title']! as String : '',
    listingId: j['listing_id'] is int ? ServerListings.listingId(j['listing_id']! as int) : null,
    notes: const <Note>[],
  );

  static Future<List<Owner>> list() async => <Owner>[for (final row in (await Api.client!.get('property-owners')).list) fromJson(row)];

  static Future<void> remove(String id, {required int epoch}) async {
    store.stillAccount(epoch);
    await Api.client!.delete('property-owners/${id.substring(1)}');
  }

  /// Saves the owner's [card] (name, phone, listing_id, listing_title) once however often it is sent
  /// ([operation]), then [note] as its first note («متابعة») — once too: its key follows the card's. Throws when
  /// the card was not saved; gives why the note was not, or null. Nothing goes out once the account is not
  /// [epoch]'s.
  static Future<ApiError?> create(Map<String, Object?> card, {required String note, required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('property-owners', card, operation);
    final id = r.map['id'];
    if (note.isEmpty || id is! int) return null;
    try {
      await notes.add(appId(id), 'تفكير', note, operation: '$operation-note', epoch: epoch);
      return null;
    } on ApiError catch (e) {
      return e;
    }
  }
}
