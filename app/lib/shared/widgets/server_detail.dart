// A listing or project of the server, opened fresh: its detail (every photo, the licence, the phones of a
// signed-in viewer, a project's units) is fetched each time its page opens — photo addresses are signed for an
// hour, and a price may have changed — then [ServerDetail.builder] shows it as the page always does. A search
// request opens the same way ([ServerDetail.request]).

import 'package:flutter/material.dart';

import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_requests.dart';
import 'package:zon/data/store.dart';

/// Fetches the server's row [id] (`L…` or `P…`) in detail, then shows [builder].
class ServerDetail extends StatefulWidget {
  const ServerDetail({super.key, required this.id, required this.title, required this.missing, required this.builder}) : _request = false;

  /// A search request (`R…`), fetched fresh into the store (Store.keepServerRequests).
  const ServerDetail.request({super.key, required this.id, required this.title, required this.missing, required this.builder}) : _request = true;

  final String id;
  final bool _request;

  /// The page's title while it loads or when it cannot be shown.
  final String title;

  /// What a row the server no longer shows says (deleted, sold, hidden).
  final String missing;

  final WidgetBuilder builder;

  @override
  State<ServerDetail> createState() => _ServerDetailState();
}

class _ServerDetailState extends State<ServerDetail> {
  late Future<void> _load = _fetch();
  bool _gone = false;
  String? _error;

  /// The account the page was fetched for: when it changes (sign-out, another account), the page is fetched
  /// again for the new one (what the server shows depends on who asks).
  int _epoch = store.serverEpoch;

  Future<void> _fetch() async {
    final epoch = _epoch = store.serverEpoch;
    try {
      if (widget._request) {
        store.keepServerRequests(<RequestItem>[await ServerRequests.detail(widget.id)], epoch: epoch);
      } else {
        store.keepServer(<Listing>[await ServerListings.detail(widget.id)], epoch: epoch, detailed: true);
      }
    } on ApiError catch (e) {
      if (e.status == 404) {
        _gone = true;
      } else {
        _error = e.forUser;
      }
    }
  }

  void _retry() => setState(() {
    _error = null;
    _load = _fetch();
  });

  @override
  Widget build(BuildContext context) => ListenableBuilder(listenable: store, builder: _page);

  Widget _page(BuildContext context, Widget? _) {
    if (_epoch != store.serverEpoch) {
      _gone = false;
      _error = null;
      _load = _fetch();
    }
    return FutureBuilder<void>(
      future: _load,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return AppShell(title: widget.title, child: const LoadingBox());
        }
        if (_error != null) {
          return AppShell(
            title: widget.title,
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: EmptyBox(title: 'تعذّر التحميل', text: _error!, textStyle: emptyTextStyle(), action: 'أعد المحاولة', onAction: _retry),
            ),
          );
        }
        if (_gone || (widget._request ? store.requestById(widget.id) : store.listingById(widget.id)) == null) {
          return AppShell(
            title: widget.title,
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Tx(widget.missing, style: ts(15, lh: 21.5, c: AppColors.ink)),
            ),
          );
        }
        return widget.builder(context);
      },
    );
  }
}
