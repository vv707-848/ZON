// `/chats/barq` — chat with برق, the assistant that answers market questions.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/talk.dart';

const List<String> _chips = <String>[
  'وش شروط تملك الأجنبي في السعودية؟',
  'الفرق بين الصك الإلكتروني والورقي؟',
  'راتبي 12 ألف وأبي فيلا بالنرجس، وش تسوي؟',
  'What is Fal license in Saudi real estate?',
];

String _reply(String q) {
  final t = q.toLowerCase();
  if (RegExp('fal|فال|رخص').hasMatch(t)) {
    return 'فال رخصة الوساطة. الوسيط بدونها مثل من يبيع تمر وهو ما عنده نخلة. الرقم يتحقق، ويطلع QR على العرض.';
  }
  if (RegExp('صك|deed|electronic').hasMatch(t)) {
    return 'الصك الإلكتروني أساس التملك. الورقي يبي تحديث. مثل من يشتري جمل وهو يبي حليب ناقة — حدّد الصك أول، باقي التفاصيل تجي.';
  }
  if (RegExp('أجنب|foreign|تملك').hasMatch(t)) {
    return 'تملك غير السعودي له ضوابط الهيئة والمناطق المسموحة. صف لي جنسيتك والمدينة، أضبط لك المسار بلغتك.';
  }
  if (RegExp('راتب|تمويل|bank|فيلا|نرجس').hasMatch(t)) {
    return 'على راتب 12 ألف الفيلا بالنرجس تثقل. إمّا شقة عوائل قريبة، أو تمويل بمدة أطول. قلّي الغرف والحد الأقصى أصفّي لك عروض باسمها.';
  }
  return 'برق: حسب شروط التملك، الصك الإلكتروني أساس والتمويل يحتاج أهلية البنك. صف الميزانية والحي — أضبط لك العرض باسمه، بأي لغة تكتب.';
}

/// `/chats/barq` — the Barq assistant.
class BarqChatScreen extends StatefulWidget {
  const BarqChatScreen({super.key});

  @override
  State<BarqChatScreen> createState() => _BarqChatScreenState();
}

class _BarqChatScreenState extends State<BarqChatScreen> {
  final TextEditingController _msg = TextEditingController();
  List<Msg> log = store.messages('barq');
  bool think = false;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _msg.dispose();
    super.dispose();
  }

  void _send(String text) {
    final q = text.trim();
    if (q.isEmpty || think) return;
    store.pushMessage('barq', Msg(me: true, t: q));
    setState(() {
      log = store.messages('barq');
      think = true;
    });
    _msg.clear();
    _timer = Timer(const Duration(milliseconds: 700), () {
      store.pushMessage('barq', Msg(t: _reply(q)));
      if (!mounted) return;
      setState(() {
        log = store.messages('barq');
        think = false;
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    return AppShell(
      scrollable: false,
      child: TalkPage(
        count: log.length * 2 + (think ? 1 : 0),
        head: const TalkHead(
          avatar: LucideIcon(Lu.zap, size: 16, color: AppColors.white, fill: AppColors.white),
          name: 'برق',
          sub: 'متصل · يفهم التملك',
        ),
        log: <Widget>[
          if (log.isEmpty)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(22)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Tx('أنا برق.', style: ts(18, w: 700, lh: 27, c: AppColors.white)),
                  const SizedBox(height: 6),
                  Tx(
                    'أشرح شروط التملك والصك وفال والتمويل بأي لغة. وإذا استاهل، مثال شعبي خفيف على حالك.',
                    style: ts(14, lh: 24, c: const Color(0xC4FFFFFF)),
                  ),
                ],
              ),
            ),
          for (final m in log) TalkBubble(text: m.t, mine: m.me == true),
          if (think) const TalkBubble(text: 'برق يفكّر…', mine: false),
          if (log.length < 3)
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: <Widget>[
                Tx('اسأل مثلاً', style: ts(16, lh: 24, c: AppColors.ink)),
                for (final c in _chips)
                  Tap(
                    onTap: () => _send(c),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(999)),
                      foregroundDecoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        border: Border.all(color: const Color(0x14121214)),
                      ),
                      child: Tx(
                        c,
                        style: ts(13, w: 700, lh: 19.5, c: AppColors.ink),
                        align: TextAlign.right,
                      ),
                    ),
                  ),
              ],
            ),
        ],
        bar: TalkBar(controller: _msg, onSend: _send),
      ),
    );
  }
}
