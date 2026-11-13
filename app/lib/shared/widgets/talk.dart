// Chat UI: header, message bubbles and the composer.

import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'package:image_picker/image_picker.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// Conversation header: back link, avatar and name.
class TalkHead extends StatelessWidget {
  const TalkHead({super.key, required this.avatar, required this.name, required this.sub});

  final Widget avatar;
  final String name;
  final String sub;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: const BoxDecoration(
        color: AppColors.paper,
        border: Border(bottom: BorderSide(color: Color(0x14121214))),
      ),
      child: Row(
        children: <Widget>[
          Tap(
            onTap: () => Nav.go(context, '/chats'),
            child: const LucideIcon(Lu.chevronRight, size: 24, strokeWidth: 2.2, color: AppColors.ink),
          ),
          const SizedBox(width: 10),
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(14)),
            alignment: Alignment.center,
            child: avatar,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Tx(name, style: ts(16, w: 700, lh: 24, c: AppColors.ink)),
                Tx(sub, style: ts(12, lh: 18, c: AppColors.muted)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// First letter of a name for the avatar.
Widget talkInitial(String name) => Tx(name.isEmpty ? '' : String.fromCharCode(name.codeUnitAt(0)), style: ts(16, w: 700, lh: 24, c: AppColors.white));

/// One message bubble.
class TalkBubble extends StatelessWidget {
  const TalkBubble({super.key, required this.text, required this.mine});

  final String text;
  final bool mine;

  @override
  Widget build(BuildContext context) {
    // RTL: mine → inline-start (right) with the bottom-right corner cut;
    // theirs → inline-end (left) with the bottom-left corner cut.
    final radius = mine
        ? const BorderRadius.only(
            topLeft: Radius.circular(18),
            topRight: Radius.circular(18),
            bottomLeft: Radius.circular(18),
            bottomRight: Radius.circular(6),
          )
        : const BorderRadius.only(
            topLeft: Radius.circular(18),
            topRight: Radius.circular(18),
            bottomLeft: Radius.circular(6),
            bottomRight: Radius.circular(18),
          );
    return LayoutBuilder(
      builder: (context, box) {
        return Align(
          alignment: mine ? AlignmentDirectional.centerStart : AlignmentDirectional.centerEnd,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: box.maxWidth * 0.8),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: mine ? AppColors.forest : AppColors.white,
                borderRadius: radius,
                boxShadow: mine ? null : const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
              ),
              child: Tx(text, style: ts(14, lh: 24, c: mine ? AppColors.white : AppColors.forest)),
            ),
          ),
        );
      },
    );
  }
}

/// The page frame of a conversation: the head and log scroll together while
/// the composer stays at the bottom (`position: sticky`).
class TalkPage extends StatefulWidget {
  const TalkPage({super.key, required this.head, required this.log, required this.bar, required this.count});

  final Widget head;
  final List<Widget> log;
  final Widget bar;

  /// Number of log entries: the log scrolls to its end whenever it changes.
  final int count;

  @override
  State<TalkPage> createState() => _TalkPageState();
}

class _TalkPageState extends State<TalkPage> {
  final ScrollController _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _toEnd();
  }

  @override
  void didUpdateWidget(TalkPage old) {
    super.didUpdateWidget(old);
    if (old.count != widget.count) _toEnd();
  }

  void _toEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_scroll.hasClients) return;
      final max = _scroll.position.maxScrollExtent;
      if ((max - _scroll.offset).abs() < 0.5) return;
      _scroll.animateTo(max, duration: const Duration(milliseconds: 300), curve: Curves.easeInOut);
    });
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final bottom = math.max(14.0, MediaQuery.paddingOf(context).bottom);
    // composer: margin 8px 14px max(14px, safe-area) around a 56px pill
    final barSpace = 8 + 56 + bottom;
    return LayoutBuilder(
      builder: (context, box) {
        return Stack(
          children: <Widget>[
            SingleChildScrollView(
              controller: _scroll,
              child: ConstrainedBox(
                constraints: BoxConstraints(minHeight: box.maxHeight),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    widget.head,
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: <Widget>[
                          for (var i = 0; i < widget.log.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 8), widget.log[i]],
                        ],
                      ),
                    ),
                    SizedBox(height: barSpace),
                  ],
                ),
              ),
            ),
            Positioned(left: 14, right: 14, bottom: bottom, child: widget.bar),
          ],
        );
      },
    );
  }
}

/// Message composer: camera / gallery, text field and send button.
class TalkBar extends StatefulWidget {
  const TalkBar({super.key, required this.controller, required this.onSend, this.placeholder = 'اكتب رسالتك هنا...', this.photos = true});

  final TextEditingController controller;
  final ValueChanged<String> onSend;
  final String placeholder;

  /// The camera and gallery buttons. Off with a server: a conversation there carries text only, and a picked
  /// photo must not be told as sent.
  final bool photos;

  @override
  State<TalkBar> createState() => _TalkBarState();
}

class _TalkBarState extends State<TalkBar> {
  final ImagePicker _picker = ImagePicker();

  Future<void> _attach(ImageSource source) async {
    try {
      final file = await _picker.pickImage(source: source);
      if (file == null) return;
      widget.onSend('تم إرفاق صورة');
    } catch (_) {
      // picking cancelled / unavailable: nothing is sent (like an empty file input)
    }
  }

  @override
  Widget build(BuildContext context) {
    final field = TextField(
      controller: widget.controller,
      textDirection: TextDirection.rtl,
      textAlign: TextAlign.right,
      textInputAction: TextInputAction.go,
      style: ts(16, w: 500, lh: 24, c: AppColors.forest),
      cursorColor: AppColors.forest,
      decoration: InputDecoration.collapsed(
        hintText: widget.placeholder,
        hintStyle: ts(16, w: 500, lh: 24, c: const Color(0xFF8A9690)),
      ),
      onEditingComplete: () {},
      onSubmitted: (v) => widget.onSend(v),
    );
    return Container(
      height: 56,
      padding: const EdgeInsets.all(6),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: AppColors.line),
        boxShadow: AppShadows.raised,
      ),
      // `direction: ltr` — media on the left, send on the right
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: Row(
          children: <Widget>[
            if (widget.photos) ...<Widget>[
              Container(
                height: 44,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Tap(
                      onTap: () => _attach(ImageSource.camera),
                      child: const Padding(
                        padding: EdgeInsets.all(4),
                        child: LucideIcon(Lu.camera, size: 20, strokeWidth: 1.8, color: AppColors.white),
                      ),
                    ),
                    Container(width: 1, height: 18, margin: const EdgeInsets.symmetric(horizontal: 10), color: const Color(0x55FFFFFF)),
                    Tap(
                      onTap: () => _attach(ImageSource.gallery),
                      child: const Padding(
                        padding: EdgeInsets.all(4),
                        child: LucideIcon(Lu.image, size: 20, strokeWidth: 1.8, color: AppColors.white),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
            ],
            Expanded(
              child: SizedBox(
                height: 44,
                // the field itself is `direction: rtl` inside the ltr form
                child: Align(
                  alignment: Alignment.center,
                  child: Directionality(textDirection: TextDirection.rtl, child: field),
                ),
              ),
            ),
            const SizedBox(width: 8),
            Tap(
              onTap: () => widget.onSend(widget.controller.text),
              child: Container(
                width: 44,
                height: 44,
                decoration: const BoxDecoration(color: AppColors.forest, shape: BoxShape.circle),
                alignment: Alignment.center,
                child: const LucideIcon(Lu.send, size: 18, color: AppColors.white),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
