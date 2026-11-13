// `/account/projects/:id/review` — the wording of a developer's project and of its unit models, with a server.
//
// The server prepares the AI's wording of each text after it is saved. The page asks again every few seconds
// while any is prepared; when it takes longer, it says so and sends him to «مشاريعي». Then each text with a
// suggestion waits for his decision: «موافق» (the suggestion, or his edit of it) or «رفض» (his own text). A text
// the AI left as he wrote it needs none. «نشر» sends the decisions and publishes: the server asks every
// condition again (each text decided; a ready project's models each with a current licence) and says what stops it.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_my_projects.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/widgets/polish_review.dart';

/// The wording review of the server's project [id] (its number on the server).
class ProjectReviewScreen extends StatefulWidget {
  const ProjectReviewScreen({super.key, required this.id});

  final int id;

  static const Duration every = Duration(seconds: 3);
  static const int tries = 10;

  @override
  State<ProjectReviewScreen> createState() => _ProjectReviewScreenState();
}

class _ProjectReviewScreenState extends State<ProjectReviewScreen> {
  ProjectWording? _wording;
  String? _error;
  bool _late = false;
  bool _sending = false;
  int _epoch = store.serverEpoch;

  /// His choice on each text waiting: 'p' for the project's, a model's id otherwise.
  final Map<String, Verdict> _verdicts = <String, Verdict>{};
  final Map<String, String> _texts = <String, String>{};
  String? _editKey;

  @override
  void initState() {
    super.initState();
    store.addListener(_onAccount);
    unawaited(_ask());
  }

  @override
  void dispose() {
    store.removeListener(_onAccount);
    super.dispose();
  }

  /// Another account (or none): nothing of the one before stays shown, and it is asked again.
  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    setState(() {
      _wording = null;
      _verdicts.clear();
      _texts.clear();
      _editKey = null;
      _sending = false;
    });
    unawaited(_ask());
  }

  bool _currentFor(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _ask() async {
    final epoch = _epoch;
    setState(() {
      _error = null;
      _late = false;
    });
    for (var i = 0; i < ProjectReviewScreen.tries; i++) {
      try {
        final wording = await ServerMyProjects.review(widget.id);
        if (!mounted || !_currentFor(epoch)) return;
        if (!wording.preparing) {
          setState(() {
            _wording = wording;
            for (final (key, text) in _waiting(wording)) {
              _verdicts.putIfAbsent(key, () => Verdict.pending);
              _texts.putIfAbsent(key, () => text.suggested!);
            }
          });
          return;
        }
      } on ApiError catch (e) {
        if (_currentFor(epoch)) setState(() => _error = e.forUser);
        return;
      }
      await Future<void>.delayed(ProjectReviewScreen.every);
      if (!_currentFor(epoch)) return;
    }
    setState(() => _late = true);
  }

  /// The texts with a suggestion waiting for him, keyed as [_verdicts].
  static List<(String, TextWording)> _waiting(ProjectWording w) => <(String, TextWording)>[
    if (w.project.waiting) ('p', w.project),
    for (final u in w.units)
      if (u.waiting && u.unitId != null) ('${u.unitId}', u),
  ];

  (TextDecision, String?) _decisionOf(String key, TextWording t) {
    if (_verdicts[key] == Verdict.no) return (TextDecision.reject, null);
    final text = (_texts[key] ?? '').trim();
    return text == t.suggested!.trim() ? (TextDecision.approve, null) : (TextDecision.edit, text);
  }

  Future<void> _publish() async {
    final wording = _wording!;
    final epoch = _epoch;
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      if (wording.project.waiting) {
        final (decision, text) = _decisionOf('p', wording.project);
        await ServerMyProjects.decideProject(widget.id, decision, text: text);
      }
      final units = <int, (TextDecision, String?)>{
        for (final (key, t) in _waiting(wording))
          if (key != 'p') t.unitId!: _decisionOf(key, t),
      };
      if (units.isNotEmpty) await ServerMyProjects.decideUnits(widget.id, units);
      if (!_currentFor(epoch)) return;
      final project = await ServerMyProjects.publish(widget.id);
      store.keepServer(<Listing>[project], epoch: epoch, detailed: true);
      if (mounted && _currentFor(epoch)) unawaited(Nav.replace(context, '/projects/${ServerListings.projectId(widget.id)}'));
    } on ApiError catch (e) {
      if (_currentFor(epoch)) {
        setState(() {
          _sending = false;
          _error = e.code == 'LICENCE_REQUIRED' ? 'كل نموذج في مشروع جاهز يحتاج ترخيصاً سارياً لدى الهيئة. أكمل التراخيص من «مشاريعي».' : e.forUser;
        });
        unawaited(_ask()); // what was decided meanwhile is not asked again
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final wording = _wording;
    if (wording == null) {
      return AppShell(
        title: 'أوصاف المشروع',
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: _error != null
              ? EmptyBox(title: 'تعذّر جلب الصياغة', text: _error!, textStyle: emptyTextStyle(), action: 'أعد المحاولة', onAction: () => unawaited(_ask()))
              : _late
              ? EmptyBox(
                  title: 'الصياغة تأخذ وقتاً',
                  text: 'حُفظ مشروعك مسودة. يصلك إشعار حين تجهز الأوصاف، وتجده في «مشاريعي».',
                  textStyle: emptyTextStyle(),
                  action: 'مشاريعي',
                  onAction: () => unawaited(Nav.replace(context, '/account/listings')),
                )
              : const LoadingBox(label: 'نصوغ أوصاف المشروع والوحدات…'),
        ),
      );
    }
    final waiting = _waiting(wording);
    final open = waiting.where((e) => _verdicts[e.$1] == Verdict.pending).length;
    Widget block(String key, String label, TextWording t, {required bool project}) => PolishUnit(
      key: ValueKey<String>('wording-$key'),
      label: label,
      spark: project,
      ai: true,
      verdict: _verdicts[key] ?? Verdict.pending,
      editing: _editKey == key,
      text: _verdicts[key] == Verdict.no ? t.raw : (_texts[key] ?? ''),
      editValue: _texts[key] ?? '',
      onEditChanged: (v) => setState(() => _texts[key] = v),
      onOk: () => setState(() => _verdicts[key] = Verdict.ok),
      onNo: () => setState(() => _verdicts[key] = Verdict.no),
      onEdit: () => setState(() => _editKey = _editKey == key ? null : key),
    );
    final cards = <Widget>[
      for (final (key, t) in waiting) block(key, key == 'p' ? 'وصف المشروع' : 'وحدة ${t.name}', t, project: key == 'p'),
    ];
    return AppShell(
      child: PolishStage(
        onBack: () => Nav.go(context, '/account/listings'),
        title: 'أوصاف المشروع والوحدات',
        lead: waiting.isEmpty
            ? 'أوصافك جاهزة كما كتبتها.'
            : 'لكل وصف: موافق على صياغة الذكاء (أو عدّلها)، أو رفض فيُنشر بنصك.${open > 0 ? ' بقي $open.' : ''}',
        actions: <Widget>[
          if (_error != null) FormError(_error!),
          if (waiting.isNotEmpty)
            AppButton(
              label: 'موافق على الكل',
              tone: ButtonTone.secondary,
              onTap: _sending
                  ? null
                  : () => setState(() {
                      for (final (key, _) in waiting) {
                        _verdicts[key] = Verdict.ok;
                      }
                    }),
            ),
          AppButton(label: _sending ? 'جارٍ النشر…' : 'نشر المشروع', onTap: _sending || open > 0 ? null : _publish),
        ],
        children: <Widget>[
          for (var i = 0; i < cards.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 12), cards[i]],
        ],
      ),
    );
  }
}
