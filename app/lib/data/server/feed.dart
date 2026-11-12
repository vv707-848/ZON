// «الرئيسية» with a server: the feed's pages for the filter card's query (GET /feed, ServerListings.feed), read
// as every paged list of the server is (ServerPager).

import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_pages.dart';
import 'package:zon/data/store.dart';

/// The feed of the home screen, one query at a time.
class Feed extends ServerPager<FeedQuery, Listing> {
  Feed()
    : super(
        fetch: (query, page) => ServerListings.feed(query, page: page),
        idOf: (l) => l.id,
        keep: store.keepServer,
      );
}
