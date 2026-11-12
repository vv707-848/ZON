// «الطلبات» with a server: the board's pages for the filter card's query (GET /requests, ServerRequests.board),
// read as every paged list of the server is (ServerPager).

import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_pages.dart';
import 'package:zon/data/server/server_requests.dart';
import 'package:zon/data/store.dart';

/// The board of the requests screen, one query at a time.
class RequestsBoard extends ServerPager<RequestQuery, RequestItem> {
  RequestsBoard()
    : super(
        fetch: (query, page) => ServerRequests.board(query, page: page),
        idOf: (r) => r.id,
        keep: store.keepServerRequests,
      );
}
