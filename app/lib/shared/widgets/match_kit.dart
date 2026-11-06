// Matching helpers: scoring, the AI scanning card, the voice mic and the
// result cards with their "match / no match" marks.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/core/simulations.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/ask_card.dart';
import 'package:zon/shared/widgets/listing_card.dart';

// Shared pieces of the AI matching / semantic search pages.

// ---------------------------------------------------------------- scoring

/// Splits a text into lowercase search words.
List<String> semTokens(String s) => s.toLowerCase().split(RegExp(r'[^؀-ۿa-z0-9]+')).where((w) => w.length > 1).toList();

/// All searchable text of a listing (title, place, type, description).
String listingHay(Listing l) => '${l.title} ${l.city} ${l.neighborhood} ${kTypeLabel[l.propertyType]} ${l.description}';

/// Relevance of a result for the query [q], adjusted by the user's
/// "match / no match" marks.
int scoreByFeedback(String hay, String q, Map<String, SemMark> marks, String id) {
  var s = 0;
  final h = hay.toLowerCase();
  for (final w in semTokens(q)) {
    if (h.contains(w)) s += 2;
  }
  marks.forEach((mid, m) {
    final hit = semTokens(m.reason).where((w) => h.contains(w)).length;
    if (m.v == 'match') {
      if (mid == id) s += 18;
      s += hit * 3;
    } else {
      if (mid == id) s -= 28;
      s -= hit * 4;
    }
  });
  return s;
}

/// Sorts by score, highest first, keeping the original order for ties.
List<T> sortByScore<T>(List<T> items, int Function(T) score) {
  final indexed = <(int, T, int)>[for (var i = 0; i < items.length; i++) (i, items[i], score(items[i]))];
  indexed.sort((a, b) {
    final c = b.$3 - a.$3;
    return c != 0 ? c : a.$1 - b.$1;
  });
  return indexed.map((e) => e.$2).toList();
}

// ---------------------------------------------------------------- Hunt

const List<String> kHuntSteps = <String>['يبحث', 'يفكر', 'يصنّف', 'يرتّب'];
const Map<String, List<(String, String)>> _huntRows = <String, List<(String, String)>>{
  'listings': <(String, String)>[('فيلا — النرجس', 'عرض'), ('شقة — العليا', 'عرض'), ('دور — حطين', 'عرض'), ('أرض — الملقا', 'عرض')],
  'requests': <(String, String)>[('طلب شراء فيلا', 'طلب'), ('طلب إيجار شقة', 'طلب'), ('طلب شراء أرض', 'طلب'), ('طلب إيجار دور', 'طلب')],
  'people': <(String, String)>[('سارة القحطاني', 'باحث'), ('فهد العتيبي', 'وسيط'), ('محمد الشهري', 'باحث'), ('نورة الحربي', 'وسيط')],
};
const List<String> _huntMark = <String>['يفحص', 'يفكر', 'يصنّف', 'مطابق'];

/// The people rows without simulations: roles, never a made-up name.
const List<(String, String)> _huntRoles = <(String, String)>[('رغبة باحث', 'باحث'), ('عميل وسيط', 'وسيط'), ('رغبة باحث', 'باحث'), ('عميل وسيط', 'وسيط')];

/// The AI "scanning…" card shown while matches are being searched.
class Hunt extends StatefulWidget {
  const Hunt({super.key, required this.title, this.hint, this.steps = kHuntSteps, this.kind = 'listings'});

  final String title;
  final String? hint;
  final List<String> steps;

  /// "listings" | "requests" | "people"
  final String kind;

  @override
  State<Hunt> createState() => _HuntState();
}

class _HuntState extends State<Hunt> {
  int i = 0;
  int pct = 8;
  Timer? _a;
  Timer? _b;

  @override
  void initState() {
    super.initState();
    _a = Timer.periodic(const Duration(milliseconds: 850), (_) => setState(() => i = (i + 1) % widget.steps.length));
    _b = Timer.periodic(const Duration(milliseconds: 110), (_) => setState(() => pct = pct >= 94 ? 94 : pct + 3));
  }

  @override
  void dispose() {
    _a?.cancel();
    _b?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final rows = !kSimulations && widget.kind == 'people' ? _huntRoles : _huntRows[widget.kind] ?? _huntRows['listings']!;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx(widget.title, style: ts(18, w: 700, lh: 27, c: AppColors.forest)),
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              for (var n = 0; n < widget.steps.length; n++) ...<Widget>[
                if (n > 0) const SizedBox(width: 6),
                Expanded(
                  child: Container(
                    height: 32,
                    decoration: BoxDecoration(color: i == n ? AppColors.forest : AppColors.mist, borderRadius: BorderRadius.circular(999)),
                    alignment: Alignment.center,
                    child: Tx(widget.steps[n], style: ts(13, w: 600, lh: 19.5, c: i == n ? AppColors.white : AppColors.sage)),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 12),
          for (var n = 0; n < rows.length; n++) ...<Widget>[
            if (n > 0) const SizedBox(height: 6),
            _row(rows[n], n == i % rows.length, _huntMark[(n + i) % _huntMark.length]),
          ],
          Container(
            height: 3,
            margin: const EdgeInsets.only(top: 12, bottom: 8),
            decoration: BoxDecoration(color: AppColors.mistLine, borderRadius: BorderRadius.circular(99)),
            clipBehavior: Clip.antiAlias,
            alignment: AlignmentDirectional.centerStart,
            child: FractionallySizedBox(
              widthFactor: pct / 100,
              heightFactor: 1,
              child: const ColoredBox(color: AppColors.forest),
            ),
          ),
          if (widget.hint != null) Tx(widget.hint!, style: ts(13, lh: 19.5, c: AppColors.muted)),
        ],
      ),
    );
  }

  Widget _row((String, String) r, bool on, String mark) {
    return CustomPaint(
      foregroundPainter: on ? const _InsetLeftBar(radius: 14, width: 3, color: AppColors.forest) : null,
      child: Container(
        constraints: const BoxConstraints(minHeight: 42),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(color: on ? AppColors.mist : AppColors.tint, borderRadius: BorderRadius.circular(14)),
        child: Row(
          children: <Widget>[
            Tx(r.$2, style: ts(11, w: 700, lh: 16.5, c: AppColors.muted)),
            const SizedBox(width: 8),
            Expanded(
              child: Tx(r.$1, style: ts(14, w: 700, lh: 21, c: AppColors.deep)),
            ),
            const SizedBox(width: 8),
            Tx(mark, style: ts(12, w: 700, lh: 18, c: AppColors.forest)),
          ],
        ),
      ),
    );
  }
}

/// A [width]-wide colour bar inside the left edge of a rounded box.
class _InsetLeftBar extends CustomPainter {
  const _InsetLeftBar({required this.radius, required this.width, required this.color});

  final double radius;
  final double width;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final box = RRect.fromRectAndRadius(Offset.zero & size, Radius.circular(radius));
    final shifted = box.shift(Offset(width, 0));
    final path = Path.combine(PathOperation.difference, Path()..addRRect(box), Path()..addRRect(shifted));
    canvas.drawPath(path, Paint()..color = color);
  }

  @override
  bool shouldRepaint(_InsetLeftBar old) => old.color != color || old.width != width || old.radius != radius;
}

// ---------------------------------------------------------------- folio / marks

/// Semantic "match / no match" marks shared by search and matching pages.
mixin SemMarksState<T extends StatefulWidget> on State<T> {
  late Map<String, SemMark> mark = store.loadSemMarks();
  String? openWhy;

  void pickMark(String id, String v) {
    final had = (mark[id]?.reason ?? '').trim().isNotEmpty;
    setState(() {
      final cur = SemMark(v: v, reason: mark[id]?.reason ?? '');
      if (cur.reason.trim().isNotEmpty) store.saveSemMark(id, cur);
      mark = <String, SemMark>{...mark, id: cur};
      openWhy = had ? null : id;
    });
  }

  void reasonMark(String id, String reason) {
    setState(() => mark = <String, SemMark>{...mark, id: SemMark(v: mark[id]?.v ?? 'match', reason: reason)});
  }

  void saveMark(String id) {
    final m = mark[id];
    if (m != null && m.reason.trim().isNotEmpty) store.saveSemMark(id, m);
    setState(() => openWhy = null);
  }

  /// A compact result with a listing card.
  Widget folioListing(Listing l) => FolioMini(
    key: ValueKey<String>('f-${l.id}'),
    card: ListingCard(listing: l, mini: true),
    mark: mark[l.id],
    open: openWhy == l.id,
    onPick: (v) => pickMark(l.id, v),
    onReason: (r) => reasonMark(l.id, r),
    onSave: () => saveMark(l.id),
  );

  /// A compact result with a request card.
  Widget folioRequest(RequestItem r) => FolioMini(
    key: ValueKey<String>('f-${r.id}'),
    card: AskCard(item: r),
    mark: mark[r.id],
    open: openWhy == r.id,
    label: 'قيّم هذا الطلب',
    onPick: (v) => pickMark(r.id, v),
    onReason: (x) => reasonMark(r.id, x),
    onSave: () => saveMark(r.id),
  );
}

/// A result card with its rating mark under it.
class FolioMini extends StatelessWidget {
  const FolioMini({
    super.key,
    required this.card,
    required this.mark,
    required this.open,
    required this.onPick,
    required this.onReason,
    required this.onSave,
    this.label = 'قيّم هذا العرض',
  });

  final Widget card;
  final SemMark? mark;
  final bool open;
  final ValueChanged<String> onPick;
  final ValueChanged<String> onReason;
  final VoidCallback onSave;
  final String label;

  @override
  Widget build(BuildContext context) {
    final hidden = (mark?.reason ?? '').trim().isNotEmpty && !open;
    Widget? fm;
    if (!hidden) {
      fm = FolioMark(mark: mark, open: open, label: label, onPick: onPick, onReason: onReason, onSave: onSave);
    }
    return Container(
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(22),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: <Widget>[card, ?fm]),
      ),
    );
  }
}

/// "مطابق / غير مطابق" rating of a result, with an optional reason.
class FolioMark extends StatefulWidget {
  const FolioMark({
    super.key,
    required this.mark,
    required this.open,
    required this.onPick,
    required this.onReason,
    required this.onSave,
    this.label = 'قيّم هذا العرض',
  });

  final SemMark? mark;
  final bool open;
  final ValueChanged<String> onPick;
  final ValueChanged<String> onReason;
  final VoidCallback onSave;
  final String label;

  @override
  State<FolioMark> createState() => _FolioMarkState();
}

class _FolioMarkState extends State<FolioMark> {
  late final TextEditingController _why = TextEditingController(text: widget.mark?.reason ?? '');
  final FocusNode _focus = FocusNode();

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      // `onBlur={() => { if (mark?.reason?.trim()) onSave(); }}`
      if (!_focus.hasFocus && (widget.mark?.reason ?? '').trim().isNotEmpty) widget.onSave();
    });
  }

  @override
  void didUpdateWidget(FolioMark old) {
    super.didUpdateWidget(old);
    final r = widget.mark?.reason ?? '';
    if (r != _why.text) _why.text = r;
  }

  @override
  void dispose() {
    _why.dispose();
    _focus.dispose();
    super.dispose();
  }

  Widget _duo(String label, String v) {
    final on = widget.mark?.v == v;
    return Tap(
      onTap: () => widget.onPick(v),
      child: Container(
        height: 44,
        decoration: BoxDecoration(color: on ? AppColors.forest : AppColors.mist, borderRadius: BorderRadius.circular(14)),
        foregroundDecoration: on
            ? null
            : BoxDecoration(
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: AppColors.forest, width: 1.5),
              ),
        alignment: Alignment.center,
        child: Tx(label, style: ts(16, w: 600, lh: 24, c: on ? AppColors.white : AppColors.forest)),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final canSave = (widget.mark?.reason ?? '').trim().isNotEmpty;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: AppColors.mistLine)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx(widget.label, style: ts(14, w: 600, lh: 21, c: AppColors.forest)),
          const SizedBox(height: 8),
          Row(
            children: <Widget>[
              Expanded(child: _duo('مطابق', 'match')),
              const SizedBox(width: 8),
              Expanded(child: _duo('غير مطابق', 'nomatch')),
            ],
          ),
          if (widget.open) ...<Widget>[
            const SizedBox(height: 10),
            Tx('اكتب السبب عشان تجيك نتائج أدق', style: ts(13, w: 600, lh: 19.5, c: AppColors.muted)),
            const SizedBox(height: 8),
            FieldBox(
              controller: _why,
              focusNode: _focus,
              placeholder: 'مثال: بعيد عن المدارس · الغرف أقل من طلبك · السعر فوق الميزانية',
              style: ts(15, w: 500, lh: 22.5, c: AppColors.deep),
              height: 64,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(12)),
              multiline: true,
              onChanged: widget.onReason,
            ),
            const SizedBox(height: 8),
            Tap(
              onTap: canSave ? widget.onSave : null,
              child: Container(
                height: 44,
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(12)),
                alignment: Alignment.center,
                child: Tx('حفظ السبب', style: ts(16, w: 600, lh: 24, c: AppColors.white)),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Box with the seeker's wish text.
Widget radarWish(String text) => Container(
  margin: const EdgeInsets.symmetric(horizontal: 2),
  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
  decoration: BoxDecoration(color: AppColors.surface, borderRadius: BorderRadius.circular(18), boxShadow: AppShadows.hairline),
  child: Tx(text, style: ts(13, lh: 21, c: AppColors.muted)),
);

/// Two-button segmented control.
class MxTabs extends StatelessWidget {
  const MxTabs({super.key, required this.items, this.mode = false});

  final List<(String, bool, VoidCallback)> items;

  /// Smaller variant (used inside the dark desk).
  final bool mode;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(14)),
      child: Row(
        children: <Widget>[
          for (var i = 0; i < items.length; i++) ...<Widget>[
            if (i > 0) const SizedBox(width: 4),
            Expanded(
              child: Tap(
                onTap: items[i].$3,
                child: Container(
                  height: 40,
                  decoration: BoxDecoration(
                    color: items[i].$2 ? AppColors.forest : const Color(0x00000000),
                    borderRadius: BorderRadius.circular(mode ? 10 : 12),
                  ),
                  alignment: Alignment.center,
                  child: Tx(
                    items[i].$1,
                    style: mode
                        ? ts(14, w: 600, lh: 21, c: items[i].$2 ? AppColors.white : AppColors.sage)
                        : ts(14, w: 600, lh: 21, c: items[i].$2 ? AppColors.white : AppColors.sage),
                    align: TextAlign.center,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
