// A conversation with a server (ServerChats), `/chats/C<id>` — or `/chats/new?to=<username>&about=<L12|P3|R7>`
// before its first message.
//
// * Its messages come from the server, the newest first page; «رسائل أقدم» brings the page before. While it is
//   open it asks again every few seconds (live delivery and push come with their accounts), and what he was
//   shown is marked read up to its last message.
// * A message is sent once however often tried; until the server takes it, it shows as «يُرسل…», and when it
//   cannot, his text comes back to the bar with why.
// * Before the first message: if the two already talk about the same subject, that conversation opens instead;
//   otherwise the first message opens it.
// * A block (either side) closes the bar with a general line: the blocked side is not told why.
// * Another account signed in under it: nothing of this one stays shown.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_chats.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/talk.dart';

class ServerChatThread extends StatefulWidget {
  const ServerChatThread({super.key, this.id, this.to, this.about, this.name, this.role});

  /// The conversation on the server; null before its first message (then [to] is the other side's username).
  final int? id;
  final String? to;

  /// What it is about: `L12` · `P3` · `R7`.
  final String? about;
  final String? name;
  final String? role;

  static const Duration every = Duration(seconds: 6);

  @override
  State<ServerChatThread> createState() => _ServerChatThreadState();
}

class _ServerChatThreadState extends State<ServerChatThread> {
  final TextEditingController _bar = TextEditingController();
  final Map<int, ServerMsg> _messages = <int, ServerMsg>{};
  final OperationKey _op = OperationKey('msg');
  Chat? _chat;
  bool _loading = true;
  bool _hasMore = false;
  bool _older = false;
  String? _error;
  String? _sending;
  int _readUpTo = 0;
  Timer? _poll;
  int _epoch = store.serverEpoch;

  int? get _id => widget.id;

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
    setState(() {
      _messages.clear();
      _chat = null;
      _sending = null;
      _error = null;
    });
    unawaited(Nav.replace(context, '/chats'));
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _start() async {
    final epoch = _epoch;
    try {
      final list = await ServerChats.list();
      if (!_current(epoch)) return;
      final id = _id;
      if (id == null) {
        // before the first message: the conversation they already have about the same subject, if any
        for (final c in list) {
          if (c.username == widget.to && (c.listingId ?? c.requestId) == widget.about) {
            if (!mounted) return;
            unawaited(Nav.replace(context, '/chats/${c.id}'));
            return;
          }
        }
        setState(() => _loading = false);
        return;
      }
      for (final c in list) {
        if (c.id == ServerChats.appId(id)) _chat = c;
      }
      await _fetch(epoch);
      if (_current(epoch)) _poll = Timer.periodic(ServerChatThread.every, (_) => unawaited(_fetch(epoch)));
    } on ApiError catch (e) {
      if (_current(epoch)) {
        setState(() {
          _loading = false;
          _error = e.forUser;
        });
      }
    }
  }

  /// The newest page again: new messages join, and what he was shown is read.
  Future<void> _fetch(int epoch) async {
    final id = _id;
    if (id == null) return;
    try {
      final page = await ServerChats.messages(id);
      if (!_current(epoch)) return;
      setState(() {
        _loading = false;
        if (_messages.isEmpty) _hasMore = page.hasMore;
        for (final m in page.messages) {
          _messages[m.id] = m;
        }
      });
      final last = _messages.isEmpty ? 0 : _messages.keys.reduce((a, b) => a > b ? a : b);
      if (last > _readUpTo && _messages[last]?.mine == false) {
        _readUpTo = last;
        await ServerChats.read(id, last);
      }
    } on ApiError catch (e) {
      if (_current(epoch) && _messages.isEmpty) {
        setState(() {
          _loading = false;
          _error = e.forUser;
        });
      }
    }
  }

  Future<void> _loadOlder() async {
    final id = _id;
    if (id == null || _messages.isEmpty || _older) return;
    final epoch = _epoch;
    setState(() => _older = true);
    try {
      final page = await ServerChats.messages(id, before: _messages.keys.reduce((a, b) => a < b ? a : b));
      if (!_current(epoch)) return;
      setState(() {
        for (final m in page.messages) {
          _messages[m.id] = m;
        }
        _hasMore = page.hasMore;
      });
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } finally {
      if (_current(epoch)) setState(() => _older = false);
    }
  }

  Future<void> _send(String typed) async {
    final text = typed.trim();
    if (text.isEmpty || _sending != null) return;
    final epoch = _epoch;
    setState(() {
      _sending = text;
      _error = null;
    });
    _bar.clear();
    try {
      final id = _id;
      if (id == null) {
        final (opened, _) = await ServerChats.open(
          widget.to!,
          text,
          subject: widget.about,
          operation: _op.forBody(<String, Object?>{'to': widget.to, 'about': widget.about, 'body': text}),
          epoch: epoch,
        );
        _op.done();
        if (_current(epoch)) unawaited(Nav.replace(context, '/chats/${ServerChats.appId(opened)}'));
        return;
      }
      final sent = await ServerChats.send(id, text, operation: _op.forBody(<String, Object?>{'c': id, 'body': text}), epoch: epoch);
      _op.done();
      if (!_current(epoch)) return;
      setState(() {
        _messages[sent.id] = sent;
        _sending = null;
      });
    } on ApiError catch (e) {
      if (_current(epoch)) {
        setState(() {
          _sending = null;
          _error = e.code == 'USER_BLOCKED' ? 'لا يمكن إرسال رسائل في هذه المحادثة.' : e.forUser;
          if (_bar.text.isEmpty) _bar.text = text; // his text back, to try again
        });
      }
    } on AccountChanged {
      // nothing more under the next account
    }
  }

  @override
  Widget build(BuildContext context) {
    final name = _chat?.name ?? widget.name ?? '';
    final role = _chat?.role ?? widget.role ?? '';
    final blocked = _chat?.blocked ?? false;
    final ordered = _messages.values.toList()..sort((a, b) => a.id.compareTo(b.id));
    final subject = _chat?.subjectTitle;
    return AppShell(
      scrollable: false,
      child: TalkPage(
        count: ordered.length + (_sending == null ? 0 : 1),
        head: TalkHead(avatar: talkInitial(name), name: name, sub: subject == null ? (kRoleLabel[role] ?? '') : 'بخصوص: $subject'),
        log: <Widget>[
          if (_loading) const LoadingBox(),
          if (_hasMore)
            Center(
              child: AppButton(
                label: _older ? 'جارٍ الجلب…' : 'رسائل أقدم',
                tone: ButtonTone.tonal,
                onTap: _older ? null : () => unawaited(_loadOlder()),
              ),
            ),
          if (!_loading && ordered.isEmpty && _sending == null)
            Tx(
              _id == null ? 'اكتب رسالتك الأولى.' : 'لا رسائل بعد.',
              style: ts(14, c: AppColors.muted),
              align: TextAlign.center,
            ),
          for (final m in ordered) TalkBubble(text: m.body, mine: m.mine),
          if (_sending case final String t) Opacity(opacity: 0.6, child: TalkBubble(text: '$t\nيُرسل…', mine: true)),
          if (_error case final String e) FormError(e),
        ],
        bar: blocked
            ? Padding(
                padding: const EdgeInsets.all(12),
                child: Tx(
                  'لا يمكن إرسال رسائل في هذه المحادثة.',
                  style: ts(14, c: AppColors.muted),
                  align: TextAlign.center,
                ),
              )
            : TalkBar(controller: _bar, onSend: (t) => unawaited(_send(t)), photos: false),
      ),
    );
  }
}
