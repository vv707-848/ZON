// Editor of a developer's project: project details and the units (add, edit,
// mark sold or available, delete). The project details are saved with
// "حفظ المشروع".
//
// Units (QA F03 / F04): a unit added here is a DRAFT kept in the editor
// until it is saved — leaving without saving changes nothing that is
// published. «حفظ الوحدة» writes one unit only after it passes the checks
// (a price ≥ 0, and in a ready project — the edited one or the published
// one — a verified licence of its own) and after checking the account owns
// the project; a refused unit leaves the published one as it was. «متاح» /
// «مباع» apply at once to a published unit; deleting a published unit asks
// first. Saving never re-publishes a hidden project (QA F07).
//
// Licences (brief L01): a ready project has a licence number per unit (in
// each unit's form, with its own verification); saving a ready project
// needs every unit verified. Off-plan hides the fields and skips the check;
// numbers already typed stay in the units but are not shown while the
// project is off-plan. A project-level number saved by older versions is
// NOT copied onto the units — the editor asks to complete each unit.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/add_kit.dart';
import 'package:zon/features/details/widgets/unit_row.dart' show SoldStamp;

/// Saves the units of [listing] and refreshes its available / sold counts.
void _persistUnits(Listing listing, List<UnitModel> units) {
  final sold = units.where((u) => u.sold).length;
  store.patchListing(listing.id, <String, Object?>{'units': units.map((u) => u.toJson()).toList(), 'available': units.length - sold, 'sold': sold});
}

/// The project editor.
class ProjectEditor extends StatefulWidget {
  const ProjectEditor({super.key, required this.listing});

  final Listing listing;

  @override
  State<ProjectEditor> createState() => _ProjectEditorState();
}

class _ProjectEditorState extends State<ProjectEditor> {
  Listing get listing => widget.listing;

  late String title = listing.title;
  late String c = listing.city;
  late String hood = listing.neighborhood;
  late PType ptype = listing.propertyType;
  late String delivery = listing.delivery ?? 'ready';
  late String landUse = listing.landUse ?? 'residential';
  late String desc = listing.description;
  late String mapsLink = listing.mapsLink ?? '';

  /// Number saved on the project by version 1.1 (not valid for the units).
  late final String legacyLicence = listing.license.trim();

  /// The ownership refusal (ACCESS-02).
  String error = '';

  /// Set by the first save attempt: from then on the problems are shown.
  bool showErrors = false;

  bool get ready => ptype == 'land' || delivery == 'ready';

  /// Units as published (written to the store).
  late List<UnitModel> saved = List<UnitModel>.of(listing.units ?? const <UnitModel>[]);

  /// Units added in this editor and not saved yet — never written alone.
  late List<UnitModel> drafts = saved.isEmpty ? <UnitModel>[emptyUnit(listing.propertyType)] : <UnitModel>[];

  List<UnitModel> get units => <UnitModel>[...saved, ...drafts];

  late List<String> cover = List<String>.of(listing.images);
  String pAi = '';
  String? editId;
  bool done = false;
  Timer? _navTimer;

  @override
  void dispose() {
    _navTimer?.cancel();
    super.dispose();
  }

  bool _isDraft(UnitModel u) => drafts.any((d) => d.id == u.id);

  /// The project as published now is ready (licences per unit required).
  bool get _publishedReady => projectIsReady(store.listingById(listing.id) ?? listing);

  /// A unit written now must carry a verified licence when the edited
  /// project OR the published one is ready.
  bool get _needLicence => ready || _publishedReady;

  /// Why [u] cannot be written now (null when it can).
  String? _unitProblem(UnitModel u) {
    if (!u.price.isFinite || u.price < 0) return 'السعر لا يكون سالبًا';
    if (_needLicence && (u.licenseVerified != true || !licenceFormatOk(u.license ?? ''))) {
      return 'أكمل رقم الترخيص الإعلاني لهذه الوحدة وتحقق منه';
    }
    return null;
  }

  /// Writes the published units, after checking the owner (ACCESS-02).
  bool _persist(List<UnitModel> next) {
    if (!store.canEditListing(listing.id)) {
      setState(() => error = 'لا يمكنك تعديل هذا المشروع.');
      return false;
    }
    _persistUnits(listing, next);
    setState(() => saved = next);
    return true;
  }

  /// «حفظ الوحدة»: null when written, else the reason it was refused.
  String? _saveUnit(UnitModel next) {
    final problem = _unitProblem(next);
    if (problem != null) return problem;
    if (!store.canEditListing(listing.id)) return 'لا يمكنك تعديل هذا المشروع.';
    if (_isDraft(next)) {
      if (!_persist(<UnitModel>[...saved, next])) return error;
      setState(() {
        drafts = drafts.where((d) => d.id != next.id).toList();
        editId = null;
      });
      return null;
    }
    final kept = <UnitModel>[
      for (final x in saved) x.id == next.id ? next.copyWith(<String, Object?>{'sold': x.sold}) : x,
    ];
    if (!_persist(kept)) return error;
    setState(() => editId = null);
    return null;
  }

  /// «متاح» / «مباع»: at once for a published unit, in the editor for a draft.
  void _setSold(UnitModel u, bool sold) {
    if (_isDraft(u)) {
      setState(
        () => drafts = <UnitModel>[
          for (final d in drafts) d.id == u.id ? d.copyWith(<String, Object?>{'sold': sold}) : d,
        ],
      );
      return;
    }
    _persist(<UnitModel>[
      for (final x in saved) x.id == u.id ? x.copyWith(<String, Object?>{'sold': sold}) : x,
    ]);
  }

  /// «حذف»: a draft goes away; a published unit is removed after a
  /// confirmation (it leaves the total and is not counted as sold).
  Future<void> _delete(UnitModel u) async {
    if (_isDraft(u)) {
      setState(() {
        drafts = drafts.where((d) => d.id != u.id).toList();
        if (editId == u.id) editId = null;
      });
      return;
    }
    final ok = await showAppDialog(
      context,
      icon: Lu.trash2,
      title: 'حذف الوحدة؟',
      message: 'تُزال من المشروع المنشور ولا تُحسب مباعة.',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (!ok || !mounted) return;
    if (_persist(saved.where((x) => x.id != u.id).toList()) && editId == u.id) setState(() => editId = null);
  }

  void save() {
    // ACCESS-02: only the account that published the project may save it.
    if (!store.canEditListing(listing.id)) {
      setState(() => error = 'لا يمكنك تعديل هذا المشروع.');
      return;
    }
    final all = units;
    final sold = all.where((u) => u.sold).length;
    // The visibility of the project (active / hidden) is not touched here:
    // saving details never re-publishes a hidden project (QA F07).
    store.patchListing(listing.id, <String, Object?>{
      'title': title.isNotEmpty ? title : listing.title,
      'city': c,
      'neighborhood': hood,
      'propertyType': ptype,
      'description': pAi.isNotEmpty ? pAi : desc,
      'mapsLink': mapsLink.trim().isNotEmpty ? mapsLink.trim() : kUndefined,
      'images': cover.isNotEmpty ? cover : listing.images,
      'delivery': ptype == 'land' ? 'ready' : delivery,
      'landUse': ptype == 'land' ? landUse : kUndefined,
      'units': all.map((u) => u.toJson()).toList(),
      'available': all.length - sold,
      'sold': sold,
    });
    setState(() {
      saved = all;
      drafts = <UnitModel>[];
      done = true;
    });
    _navTimer = Timer(const Duration(milliseconds: 700), () {
      if (mounted) Nav.go(context, '/account/listings');
    });
  }

  /// Units of a ready project without a verified licence of their own.
  List<String> _unitsMissingLicence() => <String>[
    if (ready)
      for (var i = 0; i < units.length; i++)
        if (units[i].licenseVerified != true || !licenceFormatOk(units[i].license ?? '')) units[i].name.isNotEmpty ? units[i].name : 'وحدة ${i + 1}',
  ];

  /// What still stops saving: the title and the description are required;
  /// a ready project needs a verified licence for every unit. Read live, so
  /// the message follows the fixes.
  String? _problem() {
    final missing = _unitsMissingLicence();
    final all = units;
    final badPrice = <String>[
      for (var i = 0; i < all.length; i++)
        if (!all[i].price.isFinite || all[i].price < 0) all[i].name.isNotEmpty ? all[i].name : 'وحدة ${i + 1}',
    ];
    return (title.trim().isEmpty ? 'اكتب عنوان المشروع' : null) ??
        (desc.trim().isEmpty ? 'اكتب وصف المشروع' : null) ??
        (badPrice.isNotEmpty ? 'السعر لا يكون سالبًا: ${badPrice.join('، ')}' : null) ??
        (missing.isNotEmpty ? 'أكمل رقم الترخيص الإعلاني وتحقق منه: ${missing.join('، ')}' : null);
  }

  void _submit() {
    if (_problem() != null) {
      setState(() => showErrors = true);
      return;
    }
    save();
  }

  @override
  Widget build(BuildContext context) {
    const projectTypes = kProjectTypes;
    final first = <GCell>[
      GCell(PhotoDrop(current: cover, onFiles: (v) => setState(() => cover = v)), span: true),
      GCell(
        ProjField(
          icon: Lu.tag,
          label: 'العنوان',
          child: projInput(value: title, onChanged: (v) => setState(() => title = v)),
        ),
        span: true,
      ),
      GCell(
        ProjField(
          icon: Lu.mapPin,
          label: 'المدينة',
          child: projSelect(
            value: c,
            options: optsOf(kCities.keys),
            onChanged: (v) => setState(() {
              c = v;
              hood = kCities[v]![0];
            }),
          ),
        ),
      ),
      GCell(
        ProjField(
          icon: Lu.mapPin,
          label: 'الحي',
          child: projSelect(value: hood, options: optsOf(kCities[c] ?? const <String>[]), onChanged: (v) => setState(() => hood = v)),
        ),
      ),
      GCell(
        ProjField(
          icon: Lu.building2,
          label: 'نوع المشروع',
          child: projSelect(
            value: ptype,
            options: <Opt>[for (final t in projectTypes) Opt(t, kTypeLabel[t]!)],
            onChanged: (v) => setState(() => ptype = v),
          ),
        ),
      ),
      if (ptype == 'land')
        GCell(
          ProjField(
            icon: Lu.landmark,
            label: 'نوع الأرض',
            child: projSelect(
              value: landUse,
              options: <Opt>[for (final e in kLandUseLabel.entries) Opt(e.key, e.value)],
              onChanged: (v) => setState(() => landUse = v),
            ),
          ),
        )
      else
        GCell(
          ProjField(
            icon: Lu.layers,
            label: 'حالة المشروع',
            child: projSelect(
              value: delivery == 'soon' ? 'offplan' : delivery,
              options: <Opt>[Opt('ready', kDeliveryLabel['ready']!), Opt('offplan', kDeliveryLabel['offplan']!)],
              onChanged: (v) => setState(() => delivery = v),
            ),
          ),
        ),
      GCell(
        ProjField(
          icon: Lu.link2,
          label: 'رابط موقع المشروع',
          hint: 'يظهر في صفحة المشروع فقط.',
          child: projInput(
            value: mapsLink,
            onChanged: (v) => setState(() => mapsLink = v),
            placeholder: 'https://maps.google.com/...',
            dir: TextDirection.ltr,
            keyboard: TextInputType.url,
          ),
        ),
        span: true,
      ),
      GCell(
        ProjField(
          icon: Lu.fileCheck,
          label: 'وصف المشروع',
          hint: 'اكتب ما يميّز المشروع. لا تكتب عدد الوحدات أو المساحات.',
          textarea: true,
          count: '${desc.length}/500',
          child: projTextarea(
            value: desc,
            maxLength: 500,
            onChanged: (v) => setState(() {
              desc = v.length > 500 ? v.substring(0, 500) : v;
              pAi = '';
            }),
          ),
        ),
        span: true,
      ),
      GCell(
        AppButton(
          label: pAi.isNotEmpty ? 'تم تنسيق وصف المشروع' : 'تنسيق الوصف ب AI',
          tone: ButtonTone.tonal,
          icon: Lu.sparkles,
          onTap: desc.trim().isEmpty ? null : () => setState(() => pAi = tidyCopy(desc, CopyKind.project)),
        ),
        span: true,
      ),
    ];

    final missing = _unitsMissingLicence();
    final second = <GCell>[
      GCell(projCardHead('الوحدات — ${units.length}'), span: true),
      // Old data: a number on the project only — not assumed valid per unit.
      if (ready && missing.isNotEmpty && legacyLicence.isNotEmpty)
        GCell(
          projHint(
            'الترخيص الإعلاني أصبح لكل وحدة. الرقم المحفوظ على المشروع ($legacyLicence) لا يُنقل للوحدات تلقائيًا — أكمل رقم كل وحدة وتحقق منه.',
          ),
          span: true,
        ),
      for (final u in units)
        GCell(
          ProjectUnitEditor(
            key: ValueKey<String>(u.id),
            u: u,
            ptype: ptype,
            ready: _needLicence,
            showErrors: showErrors,
            open: editId == u.id,
            onAvail: () => _setSold(u, false),
            onSold: () => _setSold(u, true),
            onEdit: () => setState(() => editId = editId == u.id ? null : u.id),
            onDelete: () => unawaited(_delete(u)),
            onSave: _saveUnit,
          ),
          span: true,
        ),
      GCell(
        AppButton(
          label: 'إضافة وحدة',
          tone: ButtonTone.secondary,
          icon: Lu.plus,
          onTap: () {
            // a draft: nothing is written until the unit or the project is saved
            final row = emptyUnit(ptype);
            setState(() {
              drafts = <UnitModel>[...drafts, row];
              editId = row.id;
            });
          },
        ),
        span: true,
      ),
    ];

    return ColoredBox(
      color: kSheetProj,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          ProjHero(
            title: 'تعديل المشروع',
            sub: 'عدّل بيانات المشروع ثم الوحدات من البطاقة',
            image: cover.isNotEmpty && cover[0].isNotEmpty ? cover[0] : typeImage(ptype),
            onBack: () => Nav.go(context, '/account/listings'),
          ),
          ProjCard(child: formGrid(first)),
          ProjCard(child: formGrid(second)),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                // the ownership refusal, else the live validation problem
                if ((error.isNotEmpty ? error : (showErrors ? _problem() : null)) case final String shown) ...<Widget>[
                  FormError(shown),
                  const SizedBox(height: 10),
                ],
                AppButton(label: done ? 'تم الحفظ' : 'حفظ المشروع', size: ButtonSize.large, onTap: done ? null : _submit),
              ],
            ),
          ),
          const SizedBox(height: 120),
        ],
      ),
    );
  }
}

/// One unit model of the project with its tools and form.
class ProjectUnitEditor extends StatefulWidget {
  const ProjectUnitEditor({
    super.key,
    required this.u,
    required this.ptype,
    this.ready = false,
    this.showErrors = false,
    required this.open,
    required this.onAvail,
    required this.onSold,
    required this.onEdit,
    required this.onDelete,
    required this.onSave,
  });

  final UnitModel u;
  final PType ptype;

  /// The project is ready: the unit's licence field is shown and required.
  final bool ready;
  final bool showErrors;
  final bool open;
  final VoidCallback onAvail;
  final VoidCallback onSold;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  /// Writes the unit; returns why it was refused (null when written).
  final String? Function(UnitModel next) onSave;

  @override
  State<ProjectUnitEditor> createState() => _ProjectUnitEditorState();
}

class _ProjectUnitEditorState extends State<ProjectUnitEditor> {
  late UnitModel draft = widget.u;
  String uAi = '';

  /// The price as typed (validated on save — never turned into 0 silently).
  late String priceText = widget.u.price != 0 ? editableNumber(widget.u.price) : '';

  /// Why the last «حفظ الوحدة» was refused.
  String unitError = '';

  void patch(Map<String, Object?> next) => setState(() {
    draft = draft.copyWith(next);
    unitError = '';
  });

  void _save() {
    final price = parsePrice(priceText);
    if (price.error != null) {
      setState(() => unitError = price.error!);
      return;
    }
    final problem = widget.onSave(draft.copyWith(<String, Object?>{'price': price.value}));
    if (problem != null) setState(() => unitError = problem);
  }

  @override
  Widget build(BuildContext context) {
    final u = widget.u;
    final kind = u.propertyType ?? widget.ptype;
    final flags = specsForUnit(kind);
    final n = specsForUnit(draft.propertyType ?? widget.ptype);

    const factColor = AppColors.sage;
    Widget fact(String icon, String text) => Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        LucideIcon(icon, size: 14, color: factColor),
        const SizedBox(width: 4),
        Tx(text, style: ts(14, w: 600, lh: 21, c: factColor), softWrap: false),
      ],
    );
    final facts = <Widget>[];
    final landA = u.landArea ?? u.area;
    final builtA = u.builtArea ?? u.area;
    if (flags.land && (landA ?? 0) > 0) {
      facts.add(fact(Lu.landPlot, areaLabel(landA)));
    } else if (flags.built && (builtA ?? 0) > 0) {
      facts.add(fact(Lu.landPlot, areaLabel(builtA)));
    }
    if (flags.rooms && (u.rooms ?? 0) > 0) facts.add(fact(Lu.bedDouble, sar(u.rooms!)));
    if (flags.rooms && (u.baths ?? 0) > 0) facts.add(fact(Lu.bath, sar(u.baths!)));

    final name = u.name.startsWith('قطعة') ? u.name : 'نموذج ${u.name.isNotEmpty ? u.name : '—'}';
    final body = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Tx(name, style: ts(16, w: 700, lh: 20, c: AppColors.deep)),
        const SizedBox(height: 4),
        if (facts.isNotEmpty)
          SizedBox(
            height: 19.5,
            child: Row(
              children: <Widget>[
                for (var i = 0; i < facts.length; i++) ...<Widget>[if (i > 0) const SizedBox(width: 10), facts[i]],
              ],
            ),
          ),
        const SizedBox(height: 4),
        if (widget.ready) ...<Widget>[_licenceLine(u), const SizedBox(height: 2)],
        // price line
        SizedBox(
          height: 22,
          child: Align(
            alignment: AlignmentDirectional.topStart,
            child: SizedBox(
              height: 19.5,
              child: SarText(
                sar(u.price),
                style: ts(16, w: 700, lh: 21, c: AppColors.forest),
                iconW: 12,
                iconH: 14,
                gap: 3,
                iconColor: AppColors.forest,
              ),
            ),
          ),
        ),
      ],
    );
    final row = Stack(
      clipBehavior: Clip.none,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: <Widget>[
              Container(
                width: 80,
                height: 80,
                clipBehavior: Clip.antiAlias,
                decoration: BoxDecoration(borderRadius: BorderRadius.circular(14)),
                child: AppImage(u.image.isNotEmpty ? u.image : '/listings/apt-1.jpg', fallback: '/listings/apt-1.jpg'),
              ),
              const SizedBox(width: 12),
              Expanded(child: body),
              const SizedBox(width: 12),
            ],
          ),
        ),
        if (u.sold) const Positioned(top: 14, left: 10, child: SoldStamp()),
      ],
    );

    Widget tab(String label, VoidCallback onTap, {bool del = false}) => Tap(
      onTap: onTap,
      child: Container(
        height: 40,
        decoration: BoxDecoration(color: del ? const Color(0xFFF8ECEA) : AppColors.mist, borderRadius: BorderRadius.circular(12)),
        alignment: Alignment.center,
        child: Tx(label, style: ts(14, w: 700, lh: 21, c: del ? const Color(0xFF8A3A32) : AppColors.forest), softWrap: false),
      ),
    );
    final tabs = Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
      decoration: const BoxDecoration(
        color: AppColors.white,
        border: Border(top: BorderSide(color: AppColors.mistLine)),
      ),
      child: equalRow(
        <Widget>[
          tab('متاح', widget.onAvail),
          tab('مباع', widget.onSold),
          tab(widget.open ? 'إغلاق' : 'تعديل', () {
            if (!widget.open) {
              setState(() {
                draft = widget.u;
                priceText = widget.u.price != 0 ? editableNumber(widget.u.price) : '';
                unitError = '';
              });
            }
            widget.onEdit();
          }),
          tab('حذف', widget.onDelete, del: true),
        ],
        columns: 4,
        gap: 6,
      ),
    );

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: <Widget>[row, tabs, if (widget.open) _form(n)]),
    );
  }

  /// "الترخيص: 72001122 ✓" or a warning when the unit has none yet.
  Widget _licenceLine(UnitModel u) {
    final ok = u.licenseVerified == true && licenceFormatOk(u.license ?? '');
    final c = ok ? AppColors.forest : (widget.showErrors ? AppColors.danger : const Color(0xFF8A6D00));
    return Row(
      children: <Widget>[
        LucideIcon(ok ? Lu.badgeCheck : Lu.triangleAlert, size: 13, strokeWidth: 2, color: c),
        const SizedBox(width: 4),
        Flexible(
          child: Tx(
            ok ? 'الترخيص ${u.license}' : 'رقم الترخيص الإعلاني غير مكتمل',
            style: ts(12, w: 600, lh: 18, c: c),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }

  Widget _form(UnitSpecs n) {
    final ptype = widget.ptype;
    final kind = draft.propertyType ?? ptype;
    final areaFields = <Widget>[
      if (n.land)
        ProjField(
          icon: Lu.maximize2,
          label: 'مساحة الأرض م²',
          child: projInput(
            value: editableNumber(draft.landArea),
            dir: TextDirection.ltr,
            onChanged: (v) => patch(<String, Object?>{'landArea': numOr0(v), 'area': numOr0(v)}),
          ),
        ),
      if (n.built)
        ProjField(
          icon: Lu.maximize2,
          label: 'مساحة البناء م²',
          child: projInput(
            value: editableNumber(draft.builtArea),
            dir: TextDirection.ltr,
            onChanged: (v) => patch(<String, Object?>{'builtArea': numOr0(v), 'area': numOr0(v)}),
          ),
        ),
    ];
    final cells = <GCell>[
      GCell(
        ProjField(
          icon: Lu.tag,
          label: 'اسم الوحدة',
          child: projInput(value: draft.name, onChanged: (v) => patch(<String, Object?>{'name': v})),
        ),
      ),
      GCell(
        ProjField(
          icon: Lu.home,
          label: 'نوع الوحدة',
          child: projSelect(
            value: kind,
            options: <Opt>[for (final t in kProjectUnitTypes) Opt(t, kTypeLabel[t]!)],
            onChanged: (next) => setState(() {
              draft = emptyUnit(next).copyWith(<String, Object?>{
                'id': draft.id,
                'name': draft.name,
                'price': draft.price,
                'description': draft.description,
                'sold': draft.sold,
                'license': draft.license,
                'licenseVerified': draft.licenseVerified,
              });
            }),
          ),
        ),
      ),
      if (n.landUse)
        GCell(
          ProjField(
            icon: Lu.landmark,
            label: 'نوع الأرض',
            child: projSelect(
              value: draft.landUse ?? 'residential',
              options: <Opt>[for (final e in kLandUseLabel.entries) Opt(e.key, e.value)],
              onChanged: (v) => patch(<String, Object?>{'landUse': v}),
            ),
          ),
          span: true,
        ),
      GCell(equalRow(areaFields, columns: 2, gap: 10, align: CrossAxisAlignment.end), span: true),
      GCell(
        ProjField(
          icon: Lu.wallet,
          label: 'السعر الثابت',
          child: projInput(
            value: priceText,
            dir: TextDirection.ltr,
            onChanged: (v) => setState(() {
              priceText = v;
              unitError = '';
            }),
          ),
        ),
        span: true,
      ),
      if (n.rooms)
        GCell(
          equalRow(
            <Widget>[
              ProjField(
                icon: Lu.bedDouble,
                label: 'الغرف',
                twoRows: true,
                child: projInput(
                  value: editableNumber(draft.rooms),
                  dir: TextDirection.ltr,
                  onChanged: (v) => patch(<String, Object?>{'rooms': optionalNum(v)}),
                ),
              ),
              ProjField(
                icon: Lu.sofa,
                label: 'الصالات',
                twoRows: true,
                child: projInput(
                  value: editableNumber(draft.halls),
                  dir: TextDirection.ltr,
                  onChanged: (v) => patch(<String, Object?>{'halls': optionalNum(v)}),
                ),
              ),
              ProjField(
                icon: Lu.bath,
                label: 'دورات المياه',
                twoRows: true,
                child: projInput(
                  value: editableNumber(draft.baths),
                  dir: TextDirection.ltr,
                  onChanged: (v) => patch(<String, Object?>{'baths': optionalNum(v)}),
                ),
              ),
            ],
            columns: 3,
            gap: 10,
          ),
          span: true,
        ),
      GCell(
        ProjField(
          icon: Lu.fileCheck,
          label: 'وصف الوحدة',
          textarea: true,
          count: '${draft.description.length}/500',
          child: projTextarea(
            value: draft.description,
            maxLength: 500,
            onChanged: (v) {
              patch(<String, Object?>{'description': v.length > 500 ? v.substring(0, 500) : v});
              setState(() => uAi = '');
            },
          ),
        ),
        span: true,
      ),
      GCell(
        AppButton(
          label: uAi.isNotEmpty ? 'تم تنسيق وصف الوحدة' : 'تنسيق وصف الوحدة ب AI',
          tone: ButtonTone.tonal,
          icon: Lu.sparkles,
          onTap: draft.description.trim().isEmpty
              ? null
              : () {
                  final text = tidyCopy(draft.description, CopyKind.unit);
                  setState(() => uAi = text);
                  patch(<String, Object?>{'description': text});
                },
        ),
        span: true,
      ),
      GCell(
        PhotoDrop(current: <String>[draft.image], onFiles: (v) => patch(<String, Object?>{'image': v.isNotEmpty ? v[0] : typeImage(kind)})),
        span: true,
      ),
      // the licence of THIS unit — ready projects only
      if (widget.ready)
        GCell(
          UnitLicenceField(
            key: ValueKey<String>('licence-${draft.id}'),
            value: draft.license ?? '',
            verified: draft.licenseVerified == true,
            missing: widget.showErrors || unitError.isNotEmpty,
            onChanged: (v) => patch(<String, Object?>{'license': v, 'licenseVerified': false}),
            onVerify: () => patch(<String, Object?>{'licenseVerified': licenceFormatOk(draft.license ?? '')}),
          ),
          span: true,
        ),
      if (unitError.isNotEmpty) GCell(FormError(unitError), span: true),
      GCell(AppButton(label: 'حفظ الوحدة', onTap: _save), span: true),
    ];
    return Container(
      padding: const EdgeInsets.fromLTRB(10, 4, 10, 12),
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: AppColors.mistLine)),
      ),
      child: formGrid(cells, colGap: 12, rowGap: 10),
    );
  }
}
