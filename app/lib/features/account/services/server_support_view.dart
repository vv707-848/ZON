// «الدعم» with a server (ServerSupport), in «الإعدادات»: a new ticket, and his tickets. A visitor (`/support`)
// sends one with his name and number, and has no list: he has no account to follow it in.
//
// * A complaint, a suggestion or a question, with a subject if he wants one: sent once however often tried, and
//   answered with its reference and the hours «فريق زون» answers within — as the server says them.
// * His tickets as the server keeps them, the latest written first, with what is unread; each opens its thread
//   (`/support/<reference>`).
// * Another account signed in: its own tickets, nothing of the previous one — not even what was being typed.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/utils/input.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/server/server_support.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/widgets/service_form.dart';

/// The fewest letters the server takes in a ticket.
const int kTicketMin = 5;

class ServerSupportView extends StatefulWidget {
  const ServerSupportView({super.key});

  @override
  State<ServerSupportView> createState() => _ServerSupportViewState();
}

class _ServerSupportViewState extends State<ServerSupportView> {
  final TextEditingController _name = TextEditingController();
  final TextEditingController _phone = TextEditingController();
  final TextEditingController _subject = TextEditingController();
  final TextEditingController _body = TextEditingController();
  final OperationKey _op = OperationKey('ticket');
  String _type = 'question';
  final List<SupportTicket> _tickets = <SupportTicket>[];
  int _page = 0;
  bool _hasMore = false;
  bool _loading = true;
  bool _more = false;
  bool _sending = false;
  String? _error;
  String? _formError;
  String? _sent;
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    store.addListener(_onAccount);
    unawaited(_load());
  }

  @override
  void dispose() {
    store.removeListener(_onAccount);
    _name.dispose();
    _phone.dispose();
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    _op.done();
    for (final c in <TextEditingController>[_name, _phone, _subject, _body]) {
      c.clear();
    }
    setState(() {
      _type = 'question';
      _tickets.clear();
      _page = 0;
      _hasMore = false;
      _loading = !_guest;
      _sending = false;
      _error = null;
      _formError = null;
      _sent = null;
    });
    unawaited(_load());
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  /// No account: he writes his name and number, and keeps no list.
  bool get _guest => store.readSession() == null;

  /// The first page again (after a ticket is opened, the new one is at its head).
  Future<void> _load() async {
    final epoch = _epoch;
    if (_guest) {
      _loading = false; // nothing to ask (also from initState, where setState may not be called)
      return;
    }
    try {
      final page = await ServerSupport.list();
      if (!_current(epoch)) return;
      setState(() {
        _tickets
          ..clear()
          ..addAll(page.tickets);
        _page = 1;
        _hasMore = page.hasMore;
        _error = null;
      });
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } finally {
      if (_current(epoch)) setState(() => _loading = false);
    }
  }

  Future<void> _loadMore() async {
    if (_more || !_hasMore) return;
    final epoch = _epoch;
    setState(() => _more = true);
    try {
      final page = await ServerSupport.list(page: _page + 1);
      if (!_current(epoch)) return;
      final known = <String>{for (final t in _tickets) t.reference};
      setState(() {
        _tickets.addAll(page.tickets.where((t) => !known.contains(t.reference)));
        _page += 1;
        _hasMore = page.hasMore;
      });
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } finally {
      if (_current(epoch)) setState(() => _more = false);
    }
  }

  Future<void> _send() async {
    if (_sending) return;
    if (_body.text.trim().length < kTicketMin) {
      setState(() => _formError = 'اكتب تفاصيل التذكرة ($kTicketMin أحرف على الأقل).');
      return;
    }
    final phone = normalizeSaudiMobile(_phone.text);
    if (_guest && (_name.text.trim().isEmpty || phone == null)) {
      setState(() => _formError = _name.text.trim().isEmpty ? 'اكتب اسمك.' : 'أدخل رقم جوال سعودي صحيح يبدأ بـ 05.');
      return;
    }
    final epoch = _epoch;
    final body = ServerSupport.body(
      type: _type,
      subject: _subject.text,
      text: _body.text,
      name: _guest ? _name.text : null,
      phone: _guest ? phone : null,
    );
    setState(() {
      _sending = true;
      _formError = null;
      _sent = null;
    });
    try {
      final (reference, hours) = await ServerSupport.open(body, operation: _op.forBody(body), epoch: epoch);
      _op.done();
      if (!_current(epoch)) return;
      for (final c in <TextEditingController>[_name, _phone, _subject, _body]) {
        c.clear();
      }
      setState(() {
        _type = 'question';
        _sent = hours == null ? 'وصلت تذكرتك رقم $reference.' : 'وصلت تذكرتك رقم $reference، ويرد فريق زون خلال $hours ساعة.';
      });
      unawaited(_load());
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _formError = e.forUser);
    } on AccountChanged {
      // nothing goes out under the next account
    } finally {
      if (_current(epoch)) setState(() => _sending = false);
    }
  }

  Widget _typeChip(String value, String label) {
    final on = _type == value;
    return Expanded(
      child: Tap(
        onTap: _sending ? null : () => setState(() => _type = value),
        child: Semantics(
          selected: on,
          button: true,
          child: Container(
            height: 40,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: on ? AppColors.forest : AppColors.surface,
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: on ? AppColors.forest : AppColors.line),
            ),
            child: Tx(label, style: ts(14, w: 700, lh: 20, c: on ? AppColors.white : AppColors.ink)),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final form = Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: AppColors.surface, borderRadius: BorderRadius.circular(24), boxShadow: AppShadows.hairline),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              for (var i = 0; i < kTicketTypes.length; i++) ...<Widget>[
                if (i > 0) const SizedBox(width: 8),
                _typeChip(kTicketTypes[i].$1, kTicketTypes[i].$2),
              ],
            ],
          ),
          if (_guest) ...<Widget>[
            const SizedBox(height: 12),
            SvcField(label: 'الاسم', child: selInput(controller: _name)),
            const SizedBox(height: 12),
            SvcField(
              label: 'الجوال',
              child: selInput(controller: _phone, dir: TextDirection.ltr, keyboard: TextInputType.phone),
            ),
          ],
          const SizedBox(height: 12),
          SvcField(
            label: 'الموضوع (اختياري)',
            child: selInput(controller: _subject),
          ),
          const SizedBox(height: 12),
          SvcField(
            label: 'التفاصيل',
            child: FieldBox(
              controller: _body,
              style: kSelText,
              height: 112,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: kSelDeco,
              multiline: true,
            ),
          ),
          if (_formError case final String e) ...<Widget>[const SizedBox(height: 12), FormError(e)],
          if (_sent case final String s) ...<Widget>[
            const SizedBox(height: 12),
            Tx(s, style: ts(15, w: 600, lh: 21.5, c: AppColors.teal)),
          ],
          const SizedBox(height: 12),
          AppButton(label: _sending ? 'جارٍ الإرسال…' : 'إرسال', onTap: _sending ? null : () => unawaited(_send())),
        ],
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx('تذكرة جديدة', style: ts(14, w: 700, lh: 21, c: AppColors.ink)),
        const SizedBox(height: 8),
        form,
        if (!_guest) ...<Widget>[
        const SizedBox(height: 20),
        Tx('تذاكري', style: ts(14, w: 700, lh: 21, c: AppColors.ink)),
        const SizedBox(height: 8),
        if (_error case final String e) ...<Widget>[FormError(e), const SizedBox(height: 8)],
        if (_loading)
          const LoadingBox()
        else if (_tickets.isEmpty && _error == null)
          Tx('لا تذاكر بعد.', style: ts(15, lh: 22.5, c: AppColors.muted), align: TextAlign.center),
        for (final t in _tickets) ...<Widget>[
          TicketCard(ticket: t, onTap: () => Nav.go(context, '/support/${t.reference}')),
          const SizedBox(height: 10),
        ],
        if (_hasMore)
          AppButton(label: _more ? 'جارٍ الجلب…' : 'تذاكر أقدم', tone: ButtonTone.tonal, onTap: _more ? null : () => unawaited(_loadMore())),
        ],
      ],
    );
  }
}

/// One ticket of the list: what it is, its state, the last message, and whether he has read it.
class TicketCard extends StatelessWidget {
  const TicketCard({super.key, required this.ticket, required this.onTap});

  final SupportTicket ticket;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final t = ticket;
    final last = t.last;
    final at = last?.at ?? t.createdAt;
    return Tap(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(20),
          boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                if (t.unread) ...<Widget>[
                  Semantics(
                    label: 'غير مقروءة',
                    child: Container(
                      width: 8,
                      height: 8,
                      decoration: BoxDecoration(color: AppColors.teal, borderRadius: BorderRadius.circular(99)),
                    ),
                  ),
                  const SizedBox(width: 8),
                ],
                Expanded(
                  child: Tx(t.title, style: ts(15, w: 700, lh: 22.5, c: AppColors.ink), maxLines: 1),
                ),
                const SizedBox(width: 8),
                TicketStatus(status: t.status),
              ],
            ),
            const SizedBox(height: 4),
            Tx(
              '${kTicketTypeLabel[t.type] ?? ''} · ${t.reference}${at == null ? '' : ' · ${arRelative(at, DateTime.now())}'}',
              style: ts(12, lh: 18, c: AppColors.muted),
            ),
            if (last != null) ...<Widget>[
              const SizedBox(height: 6),
              Tx('${last.zon ? 'فريق زون: ' : 'أنت: '}${last.body}', style: ts(13, lh: 19.5, c: AppColors.sage), maxLines: 2),
            ],
          ],
        ),
      ),
    );
  }
}

/// «مفتوحة» · «تم الرد» · «مغلقة».
class TicketStatus extends StatelessWidget {
  const TicketStatus({super.key, required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (status) {
      'answered' => (const Color(0x1F136B43), AppColors.success),
      'closed' => (AppColors.paper, AppColors.muted),
      _ => (AppColors.mist, AppColors.ink),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (status == 'closed') ...<Widget>[const LucideIcon(Lu.check, size: 12, color: AppColors.muted), const SizedBox(width: 4)],
          Tx(kTicketStatusLabel[status] ?? status, style: ts(12, w: 700, lh: 18, c: fg)),
        ],
      ),
    );
  }
}
