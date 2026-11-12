// `/requests/:id` — one search request with contact actions. With a server it is fetched fresh each time it opens
// (ServerDetail.request): who posted it shows as the viewer's plan lets him see, and «أضف إلى إدارة العملاء» is
// the server's (POST /requests/{id}/to-client).

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_requests.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/ask_card.dart';
import 'package:zon/shared/widgets/server_detail.dart';

/// "a – b" range text saved into the CRM client record.
String _range(double a, double b) {
  final x = (a != 0 && !a.isNaN) ? loc(a) : '';
  final y = (b != 0 && !b.isNaN) ? loc(b) : '';
  if (x.isNotEmpty && y.isNotEmpty) return '$x – $y';
  return x.isNotEmpty ? x : y;
}

/// `/requests/$id` — الطلب
class RequestDetailScreen extends StatefulWidget {
  const RequestDetailScreen({super.key, required this.id});

  final String id;

  @override
  State<RequestDetailScreen> createState() => _RequestDetailScreenState();
}

class _RequestDetailScreenState extends State<RequestDetailScreen> {
  bool added = false;
  bool adding = false;

  @override
  Widget build(BuildContext context) {
    if (Api.on && ServerRequests.isServer(widget.id)) {
      return ServerDetail.request(id: widget.id, title: 'الطلب', missing: 'الطلب غير موجود.', builder: _page);
    }
    return _page(context);
  }

  /// «أضف إلى إدارة العملاء» on the server: the client is created there (without the author's phone).
  Future<void> _toClient(RequestItem r) async {
    setState(() => adding = true);
    try {
      await ServerRequests.toClient(r.id);
      if (mounted) setState(() => added = true);
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.status == 402) {
        unawaited(Nav.go(context, '/account/plans'));
      } else {
        await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يُضف إلى العملاء', message: e.forUser);
      }
    } finally {
      if (mounted) setState(() => adding = false);
    }
  }

  Widget _page(BuildContext context) {
    final r = store.requestById(widget.id);
    final user = store.readSession();
    if (r == null) {
      return AppShell(
        title: 'الطلب',
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Tx('الطلب غير موجود.', style: ts(15, lh: 21.5, c: AppColors.ink)),
        ),
      );
    }
    final mine = store.ownsRequest(r);
    final server = ServerRequests.isServer(r.id);
    // with a server, what the viewer's plan opens is what the server sent: a hidden author, «can add to clients»
    final locked = user != null && user.role == 'broker' && !mine && (server ? r.author.isEmpty : !billing.isSubscribed());
    final canCrm = server ? r.canAddToClients == true : user != null && (user.role == 'broker' || user.role == 'developer') && !mine && !locked;

    Widget? actions;
    if (canCrm) {
      actions = AppButton(
        label: added ? 'أُضيف إلى العملاء' : 'أضف إلى إدارة العملاء',
        tone: added ? ButtonTone.tonal : ButtonTone.primary,
        icon: added ? Lu.check : Lu.userPlus,
        size: ButtonSize.large,
        onTap: () {
          if (server) {
            if (!added && !adding) unawaited(_toClient(r));
            return;
          }
          store.saveClient(
            Client(
              id: 'c-${r.id}',
              name: r.author,
              phone: '',
              city: r.city,
              hood: r.neighborhoods.join(' · '),
              types: r.types.map((t) => kTypeLabel[t] ?? '').join(' · '),
              budget: _range(r.priceMin, r.priceMax),
              purpose: r.purpose,
              area: _range(r.areaMin, r.areaMax),
              rooms: _range(r.roomsMin, r.roomsMax),
              description: r.description,
              notes: <Note>[Note(id: 'n-${r.id}', kind: 'تفكير', text: r.description)],
            ),
          );
          setState(() => added = true);
        },
      );
    } else if (locked) {
      actions = AppButton(label: 'فعّل الخدمة لفتح التواصل', size: ButtonSize.large, onTap: () => Nav.go(context, '/account/plans'));
    }

    return AppShell(
      title: 'الطلب',
      child: Container(
        color: const Color(0xFFF4F7F5),
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            AskCardOpen(item: r, hideWho: locked || user == null || (server && r.author.isEmpty), actions: actions),
            if (user == null) ...<Widget>[
              const SizedBox(height: 12),
              Tap(
                onTap: () => Nav.go(context, '/login'),
                child: Container(
                  height: 50,
                  decoration: BoxDecoration(color: const Color(0xFF1B4D3E), borderRadius: BorderRadius.circular(16)),
                  alignment: Alignment.center,
                  child: Tx('التواصل ضمن الخدمة', style: ts(18, w: 600, lh: 27, c: AppColors.white)),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
