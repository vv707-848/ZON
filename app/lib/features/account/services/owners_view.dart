// إدارة الملاك — owners whose properties the broker markets, with notes.

import 'package:flutter/material.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/plans_view.dart' show PayGate;
import 'package:zon/features/account/services/widgets/client_kit.dart';
import 'package:zon/features/account/services/widgets/desk.dart';

/// AI-style recommendation from an owner's notes.
String briefOwnerRec(Owner owner) {
  final n = owner.notes;
  final ok = n.where((x) => x.kind == 'قبول').length;
  final no = n.where((x) => x.kind == 'رفض').length;
  final think = n.where((x) => x.kind == 'تفكير').length;
  var rec = 'المالك يحتاج متابعة منتظمة حتى تتضح نيته.';
  if (n.isEmpty) {
    rec = 'ما فيه ملاحظة بعد. أول مكالمة تبني الملف.';
  } else if (no > 0 && no >= ok) {
    rec = 'الرفض ظاهر. لا تضغط البيع — غيّر العرض أو اتركه يبرد.';
  } else if (ok > 0 && no == 0) {
    rec = 'فيه قبول. هذا وقت إغلاق الصفقة أو تحديث العرض.';
  } else if (think > 0) {
    rec = 'ما زال يفكّر. ملاحظة اليوم أهم من عرض جديد.';
  }
  return rec;
}

/// The owner's listing (by id, or by title for older records).
Listing? listingOfOwner(Owner o, List<Listing> listings) {
  for (final l in listings) {
    if (l.id == o.listingId || l.title == o.listingTitle) return l;
  }
  return null;
}

/// A number without a trailing ".0".

/// دفتر الملاك — the broker's owners.
class OwnersView extends StatefulWidget {
  const OwnersView({super.key});

  @override
  State<OwnersView> createState() => _OwnersViewState();
}

class _OwnersViewState extends State<OwnersView> {
  String? openId;
  String city = 'all';
  String hood = 'all';
  String type = 'all';
  final TextEditingController _q = TextEditingController();

  @override
  void initState() {
    super.initState();
    _q.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final rows = store.owners();
    final listings = store.allListings();
    if (!billing.isSubscribed()) {
      return const PayGate(
        title: 'إدارة الملاك',
        text: 'متاحة ضمن خدمة الوسيط. المالك يُسجَّل عند نشر عرضك، والملاحظات تُلخَّص لتسهيل المتابعة. البيع يتم خارج التطبيق.',
      );
    }
    Owner? selected;
    for (final o in rows) {
      if (o.id == openId) selected = o;
    }
    if (selected != null) {
      return OwnerProfile(
        key: ValueKey<String>('op-${selected.id}'),
        owner: selected,
        onBack: () => setState(() => openId = null),
        onChange: () => setState(() {}),
      );
    }
    final q = _q.text.trim();
    final shown = rows.where((o) {
      final l = listingOfOwner(o, listings);
      if (city != 'all' && l?.city != city) return false;
      if (hood != 'all' && l?.neighborhood != hood) return false;
      if (type != 'all' && l?.propertyType != type) return false;
      if (q.isNotEmpty && !'${o.name} ${o.listingTitle} ${l?.neighborhood ?? ''}'.contains(q)) return false;
      return true;
    }).toList();
    final hoods = city == 'all' ? const <String>[] : (kCities[city] ?? const <String>[]);

    final children = <Widget>[
      DeskCard(
        showScan: false,
        children: <Widget>[
          DeskJewel(value: '${rows.length}', small: 'ملاك', title: 'دفتر الملاك'),
          CrmMeters(
            tiles: <(Widget, String)>[
              (
                MeterSelect(
                  value: city,
                  color: kDeskAccent,
                  options: <Opt>[const Opt('all', 'الكل'), ...optsOf(kCities.keys)],
                  onChanged: (v) => setState(() {
                    city = v;
                    hood = 'all';
                  }),
                ),
                'مدينة',
              ),
              (
                MeterSelect(
                  value: hood,
                  color: kDeskAccent,
                  enabled: city != 'all',
                  options: <Opt>[const Opt('all', 'الكل'), ...optsOf(hoods)],
                  onChanged: (v) => setState(() => hood = v),
                ),
                'حي',
              ),
              (
                MeterSelect(
                  value: type,
                  color: kDeskAccent,
                  options: <Opt>[const Opt('all', 'الكل'), for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
                  onChanged: (v) => setState(() => type = v),
                ),
                'نوع عقار',
              ),
            ],
          ),
        ],
      ),
      FindBox(controller: _q),
      if (shown.isEmpty)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 18),
          child: Tx(
            'لا ملاك في هذا النطاق. أضِف مالكاً عند نشر عرض جديد.',
            style: ts(16, lh: 25, c: AppColors.muted),
            align: TextAlign.center,
          ),
        )
      else
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < shown.length; i++) ...<Widget>[
              if (i > 0) const SizedBox(height: 10),
              _card(shown[i], listingOfOwner(shown[i], listings)),
            ],
          ],
        ),
    ];

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 14), children[i]],
        ],
      ),
    );
  }

  Widget _card(Owner o, Listing? listing) {
    final shot = listing != null && listing.images.isNotEmpty ? listing.images.first : null;
    final media = SizedBox(
      width: 96,
      height: 96,
      child: Stack(
        children: <Widget>[
          Positioned.fill(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(18),
              child: shot != null
                  ? ColoredBox(color: AppColors.mist, child: AppImage(shot))
                  : ColoredBox(
                      color: AppColors.forest,
                      child: Center(
                        child: Tx(firstChar(o.name), style: ts(28, w: 700, lh: 42, c: AppColors.white)),
                      ),
                    ),
            ),
          ),
          if (listing != null)
            PositionedDirectional(
              top: 8,
              start: 8,
              child: Container(
                height: 20,
                padding: const EdgeInsets.symmetric(horizontal: 8),
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
                child: Tx(listing.purpose == 'rent' ? 'إيجار' : 'بيع', style: ts(11, w: 700, lh: 21, c: AppColors.white)),
              ),
            ),
        ],
      ),
    );
    final body = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
          children: <Widget>[
            Flexible(
              child: Tx(
                o.name,
                style: ts(14, w: 700, lh: 17.5, c: AppColors.forest),
                maxLines: 1,
                softWrap: false,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            const SizedBox(width: 6),
            Tx('مالك', style: ts(12, w: 600, lh: 18, c: AppColors.muted)),
          ],
        ),
        const SizedBox(height: 3),
        Tx(
          (listing?.title ?? '').isNotEmpty ? listing!.title : (o.listingTitle.isNotEmpty ? o.listingTitle : 'بدون عرض'),
          style: ts(16, w: 700, lh: 21, c: AppColors.forest),
          maxLines: 1,
          softWrap: false,
          overflow: TextOverflow.ellipsis,
        ),
        const SizedBox(height: 3),
        Tx(
          listing != null ? '${kTypeLabel[listing.propertyType]} · ${listing.city} · ${listing.neighborhood}' : 'بدون تفاصيل العرض',
          style: ts(13, w: 600, lh: 19.5, c: AppColors.muted),
          maxLines: 1,
          softWrap: false,
          overflow: TextOverflow.ellipsis,
        ),
        const SizedBox(height: 3),
        SizedBox(
          height: 26,
          child: Align(
            alignment: AlignmentDirectional.topStart,
            child: listing != null
                ? SarText(sar(listing.price), style: ts(15, w: 700, lh: 22.5, c: AppColors.forest), iconW: 15, iconH: 16, gap: 5)
                : Tx('—', style: ts(15, w: 700, lh: 22.5, c: AppColors.forest)),
          ),
        ),
      ],
    );
    final call = o.phone.isNotEmpty
        ? Tap(onTap: () => callPhone(o.phone), child: _callDot(AppColors.forest, AppColors.white))
        : _callDot(const Color(0xFFF2F4F3), const Color(0xFF8A918C));
    return Container(
      key: ValueKey<String>(o.id),
      constraints: const BoxConstraints(minHeight: 116),
      padding: const EdgeInsets.fromLTRB(12, 10, 10, 10),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Tap(
              onTap: () => setState(() => openId = o.id),
              child: Row(
                children: <Widget>[
                  media,
                  const SizedBox(width: 12),
                  Expanded(child: body),
                ],
              ),
            ),
          ),
          const SizedBox(width: 8),
          SizedBox(
            width: 44,
            child: Align(alignment: AlignmentDirectional.centerStart, child: call),
          ),
        ],
      ),
    );
  }

  Widget _callDot(Color bg, Color fg) => Container(
    width: 40,
    height: 40,
    decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
    alignment: Alignment.center,
    child: LucideIcon(Lu.phone, size: 16, strokeWidth: 2, color: fg),
  );
}

/// An owner's page: the listing, AI advice and notes.
class OwnerProfile extends StatefulWidget {
  const OwnerProfile({super.key, required this.owner, required this.onBack, required this.onChange});

  final Owner owner;
  final VoidCallback onBack;
  final VoidCallback onChange;

  @override
  State<OwnerProfile> createState() => _OwnerProfileState();
}

class _OwnerProfileState extends State<OwnerProfile> with CliNotesState<OwnerProfile> {
  Owner get owner => widget.owner;

  @override
  List<Note> get notes => owner.notes;

  @override
  void persist(List<Note> next) {
    store.saveOwner(owner.withNotes(next));
    widget.onChange();
  }

  @override
  String computeBrief() => briefOwnerRec(owner);

  @override
  String get whoNoun => 'المالك';

  @override
  Widget build(BuildContext context) {
    final listing = listingOfOwner(owner, store.allListings());
    String or(String? v) => (v ?? '').isNotEmpty ? v! : '—';
    final children = <Widget>[
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: CliBack(label: 'الملاك', onTap: widget.onBack),
      ),
      CliHero(
        name: owner.name,
        roleLabel: 'مالك',
        notes: owner.notes,
        phone: owner.phone,
        second: listing != null
            ? CliHeroAct(
                icon: Lu.home,
                label: 'العرض',
                onTap: () => Nav.go(context, listing.kind == 'project' ? '/projects/${listing.id}' : '/listings/${listing.id}'),
              )
            : const CliHeroAct(icon: Lu.home, label: 'بدون عرض', off: true),
      ),
      CliPrefs(
        icon: Lu.home,
        title: 'تفاصيل العرض',
        specs: <(String, String)>[
          ('الغرض', listing?.purpose == 'rent' ? 'إيجار' : 'بيع'),
          ('النوع', listing != null ? (kTypeLabel[listing.propertyType] ?? '') : '—'),
          if (more) ...<(String, String)>[
            ('المدينة', or(listing?.city)),
            ('الحي', or(listing?.neighborhood)),
            ('السعر', listing != null ? moneyTxt(plainNumber(listing.price)) : '—'),
            ('العنوان', (listing?.title ?? '').isNotEmpty ? listing!.title : or(owner.listingTitle)),
          ],
        ],
        more: more,
        onMore: () => setState(() => more = !more),
      ),
      CliBrief(aiOn: true, hasNotes: owner.notes.isNotEmpty, busy: busy, rec: brief, onRun: runBrief),
      buildNotes(),
    ];
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
      child: DefaultTextStyle.merge(
        style: const TextStyle(color: AppColors.ink),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
      ),
    );
  }
}
