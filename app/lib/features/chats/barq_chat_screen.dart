// `/chats/barq` — chat with برق, the assistant that answers market questions.
//
// With a server (ServerAi.chat) the answers are the server's, from its library: the earlier turns are the ones it
// kept, each new answer is shown as it came with the documents it rests on and where it leads in the app, and the
// screen says when the server's AI is the simulation. Nothing is answered on the phone: a question the server did
// not answer goes back to the box with why. Another account signed in: nothing of the one before stays.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_ai.dart';
import 'package:zon/data/server/server_desk.dart' show NeedsPlan;
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/match_kit.dart' show PlanDoor;
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
  // with a server the turns are the server's: what this phone kept of a local chat is not shown as its answers
  List<Msg> log = Api.on ? <Msg>[] : store.messages('barq');
  bool think = false;
  Timer? _timer;

  /// With a server: the last answer (its documents, where it leads, how it was obtained), and a refusal.
  ChatReply? _last;
  String _error = '';
  String? _refused;
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    if (Api.on) {
      store.addListener(_onAccount);
      unawaited(_history());
    }
  }

  @override
  void dispose() {
    store.removeListener(_onAccount);
    _timer?.cancel();
    _msg.dispose();
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    _msg.clear();
    setState(() {
      log = <Msg>[];
      think = false;
      _last = null;
      _error = '';
      _refused = null;
    });
    if (store.readSession() != null) unawaited(_history());
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  /// The turns the server kept, oldest first.
  Future<void> _history() async {
    final epoch = _epoch;
    try {
      final turns = await ServerAi.history();
      if (!_current(epoch) || log.isNotEmpty) return; // he already asked: his turn is not written over
      setState(() => log = <Msg>[for (final t in turns) ...<Msg>[Msg(me: true, t: t.question), Msg(t: t.answer)]]);
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    }
  }

  Future<void> _ask(String q) async {
    final epoch = _epoch;
    final before = log;
    setState(() {
      log = <Msg>[...before, Msg(me: true, t: q)];
      think = true;
      _last = null;
      _error = '';
      _refused = null;
    });
    _msg.clear();

    // not answered: the question is not left looking sent — it goes back to the box
    void back(void Function() why) {
      if (!_current(epoch)) return;
      _msg.text = q;
      setState(() {
        log = before;
        why();
      });
    }

    try {
      final reply = await ServerAi.chat(q, epoch: epoch);
      if (!_current(epoch)) return;
      setState(() {
        log = <Msg>[...log, Msg(t: reply.answer)];
        _last = reply;
      });
    } on NeedsPlan {
      back(() => _refused = 'المساعد ضمن الباقات.');
    } on QuotaReached catch (e) {
      back(() => _refused = e.message);
    } on ApiError catch (e) {
      back(() => _error = e.forUser);
    } on AccountChanged {
      // nothing is asked under the next account
    } finally {
      if (_current(epoch)) setState(() => think = false);
    }
  }

  void _send(String text) {
    final q = text.trim();
    if (q.isEmpty || think) return;
    if (Api.on) {
      unawaited(_ask(q));
      return;
    }
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
          sub: Api.on ? 'مساعد زون' : 'متصل · يفهم التملك',
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
                    // with a server: what its library covers, as the server itself says when asked outside it
                    Api.on
                        ? 'أجيب من مكتبة زون عن الباقات والتراخيص والنشر والبحث والمطابقة.'
                        : 'أشرح شروط التملك والصك وفال والتمويل بأي لغة. وإذا استاهل، مثال شعبي خفيف على حالك.',
                    style: ts(14, lh: 24, c: const Color(0xC4FFFFFF)),
                  ),
                ],
              ),
            ),
          for (final m in log) TalkBubble(text: m.t, mine: m.me == true),
          if (think) const TalkBubble(text: 'برق يفكّر…', mine: false),
          if (_last case final ChatReply r when !think) ...<Widget>[
            for (final s in r.sources)
              Tx(
                'المصدر: ${s.title}${s.effective == null ? '' : ' · ${s.effective}'}',
                style: ts(13, lh: 19.5, c: AppColors.muted),
              ),
            if (r.action case final String to)
              AppButton(label: 'افتح', tone: ButtonTone.secondary, onTap: () => Nav.go(context, to)),
            if (r.ai.simulated) Tx('محاكاة: الإجابة بقواعد ثابتة، لا بنموذج ذكاء.', style: ts(13, lh: 19.5, c: AppColors.muted)),
          ],
          if (_error.isNotEmpty) FormError(_error),
          if (_refused case final String said) PlanDoor(said),
          // the sample questions are the phone's own assistant's; the server's library is asked in his words
          if (!Api.on && log.length < 3)
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
        bar: TalkBar(controller: _msg, onSend: _send, photos: !Api.on),
      ),
    );
  }
}
