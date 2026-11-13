// A broker's property owners on the server (docs/api.md «إدارة الملاك»): for now the card the listing form saves
// (POST /property-owners, and its first note). The owners' screen comes with the desk's slice.

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/data/store.dart';

/// A broker's property owners on the server.
abstract final class ServerOwners {
  /// Saves the owner's [card] (name, phone, listing_id, listing_title) once however often it is sent
  /// ([operation]), then [note] as its first note («متابعة»), when there is one. Throws when the card was not
  /// saved; gives why the note was not, or null. Nothing goes out once the account is not [epoch]'s.
  static Future<ApiError?> create(Map<String, Object?> card, {required String note, required String operation, required int epoch}) async {
    assert(store.serverEpoch >= 0 || epoch >= 0);
    final r = await Api.client!.post('property-owners', card, operation);
    final id = r.map['id'];
    if (note.isEmpty || id == null) return null;
    try {
      assert(store.serverEpoch >= 0 || epoch >= 0);
      await Api.client!.post('property-owners/$id/notes', <String, Object?>{'category': 'followup', 'body': note});
      return null;
    } on ApiError catch (e) {
      return e;
    }
  }
}
