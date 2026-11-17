// مساعد المطابقة — seekers see listings matching their wish; developers see
// offers and the seekers / brokers who match their projects.
//
// With a server (ServerAi) nothing is ranked or made up here: the wish's results are the server's three lists in
// its order (its own type · the rest of the city within the budget · projects with the unit models that fit), asked once the wish is known
// and again when it is saved; a developer's seekers and brokers are the server's, for a published project of his.
// The page says when the order is not a model's or is the simulation's, and shows the plan's refusals.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/simulations.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/server/server_ai.dart';
import 'package:zon/data/server/server_chats.dart';
import 'package:zon/data/server/server_desk.dart' show NeedsPlan;
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_my_projects.dart';
import 'package:zon/data/server/server_wishes.dart';
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

  /// "sale" (شراء) | "rent" (استئجار): chosen, never assumed.
  late String purpose = _seed?.purpose ?? '';
  late String city = (_seed != null && _seed.city.isNotEmpty && _seed.city != 'all') ? _seed.city : 'الرياض';
  late String hood = (_seed != null && _seed.hoods.isNotEmpty) ? _seed.hoods.first : kCities['الرياض']![0];
  late String type = (_seed != null && _seed.types.isNotEmpty) ? _seed.types.first : 'all';
  late final TextEditingController text = TextEditingController(text: _seed?.text ?? '');
  bool scan = false;

  /// "match" | "other"
  String tab = 'match';
  late Wish? query = (_seed?.text ?? '').isNotEmpty ? _seed : null;
  late Map<String, SemMark> rankMarks = mark;

  /// With a server: a save or a stop on its way, and what it answered when it refused.
  bool _sending = false;
  String _error = '';
  final OperationKey _operation = OperationKey('wish');

  /// The account the wish shown belongs to (Store.serverEpoch): an answer for another one is dropped, and when the
  /// account changes under this page, the new account's wish is fetched in its place.
  int _epoch = store.serverEpoch;
  String? _account = store.readSession()?.id;

  /// With a server: what it found for the wish, or why it gave nothing; [_asks] counts the questions, so that only
  /// the last one's answer is shown.
  Matching? _found;
  String _matchError = '';
  String? _refused;
  int _asks = 0;

  @override
  String get markContext => 'matching';

  /// The server's results for the wish it keeps.
  Future<void> _match() async {
    final epoch = store.serverEpoch;
    setState(() {
      scan = true;
      tab = 'match';
      _forgetFound();
    });
    final ask = _asks; // after forgetting: this question is the last one
    bool mine() => _current(epoch) && ask == _asks;
    try {
      final found = await ServerAi.matching(epoch: epoch);
      if (mine()) setState(() => _found = found);
    } on NeedsPlan {
      if (mine()) setState(() => _refused = 'مساعد المطابقة ضمن الباقات.');
    } on QuotaReached catch (e) {
      if (mine()) setState(() => _refused = e.message);
    } on ApiError catch (e) {
      if (mine()) setState(() => _matchError = e.forUser);
    } on AccountChanged {
      // nothing is asked under the next account
    } finally {
      if (mine()) setState(() => scan = false);
    }
  }

  void _forgetFound() {
    _asks++; // an answer still on its way is for a wish no longer shown
    _found = null;
    _matchError = '';
    _refused = null;
    forgetMarks();
  }

  @override
  void initState() {
    super.initState();
    text.addListener(() => setState(() {}));
    if (Api.on) {
      store.addListener(_onAccount);
      unawaited(_loadFromServer());
    }
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
    store.removeListener(_onAccount);
    text.dispose();
    super.dispose();
  }

  /// Another account (or none) under this page: nothing of the one before stays shown.
  void _onAccount() {
    final account = store.readSession()?.id;
    if (!mounted || (_epoch == store.serverEpoch && _account == account)) return;
    _epoch = store.serverEpoch;
    _account = account;
    text.text = '';
    setState(() {
      saved = null;
      query = null;
      edit = true;
      scan = false;
      _sending = false;
      _error = '';
      _forgetFound();
    });
    if (account != null) unawaited(_loadFromServer());
  }

  /// Whether an answer asked for under [epoch] still belongs to the account shown.
  bool _current(int epoch) => mounted && epoch == store.serverEpoch;

  /// The wish kept on the server is the one shown (this phone may hold an older one, or none).
  Future<void> _loadFromServer() async {
    final epoch = _epoch = store.serverEpoch;
    try {
      final w = await ServerWishes.mine();
      if (!_current(epoch)) return;
      if (w == null) {
        if (saved != null) store.clearWish();
        text.text = '';
        setState(() {
          saved = null;
          query = null;
          edit = true;
          scan = false;
          _forgetFound();
        });
        return;
      }
      store.saveWish(w);
      setState(() {
        saved = w;
        query = w.text.isNotEmpty ? w : null;
        edit = w.text.isEmpty;
      });
      if (w.text.isNotEmpty) unawaited(_match());
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    }
  }

  /// [w] saved on the server first; shown once it is there.
  Future<void> _saveOnServer(Wish w) async {
    setState(() {
      _sending = true;
      _error = '';
    });
    final epoch = store.serverEpoch;
    try {
      final kept = await ServerWishes.save(w, operation: _operation.forBody(ServerWishes.form(w)));
      if (!_current(epoch)) return;
      _operation.done(); // saved: the next save is another operation
      setState(() => _sending = false);
      _persistHere(kept);
    } on ApiError catch (e) {
      if (_current(epoch)) {
        setState(() {
          _sending = false;
          _error = e.forUser;
        });
      }
    }
  }

  Future<void> _stopOnServer() async {
    final w = saved;
    setState(() {
      _sending = true;
      _error = '';
    });
    final epoch = store.serverEpoch;
    try {
      if (w != null) await ServerWishes.remove(w);
      if (!_current(epoch)) return;
      _operation.done(); // a wish made after this one is another operation, even if it reads the same
      setState(() => _sending = false);
      _stopHere();
    } on ApiError catch (e) {
      if (!mounted || !_current(epoch)) return;
      setState(() => _sending = false);
      await showAppNotice(context, icon: Lu.circleAlert, title: 'لم تُحذف الرغبة', message: e.forUser);
    }
  }

  Wish _payload() => Wish(
    id: saved?.id,
    purpose: purpose,
    city: city,
    hoods: hood.isNotEmpty ? <String>[hood] : const <String>[],
    types: type == 'all' ? const <String>[] : <String>[type],
    budget: '',
    text: text.text.trim(),
  );

  void _persist(Wish w) => Api.on ? unawaited(_saveOnServer(w)) : _persistHere(w);

  void _persistHere(Wish w) {
    if (Api.on) {
      // saved there: its results are asked for — nothing ranked here, no wait added
      store.saveWish(w);
      setState(() {
        saved = w;
        edit = false;
        query = w;
      });
      unawaited(_match());
      return;
    }
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
    // the radar's notice, simulated on this phone
    Future<void>.delayed(const Duration(milliseconds: 4000), store.pingRadar);
  }

  void _stop() => Api.on ? unawaited(_stopOnServer()) : _stopHere();

  void _stopHere() {
    store.clearWish();
    text.text = '';
    setState(() {
      saved = null;
      query = null;
      edit = true;
      scan = false;
      _forgetFound();
    });
  }

  ({List<Listing> hit, List<Listing> other}) _hits(List<Listing> list) {
    final qy = query;
    if (qy == null) return (hit: const <Listing>[], other: const <Listing>[]);
    final scored = sortByScore<Listing>(list, (l) => scoreByFeedback(listingHay(l), qy.text, rankMarks, l.id));
    final hit = scored.where(qy.fits).toList();
    final hitIds = hit.map((l) => l.id).toSet();
    final other = scored.where((l) => (qy.city == 'all' || l.city == qy.city) && !hitIds.contains(l.id)).take(8).toList();
    return (hit: hit, other: other);
  }

  @override
  Widget build(BuildContext context) {
    final found = _found;
    final hits = Api.on
        ? (
            hit: <Listing>[for (final r in found?.matched ?? const <Scored<Listing>>[]) r.item],
            other: <Listing>[for (final r in found?.broaden ?? const <Scored<Listing>>[]) r.item],
          )
        : _hits(store.allListings());
    final rows = tab == 'match' ? hits.hit : hits.other;
    final projects = found?.projects ?? const <MatchedProject>[];
    // with a server the results are an answer: none is shown before it comes, or when it was refused
    final answered = query != null && (!Api.on || found != null);
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
          if (s.purpose.isNotEmpty)
            CrmMeters(top: 12, tiles: <(Widget, String)>[(meter(s.purpose == 'rent' ? 'استئجار' : 'شراء'), 'شراء أو استئجار')]),
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
                  purpose = s.purpose;
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
                  value: purpose,
                  color: kDeskAccent,
                  options: const <Opt>[Opt('', 'اختر', disabled: true), Opt('sale', 'شراء'), Opt('rent', 'استئجار')],
                  onChanged: (v) => setState(() => purpose = v),
                ),
                'شراء أو استئجار',
              ),
            ],
          ),
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
          if (_error.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Tx(_error, style: ts(14, w: 600, lh: 20, c: const Color(0xFFFFB4A8))),
            ),
          _mxRow(<(String, VoidCallback?)>[
            (_sending ? 'جارٍ الحفظ…' : 'حفظ الرغبة', purpose.isEmpty || text.text.trim().isEmpty || _sending ? null : () => _persist(_payload())),
            if (saved != null) ('إلغاء', () => setState(() => edit = false)),
          ]),
        ],
      );
    }

    final children = <Widget>[
      desk,
      if (scan) const Hunt(title: 'الذكاء يفعّل الرادار', hint: 'يحفظ الرغبة ويراقب العروض الجديدة', kind: 'listings'),
      if (!scan && _matchError.isNotEmpty) FormError(_matchError),
      if (!scan && _refused != null) PlanDoor(_refused!),
      if (!scan && found != null && !found.empty) AiNote(ai: found.ai),
      if (!scan && found != null && found.empty && found.suggestRequest) ...<Widget>[
        radarWish('لا عروض تطابق رغبتك الآن. انشر طلباً ليصل إلى أصحاب العروض.'),
        AppButton(label: 'طلباتي', tone: ButtonTone.secondary, onTap: () => Nav.go(context, '/account/requests')),
      ],
      if (!scan && answered)
        MxTabs(
          items: <(String, bool, VoidCallback)>[
            ('عروض مطابقة · ${hits.hit.length}', tab == 'match', () => setState(() => tab = 'match')),
            ('عروض أخرى · ${hits.other.length}', tab == 'other', () => setState(() => tab = 'other')),
            if (Api.on) ('مشاريع · ${projects.length}', tab == 'projects', () => setState(() => tab = 'projects')),
          ],
        ),
      if (!scan && tab == 'other' && answered)
        radarWish(
          Api.on
              ? 'في مدينتك وضمن ميزانيتك — حي أو نوع مختلف. علّم مطابق أو غير مطابق واكتب السبب.'
              : 'نفس المدينة فقط — نوع أو حي مختلف. علّم مطابق أو غير مطابق واكتب السبب.',
        ),
      if (!scan && answered && tab == 'projects')
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < projects.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 10), _MatchedProjectCard(projects[i])],
            if (projects.isEmpty) radarWish('لا نتائج في هذا التبويب'),
          ],
        ),
      if (!scan && answered && tab != 'projects')
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

/// A project the wish suits, with the unit models of it that fit — the server's, the best first.
class _MatchedProjectCard extends StatelessWidget {
  const _MatchedProjectCard(this.match);

  final MatchedProject match;

  @override
  Widget build(BuildContext context) {
    return Container(
      key: ValueKey<String>('m-${match.project.id}'),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(22),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            ListingCard(listing: match.project, mini: true),
            for (final u in match.units)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                decoration: const BoxDecoration(
                  border: Border(top: BorderSide(color: AppColors.mistLine)),
                ),
                child: Tx(
                  <String>[
                    'نموذج ${u.name}',
                    if (u.bedrooms != null) '${u.bedrooms} غرف',
                    if (u.area != null) '${sar(u.area!)} م²',
                    if (u.price != null) '${sar(u.price!)} ريال',
                  ].join(' · '),
                  style: ts(14, w: 600, lh: 21, c: AppColors.deep),
                ),
              ),
          ],
        ),
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

  /// With a server: his published projects, and for the one picked the seekers and brokers it found — or why not.
  List<Listing>? _mine;
  Ranked<ProjectSeeker>? _seekers;
  List<AreaBroker>? _brokers;
  String _error = '';
  String? _refused;
  int _epoch = store.serverEpoch;
  int _asks = 0;

  @override
  void initState() {
    super.initState();
    if (Api.on) {
      store.addListener(_onAccount);
      unawaited(_load());
    }
  }

  @override
  void dispose() {
    store.removeListener(_onAccount);
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    setState(() {
      pid = '';
      _mine = null;
      _forget();
    });
    if (store.readSession() != null) unawaited(_load());
  }

  void _forget() {
    _asks++; // an answer still on its way is for a project no longer shown
    scan = false;
    ready = false;
    _seekers = null;
    _brokers = null;
    _error = '';
    _refused = null;
  }

  /// Only a published project is marketed (the server refuses any other).
  Future<void> _load() async {
    final epoch = _epoch;
    try {
      final all = await ServerMyProjects.mine();
      if (!mounted || epoch != store.serverEpoch) return;
      setState(
        () => _mine = <Listing>[
          for (final p in all)
            if (p.status == 'published') p,
        ],
      );
    } on ApiError catch (e) {
      if (mounted && epoch == store.serverEpoch) setState(() => _error = e.forUser);
    }
  }

  Future<void> _ask(String id) async {
    final project = int.tryParse(ServerListings.rawId(id));
    if (project == null) return;
    final epoch = _epoch;
    setState(() {
      pid = id;
      tab = 'seekers';
      _forget();
      scan = true;
    });
    final ask = _asks;
    bool mine() => mounted && epoch == store.serverEpoch && ask == _asks;
    try {
      final brokers = await ServerAi.brokers(project, epoch: epoch);
      if (!mine()) return;
      setState(() => _brokers = brokers);
      final seekers = await ServerAi.searchers(project, epoch: epoch);
      if (mine()) setState(() => _seekers = seekers);
    } on NeedsPlan {
      if (mine()) setState(() => _refused = 'تسويق المشاريع ضمن الباقات.');
    } on QuotaReached catch (e) {
      if (mine()) setState(() => _refused = e.message);
    } on ApiError catch (e) {
      if (mine()) setState(() => _error = e.forUser);
    } on AccountChanged {
      // nothing is asked under the next account
    } finally {
      if (mine()) {
        setState(() {
          scan = false;
          ready = true;
        });
      }
    }
  }

  void _pick(String id) {
    if (Api.on) {
      unawaited(_ask(id));
      return;
    }
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
    final projects = Api.on ? (_mine ?? const <Listing>[]) : store.myListings().where((l) => l.kind == 'project').toList();
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
          else if (Api.on && _error.isNotEmpty)
            FormError(_error)
          else if (Api.on && _mine == null)
            const LoadingBox()
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
          _forget();
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
      if (Api.on && !scan && _error.isNotEmpty) FormError(_error),
      if (Api.on && !scan && _refused != null) PlanDoor(_refused!),
      if (Api.on && !scan && tab == 'seekers' && _seekers != null) ...<Widget>[
        if (_seekers!.results.isNotEmpty) AiNote(ai: _seekers!.ai),
        _hitList(<Widget>[
          for (final s in _seekers!.results)
            _HitCard(
              key: ValueKey<String>('s-${s.item.username}'),
              name: s.item.name,
              role: 'باحث',
              small: <String>[
                ...s.item.neighborhoods,
                for (final t in s.item.types) kTypeLabel[t] ?? t,
                if (s.item.budgetMax != null) 'حتى ${sar(s.item.budgetMax!)} ريال',
                if (s.item.unitName != null) 'الأقرب: نموذج ${s.item.unitName}',
              ].join(' · '),
              quote: s.item.notes.isEmpty ? null : s.item.notes,
              acts: <(String, VoidCallback)>[
                (Lu.messageCircle, () => Nav.go(context, ServerChats.startPath(s.item.username, about: pid, name: s.item.name, role: 'seeker'))),
              ],
            ),
          if (_seekers!.results.isEmpty) radarWish('لا باحث تطابق رغبته هذا المشروع الآن.'),
        ]),
      ],
      if (Api.on && !scan && tab == 'brokers' && _brokers != null)
        _hitList(<Widget>[
          for (final b in _brokers!)
            _HitCard(
              key: ValueKey<String>('b-${b.username}'),
              name: b.name,
              role: 'وسيط',
              small: 'لديه ${b.clients} عملاء مطابقون — تواصل مع الوسيط',
              acts: <(String, VoidCallback)>[
                (Lu.messageCircle, () => Nav.go(context, ServerChats.startPath(b.username, about: pid, name: b.name, role: 'broker'))),
              ],
            ),
          if (_brokers!.isEmpty) radarWish('لا وسيط في مدينة المشروع لديه عملاء مطابقون الآن.'),
        ]),
      if (!Api.on && !scan && ready && tab == 'seekers')
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
      if (!Api.on && !scan && ready && tab == 'brokers')
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
