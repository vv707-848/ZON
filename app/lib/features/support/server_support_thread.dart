// A support thread with «فريق زون» (ServerSupport), `/support/<reference>` — from «الدعم» in «الإعدادات», or from
// a notice («رد فريق زون على تذكرتك» · «أُغلقت تذكرتك»).
//
// * Every message as the server keeps it; staff are «فريق زون», never a name. Opening it marks it read on the
//   server, and while it is open it asks again every little while.
// * His answer is sent once however often tried; until the server takes it, it shows as «يُرسل…», and when it
//   cannot, his text comes back to the bar with why.
// * Closed: what was done shows under the thread; an answer opens it again, and the bar says so.
// * Another account signed in under it: nothing of this one stays shown.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/server/server_support.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/server_support_view.dart';
import 'package:zon/shared/widgets/talk.dart';

/// Where «الدعم» is.
const String kSupportHome = '/account/settings?tab=support';

class ServerSupportThread extends StatefulWidget {
  const ServerSupportThread({super.key, required this.reference});

  final String reference;

  static const Duration every = Duration(seconds: 20);

  @override
  State<ServerSupportThread> createState() => _ServerSupportThreadState();
}

class _ServerSupportThreadState extends State<ServerSupportThread> {
  final TextEditingController _bar = TextEditingController();
  final OperationKey _op = OperationKey('reply');
  SupportTicket? _ticket;
  bool _loading = true;
  String? _error;
  String? _sending;
  Timer? _poll;
  bool _fetching = false;
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    store.addListener(_onAccount);
    unawaited(_start());
  }

  @override
  void dispose() {
    _poll?.cancel();
    store.removeListener(_onAccount);
    _bar.dispose();
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    _poll?.cancel();
    _bar.clear();
    setState(() {
      _ticket = null;
      _sending = null;
      _error = null;
    });
    unawaited(Nav.replace(context, kSupportHome));
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _start() async {
    final epoch = _epoch;
    await _fetch(epoch);
    if (_current(epoch) && _ticket != null) _poll = Timer.periodic(ServerSupportThread.every, (_) => unawaited(_fetch(epoch)));
  }

  Future<void> _fetch(int epoch) async {
    if (_fetching || _sending != null) return;
    _fetching = true;
    try {
      final ticket = await ServerSupport.show(widget.reference);
      if (_current(epoch) && _sending == null) {
        setState(() {
          _ticket = ticket;
          _loading = false;
        });
      }
    } on ApiError catch (e) {
      // a failed refresh leaves the thread shown as it was
      if (_current(epoch) && _ticket == null) {
        setState(() {
          _loading = false;
          _error = e.forUser;
        });
      }
    } finally {
      _fetching = false;
    }
  }

  Future<void> _send(String typed) async {
    final text = typed.trim();
    if (text.isEmpty || _sending != null || _ticket == null) return;
    final epoch = _epoch;
    setState(() {
      _sending = text;
      _error = null;
    });
    _bar.clear();
    try {
      final ticket = await ServerSupport.reply(
        widget.reference,
        text,
        operation: _op.forBody(<String, Object?>{'t': widget.reference, 'body': text}),
        epoch: epoch,
      );
      _op.done();
      if (!_current(epoch)) return;
      setState(() {
        _ticket = ticket;
        _sending = null;
      });
    } on ApiError catch (e) {
      if (_current(epoch)) {
        setState(() {
          _sending = null;
          _error = e.forUser;
          if (_bar.text.isEmpty) _bar.text = text; // his text back, to try again
        });
      }
    } on AccountChanged {
      // nothing more under the next account
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = _ticket;
    final messages = t?.messages ?? const <SupportMsg>[];
    return AppShell(
      scrollable: false,
      child: TalkPage(
        count: messages.length + (_sending == null ? 0 : 1),
        head: TalkHead(
          avatar: const LucideIcon(Lu.messageCircle, size: 18, color: AppColors.white),
          name: 'فريق زون',
          sub: t == null ? widget.reference : '${t.title} · ${t.reference} · ${kTicketStatusLabel[t.status] ?? t.status}',
          back: kSupportHome,
        ),
        log: <Widget>[
          if (_loading) const LoadingBox(),
          if (t?.aboutListing case final int listing)
            Tap(
              onTap: () => Nav.go(context, '/listings/$listing'),
              child: Tx('بخصوص: ${t!.aboutTitle ?? 'إعلان'}', style: ts(13, w: 600, lh: 19.5, c: AppColors.teal), align: TextAlign.center),
            ),
          for (final m in messages) TalkBubble(text: m.body, mine: !m.zon),
          if (_sending case final String s) Opacity(opacity: 0.6, child: TalkBubble(text: '$s\nيُرسل…', mine: true)),
          if (t != null && t.closed && _sending == null)
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(18)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Tx('أُغلقت التذكرة', style: ts(14, w: 700, lh: 21, c: AppColors.ink)),
                  if (t.resolution case final String r) ...<Widget>[const SizedBox(height: 4), Tx(r, style: ts(14, lh: 21, c: AppColors.ink))],
                  const SizedBox(height: 6),
                  Tx('إن بقي ما يحتاج متابعة فاكتب هنا، وتُفتح التذكرة من جديد.', style: ts(12, lh: 18, c: AppColors.muted)),
                ],
              ),
            ),
          if (_error case final String e) FormError(e),
        ],
        bar: t == null ? const SizedBox.shrink() : TalkBar(controller: _bar, onSend: (s) => unawaited(_send(s)), photos: false),
      ),
    );
  }
}

/// `/support` — a visitor's ticket (with his name and number); signed in, «الدعم» in «الإعدادات» with his tickets.
class SupportScreen extends StatelessWidget {
  const SupportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    if (store.readSession() != null) return const RedirectTo(kSupportHome);
    return AppShell(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            BackLink(onTap: () => Nav.go(context, '/account')),
            const SizedBox(height: 12),
            Tx('الدعم', style: ts(20, w: 700, lh: 28, c: AppColors.ink)),
            const SizedBox(height: 6),
            Tx('شكوى أو اقتراح أو استفسار. لمتابعة تذاكرك ومراسلة فريق زون فيها، سجّل الدخول.', style: ts(14, lh: 21, c: AppColors.muted)),
            const SizedBox(height: 16),
            const ServerSupportView(),
          ],
        ),
      ),
    );
  }
}
