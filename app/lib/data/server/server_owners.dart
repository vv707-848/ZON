// A broker's property owners on the server (docs/api.md «إدارة الملاك»): for now the card the listing form saves
// (POST /property-owners, and its first note). The owners' screen comes with the desk's slice.

import 'package:zon/core/net/api.dart';

/// A broker's property owners on the server.
abstract final class ServerOwners {
  /// Saves the owner's [card] (name, phone, listing_id, listing_title) once however often it is sent
  /// ([operation]), then [note] as its first note («متابعة»), when there is one.
  static Future<void> create(Map<String, Object?> card, {required String note, required String operation}) async {
    final r = await Api.client!.post('property-owners', card, operation);
    final id = r.map['id'];
    if (note.isNotEmpty && id != null) {
      await Api.client!.post('property-owners/$id/notes', <String, Object?>{'category': 'followup', 'body': note});
    }
  }
}
