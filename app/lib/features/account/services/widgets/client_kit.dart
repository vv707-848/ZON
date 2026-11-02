// Shared pieces of the client and owner pages.

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter/services.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/features/account/services/people_view.dart' show CrmMark;
import 'package:zon/features/account/services/widgets/desk.dart' show kDeskSoft;

// Shared pieces of the client and owner pages (header card, preferences,
// AI advice, notes log, form fields).

const Color kCliLine = Color(0x1A121214);

/// `["تفكير", "قبول", "رفض"]`
const List<String> kNoteKinds = <String>['تفكير', 'قبول', 'رفض'];

Color kindInk(String k) => switch (k) {
  'تفكير' => const Color(0xFF8A5A14),
  'قبول' => const Color(0xFF1C5C40),
  'رفض' => const Color(0xFF8A3A32),
  'تأجيل' => const Color(0xFF3A4860),
  _ => AppColors.ink,
};

Color kindPillBg(String k) => switch (k) {
  'تفكير' => const Color(0xFFF6F0D8),
  'قبول' => const Color(0xFFD7EFE4),
  'رفض' => const Color(0xFFF4DDD8),
  'تأجيل' => const Color(0xFFE4E8F0),
  _ => const Color(0xFFE9EEEB),
};

/// Default colour of a note's side bar.
Color kindBar(String k) => switch (k) {
  'تفكير' || 'قبول' || 'رفض' || 'تأجيل' => kindInk(k),
  _ => const Color(0xFFD3DAD5),
};

/// Adds thousands separators to every number inside [s].
String moneyTxt(String? s) {
  if (s == null || s.isEmpty) return '';
  return s.replaceAllMapped(RegExp(r'\d+'), (m) => _loc(double.parse(m[0]!)));
}

String _loc(double n) {
  final neg = n < 0;
  final whole = n.abs().truncate();
  final digits = whole.toString();
  final b = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) b.write(',');
    b.write(digits[i]);
  }
  return '${neg ? '-' : ''}$b';
}

/// `splitRange` — "1,000 – 2,000" → ["1000", "2000"].
(String, String) splitRange(String? s) {
  if (s == null || s.isEmpty) return ('', '');
  final p = s.split(RegExp(r'\s*[–-]\s*'));
  String d(int i) => i < p.length ? p[i].replaceAll(RegExp(r'\D'), '') : '';
  return (d(0), d(1));
}

/// "a – b" range text (or the single value).
String joinRange(String a, String b) {
  String f(String x) => _loc(double.parse(x));
  if (a.isNotEmpty && b.isNotEmpty) return '${f(a)} – ${f(b)}';
  if (a.isNotEmpty) return f(a);
  if (b.isNotEmpty) return f(b);
  return '';
}

/// First character of a name.
String firstChar(String s) => s.isEmpty ? '' : s[0];

/// Limits text length (counted in UTF-16 units, like the server).
class Utf16LengthLimit extends TextInputFormatter {
  Utf16LengthLimit(this.max);

  final int max;

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    if (newValue.text.length <= max) return newValue;
    if (oldValue.text.length <= max) return oldValue;
    final t = newValue.text.substring(0, max);
    return TextEditingValue(
      text: t,
      selection: TextSelection.collapsed(offset: t.length),
    );
  }
}

// ---------------------------------------------------------------- page chrome

/// Back link at the top of a client/owner page.
class CliBack extends StatelessWidget {
  const CliBack({super.key, required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: Tap(
        onTap: onTap,
        child: SizedBox(
          height: 24,
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const LucideIcon(Lu.chevronLeft, size: 20, strokeWidth: 2.2, color: AppColors.ink),
              const SizedBox(width: 2),
              Tx(label, style: ts(16, w: 600, lh: 24, c: AppColors.ink)),
            ],
          ),
        ),
      ),
    );
  }
}

/// Dark header card: initial, name, counts and call / chat actions.
class CliHero extends StatelessWidget {
  const CliHero({super.key, required this.name, required this.roleLabel, required this.notes, required this.phone, required this.second});

  final String name;
  final String roleLabel;
  final List<Note> notes;
  final String phone;

  /// The second action (chat / listing link).
  final Widget second;

  @override
  Widget build(BuildContext context) {
    final nThink = notes.where((n) => n.kind == 'تفكير' || n.kind == 'تأجيل').length;
    final nOk = notes.where((n) => n.kind == 'قبول').length;
    final nNo = notes.where((n) => n.kind == 'رفض').length;
    Widget count(String k, int n, Color bg, Color fg) => Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 10),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(14)),
        child: Column(
          children: <Widget>[
            Tx(
              '$n',
              style: ts(18, w: 700, lh: 18, c: fg),
              align: TextAlign.center,
            ),
            const SizedBox(height: 4),
            Tx(
              k,
              style: ts(12, w: 600, lh: 18, c: fg),
              align: TextAlign.center,
            ),
          ],
        ),
      ),
    );
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(22)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SizedBox(
            height: 64,
            child: Row(
              children: <Widget>[
                // 56px grid column holding the 64px mark (it overflows into the gap)
                SizedBox(
                  width: 56,
                  height: 64,
                  child: OverflowBox(
                    minWidth: 64,
                    maxWidth: 64,
                    alignment: AlignmentDirectional.centerStart,
                    child: CrmMark(
                      letter: firstChar(name),
                      size: 64,
                      radius: 20,
                      bg: AppColors.white,
                      fg: AppColors.forest,
                      fontSize: 28,
                      weight: 600,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      Tx(name, style: ts(20, w: 700, lh: 25, c: AppColors.white)),
                      const SizedBox(height: 2),
                      Tx(roleLabel, style: ts(13, w: 600, lh: 19.5, c: kDeskSoft)),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: <Widget>[
              count('تفكير', nThink, const Color(0x2EE8D48A), const Color(0xFFF6E7B0)),
              const SizedBox(width: 6),
              count('قبول', nOk, const Color(0x2E9BE7C4), const Color(0xFFC8E6D0)),
              const SizedBox(width: 6),
              count('رفض', nNo, const Color(0x2EF3B4A8), const Color(0xFFF3B4A8)),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: <Widget>[
              Expanded(
                child: phone.isNotEmpty
                    ? CliHeroAct(icon: Lu.phone, label: 'اتصال', light: true, onTap: () => callPhone(phone))
                    : const CliHeroAct(icon: Lu.phone, label: 'بدون جوال', light: true, off: true),
              ),
              const SizedBox(width: 8),
              Expanded(child: second),
            ],
          ),
        ],
      ),
    );
  }
}

/// Call (light) and chat (translucent) buttons of the header card.
class CliHeroAct extends StatelessWidget {
  const CliHeroAct({super.key, required this.icon, required this.label, this.light = false, this.onTap, this.off = false});

  final String icon;
  final String label;
  final bool light;
  final VoidCallback? onTap;
  final bool off;

  @override
  Widget build(BuildContext context) {
    final fg = light ? AppColors.forest : AppColors.white;
    final box = Container(
      height: 44,
      decoration: BoxDecoration(color: light ? AppColors.white : const Color(0x16FFFFFF), borderRadius: BorderRadius.circular(14)),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          LucideIcon(icon, size: 24, strokeWidth: 2, color: fg),
          const SizedBox(width: 8),
          Tx(label, style: ts(15, w: 700, lh: 22.5, c: fg)),
        ],
      ),
    );
    if (off) return Opacity(opacity: 0.4, child: box);
    return Tap(onTap: onTap, child: box);
  }
}

/// Preferences / listing details with "المزيد".
class CliPrefs extends StatelessWidget {
  const CliPrefs({
    super.key,
    required this.icon,
    required this.title,
    required this.specs,
    required this.more,
    required this.onMore,
    this.wish,
    this.onEdit,
  });

  final String icon;
  final String title;
  final List<(String, String)> specs;
  final bool more;
  final VoidCallback onMore;
  final String? wish;
  final VoidCallback? onEdit;

  @override
  Widget build(BuildContext context) {
    Widget spec((String, String) s) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(16)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx(s.$1, style: ts(12, w: 600, lh: 18, c: AppColors.muted)),
          const SizedBox(height: 6),
          Tx(
            s.$2,
            style: ts(16, w: 700, lh: 20, c: AppColors.forest),
            maxLines: 1,
            softWrap: false,
            overflow: TextOverflow.ellipsis,
          ),
        ],
      ),
    );
    final rows = <Widget>[];
    for (var i = 0; i < specs.length; i += 2) {
      if (i > 0) rows.add(const SizedBox(height: 8));
      rows.add(
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Expanded(child: spec(specs[i])),
            const SizedBox(width: 8),
            Expanded(child: i + 1 < specs.length ? spec(specs[i + 1]) : const SizedBox.shrink()),
          ],
        ),
      );
    }
    Widget btn(String label, bool dark, VoidCallback onTap) => Tap(
      onTap: onTap,
      child: Container(
        height: 46,
        decoration: BoxDecoration(color: dark ? AppColors.forest : AppColors.mist, borderRadius: BorderRadius.circular(14)),
        alignment: Alignment.center,
        child: Tx(
          label,
          style: ts(16, w: 700, lh: 24, c: dark ? AppColors.white : AppColors.forest),
          align: TextAlign.center,
        ),
      ),
    );
    final moreBtn = btn(more ? 'أقل' : 'المزيد', true, onMore);
    final items = <Widget>[
      SizedBox(
        height: 24,
        child: Row(
          children: <Widget>[
            LucideIcon(icon, size: 24, strokeWidth: 1.8, color: AppColors.forest),
            const SizedBox(width: 8),
            Flexible(
              child: Tx(title, style: ts(16, w: 700, lh: 19, c: AppColors.forest)),
            ),
          ],
        ),
      ),
      Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: rows),
      if (wish != null) Tx(wish!, style: ts(15, w: 600, lh: 25.5, c: AppColors.forest)),
      if (onEdit == null)
        moreBtn
      else
        Row(
          children: <Widget>[
            Expanded(child: moreBtn),
            const SizedBox(width: 8),
            Expanded(child: btn('تعديل البيانات', false, onEdit!)),
          ],
        ),
    ];
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.fromLTRB(16, 18, 16, 16),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < items.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 14), items[i]],
        ],
      ),
    );
  }
}

/// 2 px bar sweeping while the AI reads the notes.
class CliScanBar extends StatefulWidget {
  const CliScanBar({super.key});

  @override
  State<CliScanBar> createState() => _CliScanBarState();
}

class _CliScanBarState extends State<CliScanBar> with SingleTickerProviderStateMixin {
  late final Ticker _ticker;
  final ValueNotifier<double> _t = ValueNotifier<double>(0);

  @override
  void initState() {
    super.initState();
    _ticker = createTicker((d) => _t.value = (d.inMicroseconds / 1e6 % 0.9) / 0.9)..start();
  }

  @override
  void dispose() {
    _ticker.dispose();
    _t.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 2,
      margin: const EdgeInsets.fromLTRB(14, 0, 14, 14),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(999),
        child: CustomPaint(painter: _ScanPainter(_t), size: Size.infinite),
      ),
    );
  }
}

class _ScanPainter extends CustomPainter {
  _ScanPainter(this.t) : super(repaint: t);

  final ValueNotifier<double> t;

  @override
  void paint(Canvas canvas, Size size) {
    // background-size 45%; background-position -40% → 140%
    final w = size.width * 0.45;
    final pos = -0.4 + 1.8 * t.value;
    final x = pos * (size.width - w);
    final r = Rect.fromLTWH(x, 0, w, size.height);
    canvas.drawRect(r, Paint()..shader = const LinearGradient(colors: <Color>[Color(0x00000000), AppColors.ink, Color(0x00000000)]).createShader(r));
  }

  @override
  bool shouldRepaint(_ScanPainter oldDelegate) => false;
}

/// AI follow-up recommendation.
class CliBrief extends StatelessWidget {
  const CliBrief({super.key, required this.aiOn, required this.hasNotes, required this.busy, required this.rec, required this.onRun});

  final bool aiOn;
  final bool hasNotes;
  final bool busy;

  /// The last computed recommendation (null → not yet).
  final String? rec;
  final VoidCallback onRun;

  @override
  Widget build(BuildContext context) {
    Widget action;
    if (aiOn) {
      final disabled = !hasNotes || busy;
      final btn = Container(
        height: 40,
        constraints: const BoxConstraints(minWidth: 108),
        padding: const EdgeInsets.symmetric(horizontal: 16),
        decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            const LucideIcon(Lu.sparkles, size: 16, strokeWidth: 1.8, color: AppColors.white),
            const SizedBox(width: 6),
            Tx(busy ? 'يقرأ…' : (rec != null ? 'حدّث' : 'تلخيص'), style: ts(15, w: 700, lh: 15, c: AppColors.white), maxLines: 1, softWrap: false),
          ],
        ),
      );
      action = disabled ? Opacity(opacity: 0.4, child: btn) : Tap(onTap: onRun, child: btn);
    } else {
      action = Tap(
        onTap: () => Nav.go(context, '/account/plans'),
        child: Container(
          height: 28,
          padding: const EdgeInsets.symmetric(horizontal: 10),
          decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
          alignment: Alignment.center,
          child: Tx('فعّل', style: ts(13, w: 700, lh: 19.5, c: AppColors.white)),
        ),
      );
    }
    final body = <Widget>[];
    if (aiOn) {
      if (busy) body.add(const CliScanBar());
      if (rec != null) {
        body.add(
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
            child: Tx(rec!, style: ts(16, lh: 27, c: AppColors.ink)),
          ),
        );
      } else {
        body.add(_em(hasNotes ? 'اضغط تلخيص — الذكاء يقرأ السجل ويعطيك الخطوة التالية.' : 'أول ملاحظة تبني الملف ثم يظهر التلخيص.'));
      }
    } else {
      body.add(_em('سجّل ملاحظاتك مجاناً. تلخيص الذكاء ضمن خدمة الوسيط — الصفقة خارج التطبيق.'));
    }
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: kCliLine, spreadRadius: 1)],
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.all(14),
            child: Row(
              children: <Widget>[
                Expanded(
                  child: Row(
                    children: <Widget>[
                      Container(
                        width: 36,
                        height: 36,
                        decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(12)),
                        alignment: Alignment.center,
                        child: const LucideIcon(Lu.lightbulb, size: 24, strokeWidth: 1.7, color: AppColors.forest),
                      ),
                      const SizedBox(width: 10),
                      Tx('توصية المتابعة', style: ts(16, w: 700, lh: 21, c: AppColors.forest), maxLines: 1, softWrap: false),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                action,
              ],
            ),
          ),
          ...body,
        ],
      ),
    );
  }

  Widget _em(String t) => Padding(
    padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
    child: Tx(t, style: ts(14, lh: 22.5, c: AppColors.muted)),
  );
}

/// Shared follow-up state of a client / owner profile (composer + brief).
mixin CliNotesState<T extends StatefulWidget> on State<T> {
  String kind = 'تفكير';
  late final TextEditingController body = TextEditingController()..addListener(_onBody);
  final FocusNode box = FocusNode();
  String? editingId;
  String? brief;
  bool busy = false;
  bool more = false;

  void _onBody() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    body.dispose();
    box.dispose();
    super.dispose();
  }

  /// Notes of the person shown.
  List<Note> get notes;

  /// Saves the client/owner with the [next] notes, then calls `onChange()`.
  void persist(List<Note> next);

  /// `briefClient(...).rec` / `briefOwner(...).rec`
  String computeBrief();

  /// Noun in the default placeholder ("العميل" / "المالك").
  String get whoNoun;

  void runBrief() {
    setState(() => busy = true);
    // the design computes from the notes at click time
    final rec = computeBrief();
    Future<void>.delayed(const Duration(milliseconds: 900), () {
      if (!mounted) return;
      setState(() {
        brief = rec;
        busy = false;
      });
    });
  }

  void submitNote() {
    final text = body.text.trim();
    if (text.isEmpty) return;
    final id = editingId;
    final next = id != null
        ? <Note>[for (final n in notes) n.id == id ? Note(id: n.id, kind: kind, text: text) : n]
        : <Note>[Note(id: 'n-${DateTime.now().millisecondsSinceEpoch}', kind: kind, text: text), ...notes];
    persist(next);
    body.text = '';
    setState(() => editingId = null);
  }

  Widget buildNotes() {
    final ph = kind == 'رفض'
        ? 'سبب الرفض…'
        : kind == 'قبول'
        ? 'على ماذا وافق؟'
        : 'ماذا قال $whoNoun؟';
    final canSave = body.text.trim().isNotEmpty;
    final composer = Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: kCliLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SizedBox(
            height: 24,
            child: Row(
              children: <Widget>[
                const LucideIcon(Lu.fileText, size: 24, strokeWidth: 1.8, color: AppColors.forest),
                const SizedBox(width: 8),
                Tx(editingId != null ? 'تعديل الملاحظة' : 'إرفاق ملاحظة', style: ts(16, w: 700, lh: 24, c: AppColors.forest)),
              ],
            ),
          ),
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(16)),
            child: Row(
              children: <Widget>[
                for (var i = 0; i < kNoteKinds.length; i++) ...<Widget>[if (i > 0) const SizedBox(width: 4), Expanded(child: _tab(kNoteKinds[i]))],
              ],
            ),
          ),
          const SizedBox(height: 10),
          FieldBox(
            controller: body,
            focusNode: box,
            placeholder: ph,
            style: ts(16, lh: 25, c: AppColors.ink),
            height: 92,
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(14)),
            multiline: true,
            inputFormatters: <TextInputFormatter>[Utf16LengthLimit(500)],
          ),
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: <Widget>[
              Tx('${body.text.length}/500', style: ts(13, lh: 19.5, c: AppColors.ink)),
              const SizedBox(width: 8),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (editingId != null) ...<Widget>[
                    Tap(
                      onTap: () {
                        body.text = '';
                        setState(() {
                          editingId = null;
                          kind = 'تفكير';
                        });
                      },
                      child: Container(
                        height: 48,
                        decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(16)),
                        alignment: Alignment.center,
                        child: Tx('إلغاء', style: ts(16, w: 600, lh: 24, c: AppColors.ink)),
                      ),
                    ),
                    const SizedBox(width: 8),
                  ],
                  Opacity(
                    opacity: canSave ? 1 : 0.35,
                    child: Tap(
                      onTap: canSave ? submitNote : null,
                      child: Container(
                        height: 40,
                        constraints: const BoxConstraints(minWidth: 88),
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(16)),
                        alignment: Alignment.center,
                        child: Tx(editingId != null ? 'حفظ التعديل' : 'حفظ', style: ts(16, w: 600, lh: 24, c: AppColors.white)),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );

    final log = <Widget>[
      SizedBox(
        height: 24,
        child: Row(
          children: <Widget>[
            const LucideIcon(Lu.clock, size: 24, strokeWidth: 1.8, color: AppColors.forest),
            const SizedBox(width: 8),
            Tx('السجل', style: ts(16, w: 700, lh: 24, c: AppColors.forest)),
          ],
        ),
      ),
    ];
    if (notes.isEmpty) {
      log.add(
        Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Tx(
            'لا سجلات بعد.',
            style: ts(16, lh: 26.5, c: AppColors.muted),
            align: TextAlign.center,
          ),
        ),
      );
    } else {
      for (final n in notes) {
        log.add(_article(n));
      }
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        composer,
        const SizedBox(height: 12),
        for (var i = 0; i < log.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 8), log[i]],
      ],
    );
  }

  Widget _tab(String k) {
    final on = kind == k;
    final fg = on ? AppColors.white : AppColors.muted;
    final icon = k == 'قبول' ? Lu.check : (k == 'رفض' ? Lu.x : Lu.fileText);
    return Tap(
      onTap: () => setState(() => kind = k),
      child: Container(
        height: 40,
        decoration: BoxDecoration(color: on ? kindInk(k) : const Color(0x00000000), borderRadius: BorderRadius.circular(12)),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            LucideIcon(icon, size: 14, strokeWidth: k == 'تفكير' ? 1.8 : 2.2, color: fg),
            const SizedBox(width: 4),
            Flexible(
              child: Tx(k, style: ts(13, w: 600, lh: 19.5, c: fg), maxLines: 1, softWrap: false),
            ),
          ],
        ),
      ),
    );
  }

  Widget _article(Note n) {
    Widget act(String icon, VoidCallback onTap) => Tap(
      onTap: onTap,
      child: Container(
        width: 32,
        height: 32,
        decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(10)),
        alignment: Alignment.center,
        child: LucideIcon(icon, size: 24, strokeWidth: 2, color: AppColors.forest),
      ),
    );
    return Container(
      key: ValueKey<String>(n.id),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const <BoxShadow>[BoxShadow(color: kCliLine, spreadRadius: 1)],
      ),
      child: Stack(
        children: <Widget>[
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(18, 14, 14, 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: <Widget>[
                    Container(
                      height: 22,
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      decoration: BoxDecoration(color: kindPillBg(n.kind), borderRadius: BorderRadius.circular(999)),
                      alignment: Alignment.center,
                      child: Tx(n.kind, style: ts(12, w: 700, lh: 18, c: kindInk(n.kind))),
                    ),
                    const SizedBox(width: 8),
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        act(Lu.pencil, () {
                          body.text = n.text;
                          setState(() {
                            editingId = n.id;
                            kind = n.kind;
                          });
                          box.requestFocus();
                        }),
                        const SizedBox(width: 6),
                        act(Lu.trash2, () {
                          persist(notes.where((x) => x.id != n.id).toList());
                          if (editingId == n.id) {
                            body.text = '';
                            setState(() => editingId = null);
                          }
                        }),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Tx(n.text, style: ts(16, lh: 26.5, c: AppColors.ink)),
              ],
            ),
          ),
          PositionedDirectional(
            start: 0,
            top: 14,
            bottom: 14,
            child: Container(
              width: 3,
              decoration: BoxDecoration(color: kindBar(n.kind), borderRadius: BorderRadius.circular(3)),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------- the form

/// Form pieces shared by the client form.
Widget cliLabel(String t, Widget child) => Column(
  crossAxisAlignment: CrossAxisAlignment.stretch,
  children: <Widget>[
    Tx(t, style: ts(14, w: 600, lh: 21, c: AppColors.muted)),
    const SizedBox(height: 6),
    child,
  ],
);

Widget cliInput(TextEditingController c, {bool ltr = false, TextInputType? keyboard, bool digits = false}) => FieldBox(
  controller: c,
  style: ts(18, lh: 27, c: AppColors.ink),
  height: 52,
  padding: const EdgeInsets.symmetric(horizontal: 14),
  decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(14)),
  dir: ltr ? TextDirection.ltr : null,
  keyboardType: keyboard,
  inputFormatters: digits ? <TextInputFormatter>[FilteringTextInputFormatter.digitsOnly] : null,
);

Widget cliSelect({required String value, required List<Opt> options, required ValueChanged<String> onChanged}) => SelectBox(
  value: value,
  options: options,
  onChanged: onChanged,
  style: ts(18, lh: 25.5, c: AppColors.ink),
  height: 52,
  padding: const EdgeInsets.symmetric(horizontal: 14),
  decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(14)),
);

/// Collapsible multi-select.
class CliPick extends StatelessWidget {
  const CliPick({
    super.key,
    required this.icon,
    required this.title,
    required this.sub,
    required this.open,
    required this.onToggle,
    required this.selectedLabels,
    required this.all,
    required this.on,
    required this.onPick,
    this.ink = AppColors.deep,
  });

  final String icon;
  final String title;
  final String sub;
  final bool open;
  final VoidCallback onToggle;
  final List<String> selectedLabels;
  final List<Opt> all;
  final Set<String> on;
  final ValueChanged<String> onPick;

  /// Text colour of the surrounding page.
  final Color ink;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(16)),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tap(
            onTap: onToggle,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              child: Row(
                children: <Widget>[
                  SizedBox(
                    width: 22,
                    child: Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: LucideIcon(icon, size: 20, strokeWidth: 1.8, color: AppColors.muted),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Tx(title, style: ts(18, w: 600, lh: 27, c: ink)),
                        const SizedBox(height: 2),
                        Tx(sub, style: ts(14, w: 500, lh: 21, c: AppColors.muted)),
                      ],
                    ),
                  ),
                  const SizedBox(width: 10),
                  Container(
                    constraints: const BoxConstraints(minWidth: 28),
                    height: 28,
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    decoration: BoxDecoration(color: AppColors.ink, borderRadius: BorderRadius.circular(999)),
                    alignment: Alignment.center,
                    child: Tx('${on.length}', style: ts(14, w: 700, lh: 21, c: AppColors.white)),
                  ),
                  const SizedBox(width: 10),
                  SizedBox(
                    width: 18,
                    height: 20,
                    child: OverflowBox(
                      maxWidth: 20,
                      alignment: AlignmentDirectional.centerStart,
                      child: Transform.rotate(
                        angle: open ? 3.14159265 : 0,
                        child: const LucideIcon(Lu.chevronDown, size: 20, color: AppColors.muted),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (!open && selectedLabels.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: <Widget>[
                  for (final l in selectedLabels)
                    Container(
                      height: 28,
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      decoration: BoxDecoration(color: AppColors.ink, borderRadius: BorderRadius.circular(999)),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[Tx(l, style: ts(14, w: 600, lh: 21, c: AppColors.white))],
                      ),
                    ),
                ],
              ),
            ),
          if (open)
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: <Widget>[
                  for (final o in all)
                    Tap(
                      onTap: () => onPick(o.value),
                      child: Container(
                        height: 36,
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        decoration: BoxDecoration(
                          color: on.contains(o.value) ? AppColors.ink : AppColors.white,
                          borderRadius: BorderRadius.circular(999),
                        ),
                        foregroundDecoration: on.contains(o.value)
                            ? null
                            : BoxDecoration(
                                borderRadius: BorderRadius.circular(999),
                                border: Border.all(color: kCliLine),
                              ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[Tx(o.label, style: ts(15, w: 600, lh: 22.5, c: on.contains(o.value) ? AppColors.white : ink))],
                        ),
                      ),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
