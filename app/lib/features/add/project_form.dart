// The developer's project flow of `/add` (مشروع): details form → AI review
// of the project text and of each unit's text → publish.
//
// Licences (brief L01): a ready project («جاهز») asks for the advertising
// licence number under EACH unit, each with its own verification; every
// unit must be verified before publishing. An off-plan project («على
// الخارطة») hides those fields and does not check them; numbers typed
// before switching stay in the form (draft) but are not published with an
// off-plan project. There is no project-wide number copied onto the units.
//
// Unit prices (QA F04): each price is kept as typed and checked before
// publishing — a number ≥ 0 (Arabic digits are read); an invalid or negative
// price stops publishing with a message and is never turned into 0. An empty
// price is still 0, as before (a zero price waits for a product decision).
//
// With a server (ServerMyProjects): each unit model carries its count; «تحقق» asks REGA (as a listing's
// licence); «متابعة» sends the photos, the project and its models — each once however often tried — then each
// ready model's licence, and opens the wording review (project_review_screen.dart), which publishes. A send
// that stopped midway is completed by the next «متابعة», with what he changed meanwhile. The phone's simulated
// wording is not offered with a server.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_my_listings.dart';
import 'package:zon/data/server/server_my_projects.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/add_kit.dart';

/// The project flow for a developer.
class ProjectForm extends StatefulWidget {
  const ProjectForm({super.key, required this.name, required this.city, required this.onBack});

  /// Developer name shown on the published project.
  final String name;

  /// The developer's city (pre-selects the city and the first neighbourhood).
  final String city;

  /// Leaves the flow.
  final VoidCallback onBack;

  @override
  State<ProjectForm> createState() => _ProjectFormState();
}

class _ProjectFormState extends State<ProjectForm> {
  // project details
  String title = '';
  late String c = widget.city.isNotEmpty ? widget.city : 'الرياض';
  late String hood = firstHood(widget.city);
  PType ptype = 'apartment';
  String delivery = 'ready';
  String landUse = 'residential';
  String desc = '';
  String mapsLink = '';
  List<UnitModel> units = <UnitModel>[emptyUnit()];

  /// The price of each unit as typed, by unit id (QA F04).
  final Map<String, String> priceText = <String, String>{};

  String _priceOf(UnitModel u) => priceText[u.id] ?? (u.price != 0 ? editableNumber(u.price) : '');

  // ------------------------------------------------------------- with a server

  bool get _server => Api.on;

  /// Each model's count of units as typed, by unit id (one when untouched).
  final Map<String, String> countText = <String, String>{};
  int? _countOf(UnitModel u) {
    final t = toAsciiDigits(countText[u.id] ?? '1').trim();
    final n = int.tryParse(t);
    return n == null || n < 0 || n > 5000 ? null : n;
  }

  /// What a send that stopped midway already did, so the next «متابعة» completes it: the project, each model's
  /// id, the photos uploaded, the licences given.
  int? _projectId;
  final Map<String, int> _unitIds = <String, int>{};
  final Map<String, int> _uploaded = <String, int>{};
  final Map<String, String> _licensed = <String, String>{};
  final OperationKey _projectOp = OperationKey('prj');
  final Map<String, OperationKey> _unitOps = <String, OperationKey>{};
  bool _sending = false;
  String? _sendError;

  /// Errors are shown after the first publish attempt.
  /// Set by the first «متابعة»: from then on the problems are shown.
  bool showErrors = false;

  /// Ready (جاهز) projects carry a licence per unit; land is always ready.
  bool get ready => ptype == 'land' || delivery == 'ready';
  List<String> cover = const <String>[];

  // AI review
  bool reviewing = false;

  /// AI wording of the project text and of each unit's text.
  String pAi = '';
  List<String> uAi = <String>[];

  /// The block being edited in the review: 'p' (project) or a unit id.
  String? editKey;
  Verdict pOk = Verdict.pending;
  List<Verdict> uOk = <Verdict>[];

  /// Replaces fields of unit [i] (see `UnitModel.copyWith`).
  void patch(int i, Map<String, Object?> next) => setState(() {
    units = <UnitModel>[for (var k = 0; k < units.length; k++) k == i ? units[k].copyWith(next) : units[k]];
  });

  /// Saves the project with the chosen texts and opens it.
  void publishProject(String projectText, List<String> unitTexts) {
    final id = 'p-${DateTime.now().millisecondsSinceEpoch}';
    final sold = units.where((u) => u.sold).length;
    store.saveListing(
      Listing(
        id: id,
        kind: 'project',
        title: title.isNotEmpty ? title : 'مشروع جديد',
        purpose: 'sale',
        city: c,
        neighborhood: hood,
        propertyType: ptype,
        source: 'developer',
        price: 0,
        rooms: units.isNotEmpty ? units[0].rooms : null,
        baths: units.isNotEmpty ? units[0].baths : null,
        area: units.isNotEmpty ? units[0].area : null,
        images: cover.isNotEmpty ? cover : <String>[typeImage(ptype)],
        description: projectText,
        advertiser: widget.name,
        advertiserRole: 'developer',
        // no project-wide licence: each unit of a ready project has its own
        license: '',
        mapsLink: mapsLink.trim().isNotEmpty ? mapsLink.trim() : null,
        available: (units.length - sold).toDouble(),
        sold: sold.toDouble(),
        delivery: ptype == 'land' ? 'ready' : delivery,
        landUse: ptype == 'land' ? landUse : null,
        units: <UnitModel>[
          for (var i = 0; i < units.length; i++)
            units[i].copyWith(<String, Object?>{
              'price': parsePrice(_priceOf(units[i])).value ?? units[i].price,
              'description': i < unitTexts.length ? unitTexts[i] : units[i].description,
              // an off-plan project is published without the numbers typed
              // while it was "ready" (they stayed in the draft only)
              if (!ready) 'license': null,
              if (!ready) 'licenseVerified': null,
            }),
        ],
      ),
    );
    Nav.go(context, '/projects/$id');
  }

  String _unitSeed(UnitModel u) => u.description.isNotEmpty ? u.description : (u.name.isNotEmpty ? u.name : 'وحدة سكنية');

  /// Units of a ready project without a verified licence (1-based names).
  List<String> _unitsMissingLicence() => <String>[
    if (ready)
      for (var i = 0; i < units.length; i++)
        if (units[i].licenseVerified != true || !licenceFormatOk(units[i].license ?? '')) units[i].name.isNotEmpty ? units[i].name : 'وحدة ${i + 1}',
  ];

  /// What still stops publishing (null when nothing): title, description
  /// and — for a ready project — a verified licence for EVERY unit (not only
  /// the first). Read live, so the message follows the fixes.
  String? _problem() {
    final missing = _unitsMissingLicence();
    return (title.trim().isEmpty ? 'اكتب عنوان المشروع' : null) ??
        (desc.trim().isEmpty ? 'اكتب وصف المشروع' : null) ??
        _priceProblem() ??
        _countProblem() ??
        (missing.isNotEmpty ? 'أكمل رقم الترخيص الإعلاني وتحقق منه: ${missing.join('، ')}' : null);
  }

  /// The first price error among the units, with the units it applies to,
  /// e.g. "السعر لا يكون سالبًا: وحدة 2" (null when every price is valid).
  String? _priceProblem() {
    String? error;
    final names = <String>[];
    for (var i = 0; i < units.length; i++) {
      final e = parsePrice(_priceOf(units[i])).error;
      if (e == null || (error != null && e != error)) continue;
      error = e;
      names.add(units[i].name.isNotEmpty ? units[i].name : 'وحدة ${i + 1}');
    }
    return error == null ? null : '$error: ${names.join('، ')}';
  }

  /// With a server, a count that is not a whole number from 0 to 5000 stops it (never read as 0).
  String? _countProblem() {
    if (!_server) return null;
    final names = <String>[
      for (var i = 0; i < units.length; i++)
        if (_countOf(units[i]) == null) units[i].name.isNotEmpty ? units[i].name : 'وحدة ${i + 1}',
    ];
    return names.isEmpty ? null : 'عدد الوحدات رقم صحيح من 0 إلى 5000: ${names.join('، ')}';
  }

  /// Validates the form and opens the AI review.
  void _submit() {
    if (_problem() != null) {
      setState(() => showErrors = true);
      return;
    }
    if (_server) {
      unawaited(_send());
      return;
    }
    setState(() {
      pAi = pAi.isNotEmpty ? pAi : polishCopy(desc, CopyKind.project);
      uAi = <String>[
        for (var i = 0; i < units.length; i++) (i < uAi.length && uAi[i].isNotEmpty) ? uAi[i] : polishCopy(_unitSeed(units[i]), CopyKind.unit),
      ];
      pOk = Verdict.pending;
      uOk = <Verdict>[for (final _ in units) Verdict.pending];
      editKey = null;
      reviewing = true;
    });
  }

  /// «متابعة» with a server: the photos, the project, its models, the ready models' licences; then the review.
  Future<void> _send() async {
    final epoch = store.serverEpoch;
    setState(() {
      _sending = true;
      _sendError = null;
    });
    try {
      final photos = await ServerMyListings.upload(cover, known: _uploaded, epoch: epoch);
      var total = 0;
      for (final u in units) {
        total += _countOf(u)!;
      }
      final body = ServerMyProjects.form(
        title: title,
        city: c,
        neighborhood: hood,
        propertyType: ptype,
        delivery: ptype == 'land' ? 'ready' : (delivery == 'soon' ? 'offplan' : delivery),
        landUse: ptype == 'land' ? landUse : null,
        description: desc,
        mapsLink: mapsLink,
        totalUnits: total < 1 ? 1 : total,
        mediaIds: photos,
      );
      final sent = _projectId;
      final int project;
      if (sent == null) {
        project = _projectId = await ServerMyProjects.create(body, operation: _projectOp.forBody(body), epoch: epoch);
        _projectOp.done();
      } else {
        project = sent;
        await ServerMyProjects.update(project, body, epoch: epoch); // a send before stopped midway: his changes since
      }

      // the models he took out after they were sent
      final kept = <String>{for (final u in units) u.id};
      for (final gone in _unitIds.keys.where((k) => !kept.contains(k)).toList()) {
        await ServerMyProjects.removeUnit(project, _unitIds[gone]!, epoch: epoch);
        _unitIds.remove(gone);
        _licensed.remove(gone);
      }
      for (final u in units) {
        final type = u.propertyType ?? ptype;
        final image = await ServerMyListings.upload(<String>[if (u.image.isNotEmpty) u.image], known: _uploaded, epoch: epoch);
        final unitBody = ServerMyProjects.unitForm(u, type: type, price: parsePrice(_priceOf(u)).value, count: _countOf(u)!, mediaIds: image);
        final known = _unitIds[u.id];
        if (known == null) {
          final op = _unitOps.putIfAbsent(u.id, () => OperationKey('unit'));
          _unitIds[u.id] = await ServerMyProjects.addUnit(project, unitBody, operation: op.forBody(unitBody), epoch: epoch);
          op.done();
        } else {
          await ServerMyProjects.updateUnit(project, known, unitBody, epoch: epoch);
        }
        final number = toAsciiDigits(u.license ?? '').trim();
        if (ready && _licensed[u.id] != number) {
          await ServerMyProjects.licence(project, _unitIds[u.id]!, number, epoch: epoch);
          _licensed[u.id] = number;
        }
      }
      if (!mounted || epoch != store.serverEpoch) return;
      setState(() => _sending = false);
      unawaited(Nav.go(context, '/account/projects/$project/review'));
    } on ApiError catch (e) {
      if (mounted && epoch == store.serverEpoch) {
        setState(() {
          _sending = false;
          _sendError = e.forUser;
        });
      }
    } on AccountChanged {
      if (mounted) setState(() => _sending = false);
    }
  }

  /// «تحقق» with a server: the number asked of REGA (valid and current), as a listing's licence.
  Future<void> _verifyOnServer(int i) async {
    final u = units[i];
    final number = toAsciiDigits(u.license ?? '').trim();
    final epoch = store.serverEpoch;
    setState(() => _sendError = null);
    try {
      await ServerMyListings.checkLicence(number);
      if (!mounted || epoch != store.serverEpoch) return;
      final at = units.indexWhere((x) => x.id == u.id);
      if (at >= 0 && toAsciiDigits(units[at].license ?? '').trim() == number) patch(at, <String, Object?>{'licenseVerified': true});
    } on ApiError catch (e) {
      if (mounted && epoch == store.serverEpoch) setState(() => _sendError = '${u.name.isNotEmpty ? u.name : 'وحدة ${i + 1}'}: ${e.forUser}');
    }
  }

  @override
  Widget build(BuildContext context) => reviewing ? _review() : _form();

  // ------------------------------------------------------------- review

  Widget _review() {
    final okN = uOk.where((v) => v == Verdict.ok).length;
    final noN = uOk.where((v) => v == Verdict.no).length;
    final waitN = uOk.length - okN - noN;
    Verdict unitVerdict(int i) => i < uOk.length ? uOk[i] : Verdict.pending;
    void markAll(Verdict v) => setState(() {
      pOk = v;
      uOk = <Verdict>[for (final _ in units) v];
    });
    void setUnit(int i, Verdict v) => setState(() {
      uOk = <Verdict>[for (var k = 0; k < units.length; k++) k == i ? v : unitVerdict(k)];
    });
    void publishNow() {
      // A rejected block is published with the developer's own text.
      final projectText = pOk == Verdict.no ? desc : (pAi.isNotEmpty ? pAi : desc);
      final unitTexts = <String>[
        for (var i = 0; i < units.length; i++)
          unitVerdict(i) == Verdict.no ? units[i].description : ((i < uAi.length && uAi[i].isNotEmpty) ? uAi[i] : units[i].description),
      ];
      publishProject(projectText, unitTexts);
    }

    Widget pill(String text, {bool on = false}) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: on ? AppColors.forest : AppColors.white,
        borderRadius: BorderRadius.circular(999),
        border: on ? null : Border.all(color: AppColors.line),
      ),
      child: Tx(text, style: ts(12, w: 700, lh: 18, c: on ? AppColors.white : AppColors.stone)),
    );
    final summary = Wrap(
      spacing: 6,
      runSpacing: 6,
      children: <Widget>[
        pill('المشروع ${PolishUnit.labelOf(pOk)}', on: pOk == Verdict.ok),
        pill('وحدات موافق $okN'),
        pill('مرفوض $noN'),
        pill('بانتظار $waitN'),
      ],
    );
    final cards = <Widget>[
      PolishUnit(
        label: 'وصف المشروع',
        spark: true,
        ai: true,
        verdict: pOk,
        editing: editKey == 'p',
        text: pOk == Verdict.no ? desc : pAi,
        editValue: pAi,
        onEditChanged: (v) => setState(() => pAi = v),
        onOk: () => setState(() => pOk = Verdict.ok),
        onNo: () => setState(() => pOk = Verdict.no),
        onEdit: () => setState(() => editKey = editKey == 'p' ? null : 'p'),
      ),
      for (var i = 0; i < units.length; i++)
        PolishUnit(
          key: ValueKey<String>(units[i].id),
          label: 'وحدة ${units[i].name.isNotEmpty ? units[i].name : '${i + 1}'} — ${kTypeLabel[units[i].propertyType ?? ptype] ?? ''}',
          spark: false,
          ai: false,
          verdict: unitVerdict(i),
          editing: editKey == units[i].id,
          text: unitVerdict(i) == Verdict.no ? units[i].description : (i < uAi.length ? uAi[i] : ''),
          editValue: i < uAi.length ? uAi[i] : '',
          onEditChanged: (v) => setState(() => uAi = <String>[for (var k = 0; k < uAi.length; k++) k == i ? v : uAi[k]]),
          onOk: () => setUnit(i, Verdict.ok),
          onNo: () => setUnit(i, Verdict.no),
          onEdit: () => setState(() => editKey = editKey == units[i].id ? null : units[i].id),
        ),
    ];
    return PolishStage(
      onBack: () => setState(() => reviewing = false),
      title: 'أوصاف المشروع والوحدات',
      lead: 'لكل وحدة: موافق أو رفض. المرفوض يُنشر بوصفك الأصلي.',
      summary: summary,
      actions: <Widget>[
        AppButton(label: 'موافق على الكل', onTap: () => markAll(Verdict.ok)),
        AppButton(label: 'نشر حسب الاختيار', tone: ButtonTone.secondary, onTap: publishNow),
      ],
      children: <Widget>[
        for (var i = 0; i < cards.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 12), cards[i]],
      ],
    );
  }

  // ------------------------------------------------------------- form

  Widget _form() {
    final first = <GCell>[
      GCell(PhotoDrop(current: cover, onFiles: (v) => setState(() => cover = v)), span: true),
      GCell(projHint('سعر المشروع في الرئيسية يظهر ابتداءً من أقل وحدة — داخل المشروع لا تُكتب ابتداءً من.'), span: true),
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
            options: <Opt>[for (final t in kProjectTypes) Opt(t, kTypeLabel[t]!)],
            onChanged: (v) => setState(() {
              ptype = v;
              units = <UnitModel>[emptyUnit(v)];
            }),
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
              options: const <Opt>[Opt('ready', 'جاهز'), Opt('offplan', 'على الخارطة')],
              onChanged: (v) => setState(() => delivery = v),
            ),
          ),
        ),
      GCell(
        ProjField(
          icon: Lu.link2,
          label: 'رابط موقع المشروع',
          hint: 'يظهر في صفحة المشروع فقط. الوحدات لا تحتاج موقعاً منفصلاً.',
          child: projInput(
            value: mapsLink,
            placeholder: 'https://maps.google.com/...',
            dir: TextDirection.ltr,
            keyboard: TextInputType.url,
            onChanged: (v) => setState(() => mapsLink = v),
          ),
        ),
        span: true,
      ),
      GCell(
        ProjField(
          icon: Lu.fileCheck,
          label: 'وصف المشروع',
          hint: 'تعريف بسيط: اكتب ما يميّز المشروع مثل قربه من الخدمات. لا تكتب عدد الوحدات أو المساحات.',
          textarea: true,
          count: '${desc.length}/500',
          child: projTextarea(
            value: desc,
            maxLength: 500,
            placeholder: 'قريب من الخدمات والمدارس والمسجد…',
            onChanged: (v) => setState(() {
              desc = v.length > 500 ? v.substring(0, 500) : v;
              pAi = '';
            }),
          ),
        ),
        span: true,
      ),
      if (!_server)
        GCell(
          AppButton(
            label: pAi.isNotEmpty ? 'تم تنسيق وصف المشروع' : 'تنسيق الوصف ب AI',
            tone: ButtonTone.tonal,
            icon: Lu.sparkles,
            onTap: desc.trim().isEmpty ? null : () => setState(() => pAi = polishCopy(desc, CopyKind.project)),
          ),
          span: true,
        ),
    ];

    final second = <GCell>[
      GCell(projCardHead('الوحدات — ${units.length}'), span: true),
      for (var i = 0; i < units.length; i++) GCell(KeyedSubtree(key: ValueKey<String>(units[i].id), child: _unitCard(i)), span: true),
      GCell(
        AppButton(
          label: 'إضافة وحدة',
          tone: ButtonTone.secondary,
          icon: Lu.plus,
          onTap: () => setState(() => units = <UnitModel>[...units, emptyUnit(ptype)]),
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
            title: 'تفاصيل المشروع',
            sub: 'الموقع للمشروع فقط — الوحدات بلا موقع منفصل',
            image: cover.isNotEmpty && cover[0].isNotEmpty ? cover[0] : typeImage(ptype),
            onBack: widget.onBack,
          ),
          ProjCard(child: formGrid(first)),
          ProjCard(child: formGrid(second)),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                if (showErrors ? _problem() : null case final String problem) ...<Widget>[FormError(problem), const SizedBox(height: 10)],
                if (_sendError case final String failed) ...<Widget>[FormError(failed), const SizedBox(height: 10)],
                AppButton(label: _sending ? 'جارٍ الإرسال…' : 'متابعة', size: ButtonSize.large, onTap: _sending ? null : _submit),
              ],
            ),
          ),
          const SizedBox(height: 120),
        ],
      ),
    );
  }

  /// One unit's card inside the form.
  Widget _unitCard(int i) {
    final u = units[i];
    final n = specsForUnit(u.propertyType ?? ptype);
    Widget unitNum(String label, String icon, num? value, String key) => ProjField(
      icon: icon,
      label: label,
      twoRows: true,
      child: projInput(value: editableNumber(value), dir: TextDirection.ltr, onChanged: (v) => patch(i, <String, Object?>{key: optionalNum(v)})),
    );
    final cells = <GCell>[
      GCell(
        ProjField(
          icon: Lu.tag,
          label: 'اسم الوحدة',
          child: projInput(value: u.name, onChanged: (v) => patch(i, <String, Object?>{'name': v})),
        ),
      ),
      GCell(
        ProjField(
          icon: Lu.home,
          label: 'نوع الوحدة',
          child: projSelect(
            value: u.propertyType ?? ptype,
            options: <Opt>[for (final t in kProjectUnitTypes) Opt(t, kTypeLabel[t]!)],
            onChanged: (next) => setState(() {
              // A new type resets the type-specific fields but keeps the
              // unit's identity, name, price and description.
              units = <UnitModel>[
                for (var k = 0; k < units.length; k++)
                  k == i
                      ? emptyUnit(next).copyWith(<String, Object?>{
                          'id': units[k].id,
                          'name': units[k].name,
                          'price': units[k].price,
                          'description': units[k].description,
                          'license': units[k].license,
                          'licenseVerified': units[k].licenseVerified,
                        })
                      : units[k],
              ];
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
              value: u.landUse ?? 'residential',
              options: <Opt>[for (final e in kLandUseLabel.entries) Opt(e.key, e.value)],
              onChanged: (v) => patch(i, <String, Object?>{'landUse': v}),
            ),
          ),
          span: true,
        ),
      GCell(
        equalRow(
          <Widget>[
            if (n.land)
              ProjField(
                icon: Lu.maximize2,
                label: 'مساحة الأرض م²',
                child: projInput(
                  value: editableNumber(u.landArea),
                  dir: TextDirection.ltr,
                  onChanged: (v) => patch(i, <String, Object?>{'landArea': numOr0(v), 'area': numOr0(v)}),
                ),
              ),
            if (n.built)
              ProjField(
                icon: Lu.maximize2,
                label: 'مساحة البناء م²',
                child: projInput(
                  value: editableNumber(u.builtArea),
                  dir: TextDirection.ltr,
                  onChanged: (v) => patch(i, <String, Object?>{'builtArea': numOr0(v), 'area': numOr0(v)}),
                ),
              ),
          ],
          columns: 2,
          gap: 10,
          align: CrossAxisAlignment.end,
        ),
        span: true,
      ),
      GCell(
        ProjField(
          icon: Lu.wallet,
          label: 'السعر الثابت',
          child: projInput(
            value: _priceOf(u),
            dir: TextDirection.ltr,
            // kept as typed; checked by «متابعة» (never a silent 0)
            onChanged: (v) => setState(() => priceText[u.id] = v),
          ),
        ),
        span: true,
      ),
      if (_server)
        GCell(
          ProjField(
            icon: Lu.layers,
            label: 'عدد الوحدات من هذا النموذج',
            child: projInput(
              value: countText[u.id] ?? '1',
              dir: TextDirection.ltr,
              keyboard: TextInputType.number,
              onChanged: (v) => setState(() => countText[u.id] = v),
            ),
          ),
          span: true,
        ),
      if (n.rooms)
        GCell(
          equalRow(
            <Widget>[
              unitNum('الغرف', Lu.bedDouble, u.rooms, 'rooms'),
              unitNum('الصالات', Lu.sofa, u.halls, 'halls'),
              unitNum('دورات المياه', Lu.bath, u.baths, 'baths'),
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
          count: '${u.description.length}/500',
          child: projTextarea(
            value: u.description,
            maxLength: 500,
            placeholder: 'أدخل وصف الوحدة هنا..',
            onChanged: (v) => patch(i, <String, Object?>{'description': v.length > 500 ? v.substring(0, 500) : v}),
          ),
        ),
        span: true,
      ),
      if (!_server)
        GCell(
          AppButton(
            label: (i < uAi.length && uAi[i].isNotEmpty) ? 'تم تنسيق وصف الوحدة' : 'تنسيق وصف الوحدة ب AI',
            tone: ButtonTone.tonal,
            icon: Lu.sparkles,
            onTap: u.description.trim().isEmpty
                ? null
                : () => setState(() {
                    final next = <String>[for (var k = 0; k < units.length; k++) k < uAi.length ? uAi[k] : ''];
                    next[i] = polishCopy(u.description, CopyKind.unit);
                    uAi = next;
                  }),
          ),
          span: true,
        ),
      GCell(
        PhotoDrop(
          current: <String>[u.image],
          onFiles: (v) => patch(i, <String, Object?>{'image': v.isNotEmpty ? v[0] : typeImage(u.propertyType ?? ptype)}),
        ),
        span: true,
      ),
      // the licence of THIS unit — ready projects only
      if (ready)
        GCell(
          UnitLicenceField(
            key: ValueKey<String>('licence-${u.id}'),
            value: u.license ?? '',
            verified: u.licenseVerified == true,
            missing: showErrors,
            onChanged: (v) => patch(i, <String, Object?>{'license': v, 'licenseVerified': false}),
            onVerify: _server
                ? () => unawaited(_verifyOnServer(i))
                : () => patch(i, <String, Object?>{'licenseVerified': licenceFormatOk(u.license ?? '')}),
          ),
          span: true,
        ),
    ];
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: kAddBoxLine),
      ),
      child: formGrid(cells),
    );
  }
}
