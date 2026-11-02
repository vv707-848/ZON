// `/chats/:id` — one conversation.

import 'package:flutter/material.dart';

import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/ask_card.dart';
import 'package:zon/shared/widgets/listing_card.dart';
import 'package:zon/shared/widgets/talk.dart';

/// `/chats/$id` — a conversation.
class ChatThreadScreen extends StatefulWidget {
  const ChatThreadScreen({super.key, required this.id});

  /// The chat id (route parameter; chat ids are URI-encoded names).
  final String id;

  @override
  State<ChatThreadScreen> createState() => _ChatThreadScreenState();
}

class _ChatThreadScreenState extends State<ChatThreadScreen> {
  final TextEditingController _msg = TextEditingController();
  late String id;
  Chat? chat;
  Listing? listing;
  RequestItem? ask;
  late List<Msg> log;

  @override
  void initState() {
    super.initState();
    id = widget.id;
    for (final c in store.chats()) {
      if (c.id == id) chat = c;
    }
    final lid = chat?.listingId;
    listing = lid != null && lid.isNotEmpty ? store.listingById(lid) : null;
    final rid = chat?.requestId;
    ask = rid != null && rid.isNotEmpty ? store.requestById(rid) : null;
    final saved = store.messages(id);
    if (saved.isNotEmpty) {
      log = saved;
    } else if (listing != null) {
      log = <Msg>[Msg(t: 'مرحباً، بخصوص ${listing!.title}.')];
    } else if (ask != null) {
      log = const <Msg>[Msg(t: 'مرحباً، بخصوص طلبك.')];
    } else {
      log = const <Msg>[Msg(t: 'مرحباً.')];
    }
  }

  static String _decode(String s) {
    try {
      return Uri.decodeComponent(s);
    } catch (_) {
      return s;
    }
  }

  @override
  void dispose() {
    _msg.dispose();
    super.dispose();
  }

  void _send(String t) {
    final text = t.trim();
    if (text.isEmpty) return;
    store.pushMessage(id, Msg(me: true, t: text));
    store.pushMessage(id, const Msg(t: 'تم، أراجع وأردّك خلال اليوم.'));
    setState(() {
      final saved = store.messages(id);
      log = saved.isNotEmpty ? saved : <Msg>[...log, Msg(me: true, t: text), const Msg(t: 'تم، أراجع وأردّك خلال اليوم.')];
    });
    _msg.clear();
  }

  @override
  Widget build(BuildContext context) {
    final name = chat?.name ?? _decode(id);
    final role = chat?.role ?? 'broker';
    return AppShell(
      scrollable: false,
      child: TalkPage(
        count: log.length,
        head: TalkHead(avatar: talkInitial(name), name: name, sub: kRoleLabel[role] ?? ''),
        log: <Widget>[
          if (listing != null) ListingCard(listing: listing!),
          if (ask != null) AskCard(item: ask!),
          for (final m in log) TalkBubble(text: m.t, mine: m.me == true),
        ],
        bar: TalkBar(controller: _msg, onSend: _send),
      ),
    );
  }
}
