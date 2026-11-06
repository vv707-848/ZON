// مساعد المطابقة — seekers see listings matching their wish; developers see
// offers and the seekers / brokers who match their projects.

import 'package:flutter/material.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/simulations.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/widgets/desk.dart';
import 'package:zon/shared/widgets/dictation_field.dart';
import 'package:zon/shared/widgets/listing_card.dart';
import 'package:zon/shared/widgets/match_kit.dart';

const Color _soft = Color(0xFFC5D0C8);

TextStyle get _ringValue => ts(16, w: 700, lh: 16, c: AppColors.white);

/// One or two buttons on the dark card: the first is the main action (white),
/// the second a lighter one. A single button takes the full width.
Widget _mxRow(List<(String, VoidCallback?)> buttons) {
  Widget btn(int i) => AppButton(label: buttons[i].$1, tone: i == 0 ? ButtonTone.secondary : ButtonTone.glass, onTap: buttons[i].$2);

  return Padding(
    padding: const EdgeInsets.only(top: 12, bottom: 2),
    child: buttons.length == 1
        ? btn(0)
        : Row(
            children: <Widget>[
              Expanded(child: btn(0)),
              const SizedBox(width: 8),
              Expanded(child: btn(1)),
            ],
          ),
  );
}

/// مساعد المطابقة for seekers: the wish and the matching listings.
class SeekerMatchingView extends StatefulWidget {
  const SeekerMatchingView({super.key, this.inWrap = false});

  /// Without the page padding (embedded in another page).
  final bool inWrap;

  @override
  State<SeekerMatchingView> createState() => _SeekerMatchingViewState();
}

class _SeekerMatchingViewState extends State<SeekerMatchingView> with SemMarksState<SeekerMatchingView> {
  late final Wish? _seed = store.loadWish();
  late Wish? saved = _seed;
  late bool edit = (_seed?.text ?? '').isEmpty;
  late String city = (_seed != null && _seed.city.isNotEmpty && _seed.city != 'all') ? _seed.city : 'الرياض';
  late String hood = (_seed != null && _seed.hoods.isNotEmpty) ? _seed.hoods.first : kCities['الرياض']![0];
  late String type = (_seed != null && _seed.types.isNotEmpty) ? _seed.types.first : 'all';
  late final TextEditingController text = TextEditingController(text: _seed?.text ?? '');
  bool scan = false;

  /// "match" | "other"
  String tab = 'match';
  late Wish? query = (_seed?.text ?? '').isNotEmpty ? _seed : null;
  late Map<String, SemMark> rankMarks = mark;

  @override
  void initState() {
    super.initState();
    text.addListener(() => setState(() {}));
    // useEffect(() => { if (!scan && query) clearRadarPing(); }, [scan, query])
    if (query != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        // Opening the assistant shows the results: they count as seen and
        // the bell's matching notification is read.
        store.markHitsSeen(store.matchingIds(query!, store.allListings()));
        store.clearRadarPing();
        notifications.markKindRead(NoteKind.match);
      });
    }
  }

  @override
  void dispose() {
    text.dispose();
    super.dispose();
  }

  Wish _payload() => Wish(
    city: city,
    hoods: hood.isNotEmpty ? <String>[hood] : const <String>[],
    types: type == 'all' ? const <String>[] : <String>[type],
    budget: '',
    text: text.text.trim(),
  );

  void _persist(Wish w) {
    final list = store.allListings();
    store.saveWish(w);
    store.markHitsSeen(store.matchingIds(w, list));
    setState(() {
      saved = w;
      edit = false;
      query = w;
      scan = true;
      rankMarks = <String, SemMark>{...mark};
      tab = 'match';
    });
    Future<void>.delayed(const Duration(milliseconds: 2800), () {
      if (!mounted) return;
      setState(() => scan = false);
      if (query != null) store.clearRadarPing();
    });
    Future<void>.delayed(const Duration(milliseconds: 4000), store.pingRadar);
  }

  void _stop() {
    store.clearWish();
    text.text = '';
    setState(() {
      saved = null;
      query = null;
      edit = true;
    });
  }

  ({List<Listing> hit, List<Listing> other}) _hits(List<Listing> list) {
    final qy = query;
    if (qy == null) return (hit: const <Listing>[], other: const <Listing>[]);
    final scored = sortByScore<Listing>(list, (l) => scoreByFeedback(listingHay(l), qy.text, rankMarks, l.id));
    final hit = scored
        .where(
          (l) =>
              (qy.city == 'all' || l.city == qy.city) &&
              (qy.hoods.isEmpty || qy.hoods.contains(l.neighborhood)) &&
              (qy.types.isEmpty || qy.types.contains(l.propertyType)),
        )
        .toList();
    final hitIds = hit.map((l) => l.id).toSet();
    final other = scored.where((l) => (qy.city == 'all' || l.city == qy.city) && !hitIds.contains(l.id)).take(8).toList();
    return (hit: hit, other: other);
  }

  @override
  Widget build(BuildContext context) {
    final list = store.allListings();
    final hits = _hits(list);
    final rows = tab == 'match' ? hits.hit : hits.other;
    final live = (saved?.text ?? '').isNotEmpty && !edit;
    final hoods = kCities[city] ?? const <String>[];

    final Widget desk;
    if (saved != null && live) {
      final s = saved!;
      Widget meter(String v) => Tx(
        v,
        style: ts(14, w: 700, lh: 17.5, c: AppColors.white),
        align: TextAlign.center,
        maxLines: 1,
        softWrap: false,
        overflow: TextOverflow.ellipsis,
      );
      desk = DeskCard(
        showScan: false,
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 14),
        children: <Widget>[
          DeskJewel(value: 'ON', small: 'رادار', title: 'الرادار يعمل', ringColor: const Color(0xFF9BE7C4), valueStyle: _ringValue),
          CrmMeters(
            top: 12,
            tiles: <(Widget, String)>[
              (meter(s.city), 'مدينة'),
              (meter(s.hoods.isNotEmpty && s.hoods.first.isNotEmpty ? s.hoods.first : 'الكل'), 'حي'),
              (meter(s.types.isNotEmpty ? (kTypeLabel[s.types.first] ?? '') : 'كل الأنواع'), 'نوع عقار'),
            ],
          ),
          Container(
            margin: const EdgeInsets.only(top: 12),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(color: const Color(0x14FFFFFF), borderRadius: BorderRadius.circular(14)),
            child: Tx(s.text, style: ts(16, w: 700, lh: 24, c: AppColors.white)),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Tx('إشعار عند عرض مطابق', style: ts(13, lh: 21, c: _soft)),
          ),
          _mxRow(<(String, VoidCallback?)>[
            (
              'تعديل الرغبة',
              () {
                final c = s.city != 'all' ? s.city : 'الرياض';
                text.text = s.text;
                setState(() {
                  city = c;
                  hood = s.hoods.isNotEmpty ? s.hoods.first : kCities[c]![0];
                  type = s.types.isNotEmpty ? s.types.first : 'all';
                  edit = true;
                });
              },
            ),
            ('حذف', _stop),
          ]),
        ],
      );
    } else {
      desk = DeskCard(
        showScan: false,
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 14),
        children: <Widget>[
          DeskJewel(value: '—', small: 'رغبة', title: 'حدد رغبتك', frac: 0.18, valueStyle: _ringValue),
          CrmMeters(
            top: 12,
            tiles: <(Widget, String)>[
              (
                MeterSelect(
                  value: city,
                  color: kDeskAccent,
                  options: optsOf(kCities.keys),
                  onChanged: (v) => setState(() {
                    city = v;
                    final hs = kCities[v] ?? const <String>[];
                    hood = hs.isNotEmpty ? hs.first : '';
                  }),
                ),
                'مدينة',
              ),
              (MeterSelect(value: hood, color: kDeskAccent, options: optsOf(hoods), onChanged: (v) => setState(() => hood = v)), 'حي'),
              (
                MeterSelect(
                  value: type,
                  color: kDeskAccent,
                  options: <Opt>[const Opt('all', 'كل الأنواع'), for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
                  onChanged: (v) => setState(() => type = v),
                ),
                'نوع عقار',
              ),
            ],
          ),
          // wish text box — the dictation mic sits inside its frame
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: DictationField(
              controller: text,
              dark: true,
              height: 172,
              padding: const EdgeInsetsDirectional.fromSTEB(14, 12, 10, 4),
              decoration: BoxDecoration(color: const Color(0x14FFFFFF), borderRadius: BorderRadius.circular(14)),
              header: Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Tx('وصف الرغبة', style: ts(12, w: 700, lh: 18, c: _soft)),
              ),
              placeholder: 'مثال: فيلا عوائل قرب مدارس حتى ثلاثة ملايين',
              placeholderColor: _soft,
              textStyle: ts(16, w: 600, lh: 25, c: AppColors.white),
            ),
          ),
          _mxRow(<(String, VoidCallback?)>[
            ('حفظ الرغبة', text.text.trim().isEmpty ? null : () => _persist(_payload())),
            if (saved != null) ('إلغاء', () => setState(() => edit = false)),
          ]),
        ],
      );
    }

    final children = <Widget>[
      desk,
      if (scan) const Hunt(title: 'الذكاء يفعّل الرادار', hint: 'يحفظ الرغبة ويراقب العروض الجديدة', kind: 'listings'),
      if (!scan && query != null)
        MxTabs(
          items: <(String, bool, VoidCallback)>[
            ('عروض مطابقة · ${hits.hit.length}', tab == 'match', () => setState(() => tab = 'match')),
            ('عروض أخرى · ${hits.other.length}', tab == 'other', () => setState(() => tab = 'other')),
          ],
        ),
      if (!scan && tab == 'other' && query != null) radarWish('نفس المدينة فقط — نوع أو حي مختلف. علّم مطابق أو غير مطابق واكتب السبب.'),
      if (!scan && query != null)
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < rows.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 10), folioListing(rows[i])],
            if (rows.isEmpty) radarWish('لا نتائج في هذا التبويب'),
          ],
        ),
    ];

    return Padding(
      padding: widget.inWrap ? EdgeInsets.zero : const EdgeInsets.fromLTRB(16, 16, 16, 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 14), children[i]],
        ],
      ),
    );
  }
}

/// Developer matching: search offers / market the projects.
class DeveloperMatchingView extends StatefulWidget {
  const DeveloperMatchingView({super.key});

  @override
  State<DeveloperMatchingView> createState() => _DeveloperMatchingViewState();
}

class _DeveloperMatchingViewState extends State<DeveloperMatchingView> {
  /// "listings" | "project"
  String tab = 'listings';

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          MxTabs(
            mode: true,
            items: <(String, bool, VoidCallback)>[
              ('بحث عروض', tab == 'listings', () => setState(() => tab = 'listings')),
              ('تسويق المشاريع', tab == 'project', () => setState(() => tab = 'project')),
            ],
          ),
          const SizedBox(height: 14),
          if (tab == 'listings') const SeekerMatchingView(inWrap: true) else const ProjectMarket(),
        ],
      ),
    );
  }
}

/// Pick a project, then see the seekers / brokers who match it.
class ProjectMarket extends StatefulWidget {
  const ProjectMarket({super.key});

  @override
  State<ProjectMarket> createState() => _ProjectMarketState();
}

class _ProjectMarketState extends State<ProjectMarket> {
  String pid = '';

  /// "seekers" | "brokers"
  String tab = 'seekers';
  bool scan = false;
  bool ready = false;

  void _pick(String id) {
    setState(() {
      pid = id;
      tab = 'seekers';
      ready = false;
      scan = true;
    });
    Future<void>.delayed(const Duration(milliseconds: 3400), () {
      if (!mounted) return;
      setState(() {
        scan = false;
        ready = true;
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    // The developer's own projects (published by this account).
    final projects = store.myListings().where((l) => l.kind == 'project').toList();
    Listing? project;
    for (final p in projects) {
      if (p.id == pid) project = p;
    }

    if (pid.isEmpty) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Tx('تسويق المشاريع', style: ts(12, w: 700, lh: 18, c: AppColors.muted)),
              const SizedBox(height: 4),
              Tx('اختر مشروعاً', style: ts(24, w: 700, lh: 36, c: AppColors.ink)),
            ],
          ),
          const SizedBox(height: 14),
          Tx('حدد مشروعاً أولاً. بعدها يظهر الباحثون وعملاء الوسطاء المطابقون.', style: ts(14, lh: 23, c: AppColors.muted)),
          const SizedBox(height: 14),
          if (projects.isNotEmpty)
            // result cards
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  for (var i = 0; i < projects.length; i++) ...<Widget>[
                    if (i > 0) const SizedBox(height: 18),
                    ListingCard(key: ValueKey<String>(projects[i].id), listing: projects[i], onSelect: () => _pick(projects[i].id)),
                  ],
                ],
              ),
            )
          else
            Tx('انشر مشروعاً أولاً — بعد النشر يظهر هنا لتسويقه.', style: ts(14, w: 500, lh: 22.5, c: AppColors.muted)),
        ],
      );
    }

    const seekerNotes = 'أبحث عن فيلا بحديقة قريبة من المدارس';
    final seekers = <(String, String, String, String, String, String)>[
      if (kSimulations) ...<(String, String, String, String, String, String)>[
        ('s1', 'سارة القحطاني', project?.city ?? 'الرياض', project?.neighborhood ?? 'النرجس', project?.propertyType ?? 'villa', seekerNotes),
        ('s2', 'محمد الشهري', project?.city ?? 'الرياض', '', project?.propertyType ?? 'apartment', 'رغبة مسجّلة'),
      ],
    ];
    const brokers = <(String, String, String, int)>[
      if (kSimulations) ...<(String, String, String, int)>[('b1', 'فهد العتيبي', '0551111111', 4), ('b2', 'نورة الحربي', '0554444444', 2)],
    ];

    final tabs = MxTabs(
      items: <(String, bool, VoidCallback)>[
        ('باحثون', tab == 'seekers', () => setState(() => tab = 'seekers')),
        ('عملاء وسطاء', tab == 'brokers', () => setState(() => tab = 'brokers')),
      ],
    );
    final children = <Widget>[
      Tap(
        onTap: () => setState(() {
          pid = '';
          scan = false;
          ready = false;
        }),
        child: Container(
          height: 40,
          padding: const EdgeInsets.symmetric(horizontal: 4),
          alignment: AlignmentDirectional.centerStart,
          child: Tx('تغيير المشروع', style: ts(14, w: 600, lh: 21, c: AppColors.muted)),
        ),
      ),
      if (project != null) ListingCard(listing: project),
      tabs,
      if (scan)
        Hunt(
          title: tab == 'seekers' ? 'الذكاء يبحث عن باحثين مطابقين' : 'الذكاء يبحث عن عملاء الوسطاء',
          hint: tab == 'seekers' ? 'يقرأ الرغبات ويصنّف المطابق على المشروع' : 'يصنّف عملاء الوسطاء حسب مدينة المشروع',
          kind: 'people',
        ),
      if (!scan && ready && tab == 'seekers')
        _hitList(<Widget>[
          Tap(
            onTap: () => Nav.go(context, '/chats/barq'),
            child: Container(
              height: 46,
              decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(14)),
              alignment: Alignment.center,
              child: Tx('إرسال المشروع للكل', style: ts(15, w: 600, lh: 22.5, c: AppColors.white)),
            ),
          ),
          for (final s in seekers)
            _HitCard(
              key: ValueKey<String>(s.$1),
              name: s.$2,
              role: 'باحث',
              small: '${s.$3}${s.$4.isNotEmpty ? ' · ${s.$4}' : ''} · ${kTypeLabel[s.$5] ?? s.$5}',
              quote: s.$6,
              acts: <(String, VoidCallback)>[(Lu.messageCircle, () => Nav.go(context, '/chats/barq'))],
            ),
        ]),
      if (!scan && ready && tab == 'brokers')
        _hitList(<Widget>[
          for (final b in brokers)
            _HitCard(
              key: ValueKey<String>(b.$1),
              name: b.$2,
              role: 'وسيط',
              small: 'لديه ${b.$4} عملاء — تواصل مع الوسيط',
              acts: <(String, VoidCallback)>[(Lu.phone, () => callPhone(b.$3)), (Lu.messageCircle, () => Nav.go(context, '/chats/barq'))],
            ),
        ]),
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 14), children[i]],
      ],
    );
  }

  Widget _hitList(List<Widget> items) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: <Widget>[
      for (var i = 0; i < items.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 10), items[i]],
    ],
  );
}

/// A matched seeker / broker.
class _HitCard extends StatelessWidget {
  const _HitCard({super.key, required this.name, required this.role, required this.small, this.quote, required this.acts});

  final String name;
  final String role;
  final String small;
  final String? quote;
  final List<(String, VoidCallback)> acts;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Row(
        children: <Widget>[
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(14)),
            alignment: Alignment.center,
            child: Tx(name.trim().isEmpty ? '' : name.trim()[0], style: ts(16, w: 700, lh: 24, c: AppColors.white)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Row(
                  children: <Widget>[
                    Flexible(
                      child: Tx(name, style: ts(16, w: 700, lh: 24, c: AppColors.ink)),
                    ),
                    const SizedBox(width: 6),
                    Tx(role, style: ts(12, w: 700, lh: 18, c: AppColors.muted)),
                  ],
                ),
                const SizedBox(height: 3),
                Tx(small, style: ts(13, lh: 19.5, c: AppColors.muted)),
                if (quote != null) ...<Widget>[const SizedBox(height: 4), Tx(quote!, style: ts(14, lh: 21, c: AppColors.deep))],
              ],
            ),
          ),
          const SizedBox(width: 12),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              for (var i = 0; i < acts.length; i++) ...<Widget>[
                if (i > 0) const SizedBox(width: 6),
                Tap(
                  onTap: acts[i].$2,
                  child: Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(14)),
                    alignment: Alignment.center,
                    child: LucideIcon(acts[i].$1, size: 16, color: AppColors.white),
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
