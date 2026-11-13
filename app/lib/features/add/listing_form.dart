// The listing flow of `/add` (إعلان عقاري), one screen per step:
//
//   owner : intro → deed → contract → form → polish
//   broker: licence → owner info → form → polish
//
// * intro    — the owner plan path (0.5 % after the sale, contract + listing)
// * deed     — deed and owner identity; sends the brokerage contract
// * contract — waits for the contract approval (REGA / Nafath), then the
//              licence fee and the licence number (data/billing/owner_cases)
// * licence  — the broker's advertising licence number, verified
// * owner    — the broker's private notes about the owner (optional)
// * form     — the listing details (fields depend on the property type)
// * polish   — the AI wording review, then publish
//
// An owner's listing is published only after the contract is approved and
// the licence issued; publishing it activates the owner plan (P04).
//
// With a server, a broker's listing is the server's (ServerMyListings):
// * «تحقق» asks REGA for the licence (POST /listings/licence-check) — «تم التحقق» only when REGA says so;
// * «متابعة» uploads the photos, then publishes the listing once however often it is sent (Idempotency-Key),
//   and saves the owner's card (POST /property-owners) when he wrote one; the wording is then the server's AI's,
//   reviewed on its own page (listing_review_screen.dart) — this phone's simulated wording is not shown.
// An owner's listing, with its contract and its fee, comes with the plans' slice.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_my_listings.dart';
import 'package:zon/data/server/server_owners.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/add_kit.dart';
import 'package:zon/shared/widgets/billing_flow.dart';

/// Steps of the listing flow (see the file comment).
enum _Step { licence, intro, deed, contract, owner, form, polish }

/// The listing flow for an owner or a broker.
class ListingForm extends StatefulWidget {
  const ListingForm({super.key, required this.name, required this.city, required this.role, required this.onBack});

  /// Advertiser name shown on the published listing.
  final String name;

  /// The user's city (pre-selects the city and the first neighbourhood).
  final String city;

  /// "owner" or "broker".
  final String role;

  /// Leaves the flow (back to the "what do you want to add" choice).
  final VoidCallback onBack;

  @override
  State<ListingForm> createState() => _ListingFormState();
}

class _ListingFormState extends State<ListingForm> {
  String get role => widget.role;
  bool get isBroker => role == 'broker';

  late _Step step;

  /// The owner's case of this property (contract, fee, licence, listing).
  OwnerCase? ownerCase;
  String error = '';

  @override
  void initState() {
    super.initState();
    if (role == 'owner') {
      // Resume a property that is still on its way.
      ownerCase = ownerCases.current();
      final c = ownerCase;
      step = c == null ? _Step.intro : (c.licenceNumber != null ? _Step.form : _Step.contract);
      if (c?.licenceNumber != null) lic = c!.licenceNumber!;
    } else {
      step = isBroker ? _Step.licence : _Step.form;
    }
  }

  // licence and owner identity
  String lic = '';
  bool ok = false;
  String deedKind = 'إلكتروني';
  String ownerKind = 'فرد';
  String deedNo = '';
  String ownerPhone = '';
  String ownerNid = '';
  String ownerDob = '';
  String ownerName = '';
  String ownerNote = '';

  // listing details
  String title = '';
  String purpose = 'sale';
  late String c = widget.city.isNotEmpty ? widget.city : 'الرياض';
  late String hood = firstHood(widget.city);
  PType ptype = 'villa';
  String price = '';
  String landArea = '';
  String builtArea = '';
  String rooms = '';
  String halls = '';
  String baths = '';
  String floors = '';
  String income = '';
  String age = '';
  String desc = '';
  bool nego = true;
  bool deed = true;
  bool bank = true;
  String occupancy = 'families';
  String floorLevel = 'ground';
  String floorNumber = '';
  String landUse = 'residential';
  List<String> photos = const <String>[];
  String mapsLink = '';

  // AI wording
  String aiText = '';
  bool aiReady = false;

  /// With a server: a broker's listing goes to it (see the file comment).
  bool get _server => Api.on && isBroker;
  bool _busy = false;
  final OperationKey _operation = OperationKey('lst');
  final OperationKey _ownerOperation = OperationKey('own');

  /// The photos already uploaded (this phone's reference → the server's id): a second try does not upload them again.
  final Map<String, int> _uploaded = <String, int>{};

  SpecFlags get spec => specsForType(ptype);
  bool get rent => purpose == 'rent';

  void go(_Step next) => setState(() {
    step = next;
    error = '';
  });

  // ------------------------------------------------------------- actions

  /// Saves the listing (and, for a broker, the owner card) and opens it.
  /// An owner publishes only with an approved contract and an issued
  /// licence; publishing then activates the owner plan.
  void publish(String text) {
    final problem = _formProblem();
    if (problem != null) {
      setState(() {
        step = _Step.form;
        error = problem;
      });
      return;
    }
    OwnerCase? oc;
    if (role == 'owner') {
      oc = ownerCase == null ? null : ownerCases.byId(ownerCase!.id);
      if (oc == null || !oc.contractApproved || oc.licenceNumber == null) {
        setState(() {
          step = _Step.contract;
          error = 'أكمل عقد الوساطة ورسوم الترخيص قبل النشر.';
        });
        return;
      }
      lic = oc.licenceNumber!;
    }
    final id = 'u-${DateTime.now().millisecondsSinceEpoch}';
    store.saveListing(_draft(text, id: id));
    if (isBroker && (ownerName.trim().isNotEmpty || ownerPhone.trim().isNotEmpty)) {
      store.saveOwner(
        Owner(
          id: 'o-$id',
          name: ownerName.trim().isNotEmpty ? ownerName.trim() : 'مالك',
          phone: ownerPhone.trim(),
          listingTitle: title.isNotEmpty ? title : 'عرض جديد',
          listingId: id,
          notes: ownerNote.trim().isNotEmpty ? <Note>[Note(id: 'n0', kind: 'متابعة', text: ownerNote.trim())] : const <Note>[],
        ),
      );
    }
    if (oc != null) ownerCases.markPublished(oc.id, id);
    unawaited(Nav.go(context, '/listings/$id'));
  }

  /// The listing of this form, with [text] as its description.
  Listing _draft(String text, {String id = ''}) {
    final s = spec;
    final land = s.land ? optionalNum(landArea) : null;
    final built = s.built ? optionalNum(builtArea) : null;
    return Listing(
        id: id,
        kind: 'unit',
        title: title.isNotEmpty ? title : 'عرض جديد',
        purpose: purpose,
        city: c,
        neighborhood: hood,
        propertyType: ptype,
        source: role,
        price: numOr0(price),
        rooms: s.rooms && rooms.isNotEmpty ? numOr0(rooms) : null,
        baths: s.rooms && baths.isNotEmpty ? numOr0(baths) : null,
        halls: s.rooms && halls.isNotEmpty ? numOr0(halls) : null,
        area: built ?? land ?? 0,
        landArea: s.land ? land : null,
        builtArea: s.built ? built : null,
        images: photos.isNotEmpty ? photos : <String>[typeImage(ptype)],
        description: text,
        advertiser: widget.name,
        advertiserRole: role,
        license: lic,
        floors: s.floors && floors.isNotEmpty ? numOr0(floors) : null,
        annualIncome: s.income && income.isNotEmpty ? numOr0(income) : null,
        negotiable: nego,
        hasDeed: deed,
        bankOk: !rent && deed ? bank : null,
        occupancy: s.occupancy && rent ? occupancy : null,
        floorLevel: s.floorLevel ? floorLevel : null,
        floorNumber: s.floorLevel && floorLevel == 'number' ? (optionalNum(floorNumber) ?? 1) : null,
        ageYears: s.age && age.isNotEmpty ? numOr0(age) : null,
        landUse: s.landUse ? landUse : null,
        mapsLink: mapsLink.trim().isNotEmpty ? mapsLink.trim() : null,
      );
  }

  /// The owner sent the deed and identity: the brokerage contract is
  /// submitted and waits for approval (nothing is active yet).
  void submitContract() {
    final problem =
        (deedNo.trim().isEmpty ? 'اكتب رقم الصك أو رقم العقار' : null) ??
        (normalizeSaudiMobile(ownerPhone) == null ? 'رقم جوال المالك غير صحيح — مثال: 05XXXXXXXX' : null) ??
        (ownerKind == 'فرد' && digitsOnly(ownerNid).length != 10 ? 'رقم الهوية 10 أرقام' : null) ??
        (ownerNid.trim().isEmpty ? 'اكتب رقم الهوية أو السجل' : null) ??
        (ownerDob.isEmpty ? 'اختر تاريخ ميلاد المالك' : null);
    if (problem != null) {
      setState(() => error = problem);
      return;
    }
    setState(() {
      ownerCase = ownerCases.submitContract(deedNo: deedNo);
      step = _Step.contract;
      error = '';
    });
  }

  /// Required: title, price and description; the price is a number and
  /// never negative (QA EDIT-02). A zero price is not refused yet — it
  /// waits for a product decision (QA LIST-02).
  String? _formProblem() {
    final p = parseUserNumber(price);
    return (title.trim().isEmpty ? 'اكتب عنوان العرض' : null) ??
        (p == null ? 'أدخل السعر بالأرقام' : null) ??
        (p != null && p < 0 ? 'السعر لا يكون سالبًا' : null) ??
        (desc.trim().isEmpty ? 'اكتب وصف العرض' : null);
  }

  /// «تحقق» with a server: REGA's answer, not the number's length.
  Future<void> _checkLicence() async {
    setState(() {
      _busy = true;
      error = '';
    });
    try {
      await ServerMyListings.checkLicence(lic);
      if (mounted) setState(() => ok = true);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.forUser);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// The owner's card with a server needs his mobile (the server keeps one card per mobile).
  void _ownerDone() {
    final wrote = ownerName.trim().isNotEmpty || ownerPhone.trim().isNotEmpty || ownerNote.trim().isNotEmpty;
    if (_server && wrote && normalizeSaudiMobile(ownerPhone) == null) {
      setState(() => error = 'رقم جوال المالك غير صحيح — مثال: 05XXXXXXXX');
      return;
    }
    go(_Step.form);
  }

  /// With a server: the photos, then the listing, then the owner's card; then its wording's review.
  Future<void> _publishOnServer() async {
    final problem = _formProblem();
    if (problem != null) {
      setState(() => error = problem);
      return;
    }
    setState(() {
      _busy = true;
      error = '';
    });
    final epoch = store.serverEpoch;
    try {
      final ids = await ServerMyListings.upload(photos, known: _uploaded);
      final draft = _draft(desc);
      final operation = _operation.forBody(<String, Object?>{...ServerMyListings.form(draft), 'media_ids': ids, 'licence': lic});
      final made = await ServerMyListings.create(draft, mediaIds: ids, licence: lic, operation: operation);
      _operation.done();
      if (!mounted || epoch != store.serverEpoch) return;
      store.keepServer(<Listing>[made], epoch: epoch, detailed: true);
      final card = await _saveOwnerCard(made);
      if (!mounted) return;
      if (card != null) await showAppNotice(context, icon: Lu.circleAlert, title: 'نُشر العرض، ولم تُحفظ بطاقة المالك', message: card);
      if (mounted) unawaited(Nav.replace(context, '/account/listings/${made.id}/review'));
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          error = e.forUser;
        });
      }
    }
  }

  /// The owner's card of [listing], when he wrote one; why it was not saved, or null.
  Future<String?> _saveOwnerCard(Listing listing) async {
    final phone = normalizeSaudiMobile(ownerPhone);
    if (phone == null) return null;
    final card = <String, Object?>{
      'name': ownerName.trim().isNotEmpty ? ownerName.trim() : 'مالك',
      'phone': phone,
      'listing_id': int.tryParse(listing.id.substring(1)),
      'listing_title': listing.title,
    };
    try {
      await ServerOwners.create(card, note: ownerNote.trim(), operation: _ownerOperation.forBody(card));
      _ownerOperation.done();
      return null;
    } on ApiError catch (e) {
      return e.forUser;
    }
  }

  /// Validates the details and moves to the AI review.
  void submitForm() {
    if (_server) {
      unawaited(_publishOnServer());
      return;
    }
    final problem = _formProblem();
    if (problem != null) {
      setState(() => error = problem);
      return;
    }
    setState(() {
      error = '';
      aiText = aiText.isNotEmpty ? aiText : polishCopy(desc);
      aiReady = true;
      step = _Step.polish;
    });
  }

  @override
  Widget build(BuildContext context) => switch (step) {
    _Step.licence => _licenceStep(context),
    _Step.intro => OwnerIntro(onContinue: () => go(_Step.deed), onClose: widget.onBack),
    _Step.deed => _deedStep(),
    _Step.contract => _contractStep(),
    _Step.owner => _ownerStep(),
    _Step.form => _formStep(),
    _Step.polish => _polishStep(),
  };

  // ------------------------------------------------------------- licence

  Widget _licenceStep(BuildContext context) {
    final card = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        AddHead(kicker: 'ترخيص الإعلان', title: isBroker ? 'تحقق من الترخيص ثم أكمل العرض' : 'قبل نشر عرضك'),
        const SizedBox(height: 16),
        if (isBroker)
          LicCard(
            plain: true,
            value: lic,
            onChanged: (v) => setState(() {
              lic = digitsOnly(v);
              ok = false;
            }),
            ok: ok,
            onVerify: _server ? (_busy ? () {} : () => unawaited(_checkLicence())) : () => setState(() => ok = lic.length >= 4),
            qrBackground: AppColors.paper,
          )
        else
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(color: kStoneBg, borderRadius: BorderRadius.circular(18)),
            child: Tx('زون تصدر الترخيص بعد إثبات الملكية.', style: ts(14, w: 500, lh: 21, c: AppColors.deep)),
          ),
        const SizedBox(height: 14),
        if (isBroker && error.isNotEmpty) ...<Widget>[FormError(error), const SizedBox(height: 10)],
        if (isBroker)
          AppButton(label: 'متابعة', size: ButtonSize.large, onTap: ok ? () => go(_Step.owner) : null)
        else
          AppButton(label: 'استمرار', size: ButtonSize.large, onTap: () => go(_Step.intro)),
      ],
    );
    return Container(
      color: kSheetIos,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
      child: ConstrainedBox(
        constraints: BoxConstraints(minHeight: addStageMinHeight(context) - 136),
        child: IntrinsicHeight(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: CloseCircle(onTap: widget.onBack),
              ),
              const SizedBox(height: 12),
              // The card sits low on the screen, within thumb reach.
              const Spacer(),
              card,
              const SizedBox(height: 88),
            ],
          ),
        ),
      ),
    );
  }

  // ------------------------------------------------------------- deed

  Widget _deedStep() {
    Widget labeled(String label, Widget control) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx(label, style: ts(14, w: 600, lh: 21, c: AppColors.deep)),
        const SizedBox(height: 6),
        control,
      ],
    );
    Widget input(String value, ValueChanged<String> onChanged, {TextInputType? keyboard}) =>
        projInput(value: value, onChanged: onChanged, dir: TextDirection.ltr, keyboard: keyboard);

    return Container(
      color: kSheetIos,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          BackLink(onTap: () => go(_Step.intro)),
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Tx('معلومات الترخيص', style: ts(22, w: 700, lh: 32, c: AppColors.deep)),
          ),
          ProjCard(
            child: formGrid(<GCell>[
              GCell(
                labeled(
                  'نوع الصك',
                  projSelect(
                    value: deedKind,
                    options: optsOf(const <String>['إلكتروني', 'ورقي', 'سجل عيني']),
                    onChanged: (v) => setState(() => deedKind = v),
                  ),
                ),
              ),
              GCell(
                labeled(
                  'نوع المالك',
                  projSelect(
                    value: ownerKind,
                    options: optsOf(const <String>['فرد', 'شركة', 'ورثة']),
                    onChanged: (v) => setState(() => ownerKind = v),
                  ),
                ),
              ),
              GCell(labeled('رقم الصك أو رقم العقار أو السجل العيني', input(deedNo, (v) => setState(() => deedNo = v))), span: true),
              GCell(labeled('رقم جوال المالك', input(ownerPhone, (v) => setState(() => ownerPhone = v), keyboard: TextInputType.phone))),
              GCell(labeled('رقم هوية المالك', input(ownerNid, (v) => setState(() => ownerNid = v), keyboard: TextInputType.number))),
              GCell(
                labeled(
                  'تاريخ ميلاد المالك',
                  DateField(value: ownerDob, onChanged: (v) => setState(() => ownerDob = v), decoration: kProjBox, style: kProjText, height: 48),
                ),
                span: true,
              ),
            ], rowGap: 14),
          ),
          const SizedBox(height: 16),
          if (error.isNotEmpty) ...<Widget>[FormError(error), const SizedBox(height: 12)],
          AppButton(label: 'إرسال عقد الوساطة', size: ButtonSize.large, onTap: submitContract),
        ],
      ),
    );
  }

  // ------------------------------------------------------------- contract (owner)

  /// Pays the advertising licence fee (simulated) of the approved case.
  Future<void> _payFee(OwnerCase c) async {
    final paid = await showPayDialog(
      context,
      icon: Lu.fileCheck,
      title: 'رسوم الترخيص الإعلاني — ${riyals(Pricing.adLicenceFee)}',
      message: 'رسوم الهيئة العامة للعقار لإصدار رقم ترخيص هذا الإعلان. ليست اشتراكًا.',
      amount: Pricing.adLicenceFee,
      pay: () async => (await ownerCases.payLicenceFee(c.id)).ok,
    );
    if (!mounted) return;
    setState(() {
      ownerCase = ownerCases.byId(c.id);
      if (paid && ownerCase?.licenceNumber != null) lic = ownerCase!.licenceNumber!;
    });
  }

  Widget _contractStep() {
    final c = ownerCase == null ? null : ownerCases.byId(ownerCase!.id);
    if (c == null) return OwnerIntro(onContinue: () => go(_Step.deed), onClose: widget.onBack);
    Widget row(String title, String state, bool done) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        children: <Widget>[
          Container(
            width: 24,
            height: 24,
            decoration: BoxDecoration(color: done ? AppColors.forest : AppColors.mist, shape: BoxShape.circle),
            alignment: Alignment.center,
            child: done ? const LucideIcon(Lu.check, size: 14, strokeWidth: 2.4, color: AppColors.white) : null,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Tx(title, style: ts(15, w: 600, lh: 22, c: AppColors.deep)),
          ),
          Tx(state, style: ts(14, w: 600, lh: 21, c: done ? AppColors.forest : AppColors.muted)),
        ],
      ),
    );
    final String lead;
    final List<Widget> actions;
    switch (c.stage) {
      case 'contract':
        lead =
            'أرسلنا عقد الوساطة. وافق عليه في منصة الهيئة العامة للعقار عبر نفاذ، ثم حدّث الحالة. '
            'السعي ${Pricing.ownerCommissionPercent}٪ بعد بيع هذا العقار — ثابت في العقد.';
        actions = <Widget>[
          AppButton(
            label: 'تحديث حالة العقد',
            tone: ButtonTone.tonal,
            size: ButtonSize.large,
            onTap: () => showAppNotice(
              context,
              icon: Lu.clock,
              title: 'لم تصل الموافقة بعد',
              message: 'تظهر الموافقة هنا بعد تأكيدها من الهيئة. الربط مع منصة الهيئة ونفاذ ينتظر الخادم.',
            ),
          ),
          if (kSimulations) ...<Widget>[
            const SizedBox(height: 10),
            AppButton(
              label: 'محاكاة موافقة العقد (نسخة تجريبية)',
              tone: ButtonTone.secondary,
              size: ButtonSize.large,
              onTap: () => setState(() => ownerCase = ownerCases.approveContract(c.id, reference: 'SIM-${DateTime.now().millisecondsSinceEpoch}')),
            ),
            const SizedBox(height: 6),
            Tx(
              'للتجربة فقط — في الإصدار الفعلي تصل الموافقة من الهيئة.',
              style: ts(12, lh: 18, c: AppColors.muted),
              align: TextAlign.center,
            ),
          ],
        ];
      case 'fee':
      case 'licence':
        lead = 'العقد موثّق. ادفع رسوم الترخيص الإعلاني ليصدر رقم ترخيص الإعلان.';
        actions = <Widget>[AppButton(label: 'ادفع رسوم الترخيص — ${riyals(Pricing.adLicenceFee)}', size: ButtonSize.large, onTap: () => _payFee(c))];
      default:
        lead = 'صدر رقم الترخيص. أكمل تفاصيل العرض وانشره — تتفعّل الباقة بعد النشر.';
        actions = <Widget>[AppButton(label: 'متابعة إلى تفاصيل العرض', size: ButtonSize.large, onTap: () => go(_Step.form))];
    }
    return Container(
      color: kSheetIos,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: CloseCircle(onTap: widget.onBack),
          ),
          const SizedBox(height: 12),
          const AddHead(kicker: 'باقة المالك', title: 'عقد الوساطة والترخيص'),
          const SizedBox(height: 14),
          ProjCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                row('عقد الوساطة', c.contractApproved ? 'موثّق' : 'بانتظار موافقتك', c.contractApproved),
                row('رسوم الترخيص الإعلاني', c.feePaid ? 'مدفوعة' : 'لم تُدفع', c.feePaid),
                row('رقم الترخيص', c.licenceNumber ?? '—', c.licenceNumber != null),
                if (c.licenceSimulated && c.licenceNumber != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Tx('رقم تجريبي من هذه النسخة — يصدر الرقم الفعلي من الهيئة عند الربط.', style: ts(12, lh: 18, c: AppColors.muted)),
                  ),
                row('نشر العرض', 'بعد التفاصيل', false),
                const SizedBox(height: 8),
                Tx(lead, style: ts(14, lh: 22, c: AppColors.deep)),
              ],
            ),
          ),
          const SizedBox(height: 16),
          if (error.isNotEmpty) ...<Widget>[FormError(error), const SizedBox(height: 12)],
          ...actions,
        ],
      ),
    );
  }

  // ------------------------------------------------------------- owner (broker)

  Widget _ownerStep() {
    return Container(
      color: kSheetIos,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: CloseCircle(onTap: () => go(_Step.licence)),
          ),
          const SizedBox(height: 12),
          const AddHead(kicker: 'خصوصية لك — لا تظهر في العرض', title: 'معلومات المالك'),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.white,
              borderRadius: BorderRadius.circular(28),
              border: Border.all(color: AppColors.mistLine),
              boxShadow: AppShadows.card,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                IosField(
                  icon: Lu.user,
                  label: 'اسم المالك',
                  child: iosInput(value: ownerName, onChanged: (v) => setState(() => ownerName = v)),
                ),
                const SizedBox(height: 10),
                IosField(
                  icon: Lu.smartphone,
                  label: 'جوال المالك',
                  child: iosInput(
                    value: ownerPhone,
                    onChanged: (v) => setState(() => ownerPhone = v),
                    dir: TextDirection.ltr,
                    keyboard: TextInputType.phone,
                  ),
                ),
                const SizedBox(height: 10),
                IosField(
                  icon: Lu.fileCheck,
                  label: 'ملاحظة',
                  textarea: true,
                  child: iosTextarea(
                    value: ownerNote,
                    onChanged: (v) => setState(() => ownerNote = v),
                    placeholder: 'مثلاً: يفضّل البيع كاش ويرفض البنك',
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          if (error.isNotEmpty) ...<Widget>[FormError(error), const SizedBox(height: 10)],
          AppButton(label: 'حفظ والمتابعة', size: ButtonSize.large, onTap: _ownerDone),
          const SizedBox(height: 10),
          AppButton(label: 'تخطي', tone: ButtonTone.ghost, size: ButtonSize.large, onTap: () => go(_Step.form)),
        ],
      ),
    );
  }

  // ------------------------------------------------------------- polish

  Widget _polishStep() {
    return PolishStage(
      onBack: () => go(_Step.form),
      title: 'صياغة الوصف',
      lead: 'اقبل صياغة الذكاء أو عدّل الوصف ثم انشر.',
      actions: <Widget>[
        AppButton(label: 'قبول ونشر', onTap: () => publish(aiText.isNotEmpty ? aiText : desc)),
        AppButton(
          label: 'تعديل الوصف',
          tone: ButtonTone.secondary,
          onTap: () => setState(() {
            desc = aiText.isNotEmpty ? aiText : desc;
            step = _Step.form;
          }),
        ),
      ],
      children: <Widget>[
        PolishCol(label: 'ما كتبته أنت', text: desc),
        const SizedBox(height: 10),
        PolishCol(label: 'صياغة الذكاء', text: aiText, ai: true),
      ],
    );
  }

  // ------------------------------------------------------------- details form

  Widget _formStep() {
    final s = spec;
    Widget numField(
      String label,
      String icon,
      String value,
      ValueChanged<String> set, {
      String? placeholder,
      bool compact = false,
      bool signed = false,
    }) => ProjField(
      icon: icon,
      label: label,
      twoRows: compact,
      child: projInput(
        value: value,
        placeholder: placeholder,
        dir: TextDirection.ltr,
        keyboard: TextInputType.number,
        // the price keeps a leading minus so it can be refused with a message
        onChanged: (v) => setState(() => set(signed && toAsciiDigits(v.trim()).startsWith('-') ? '-${digitsOnly(v)}' : digitsOnly(v))),
      ),
    );
    final cells = <GCell>[
      GCell(PhotoDrop(current: photos, onFiles: (v) => setState(() => photos = v)), span: true),
      GCell(
        ProjField(
          icon: Lu.mapPin,
          label: 'العنوان',
          child: projInput(value: title, placeholder: 'أدخل عنوان العقار', onChanged: (v) => setState(() => title = v)),
        ),
        span: true,
      ),
      GCell(
        ProjField(
          icon: Lu.crosshair,
          label: 'الغرض',
          child: projSelect(
            value: purpose,
            options: const <Opt>[Opt('sale', 'بيع'), Opt('rent', 'إيجار سنوي')],
            onChanged: (v) => setState(() => purpose = v),
          ),
        ),
      ),
      GCell(
        ProjField(
          icon: Lu.home,
          label: 'نوع العقار',
          child: projSelect(
            value: ptype,
            options: <Opt>[for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
            onChanged: (v) => setState(() => ptype = v),
          ),
        ),
      ),
      GCell(
        ProjField(
          icon: Lu.building2,
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
          icon: Lu.link2,
          label: 'رابط الموقع',
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
        equalRow(
          <Widget>[
            AddFlag(on: deed, onToggle: () => setState(() => deed = !deed), label: 'بصك', icon: Lu.user),
            if (!rent && deed) AddFlag(on: bank, onToggle: () => setState(() => bank = !bank), label: 'يقبل البنك', icon: Lu.landmark),
          ],
          columns: 2,
          gap: 10,
        ),
        span: true,
      ),
      if (rent && s.occupancy)
        GCell(
          ProjField(
            icon: Lu.home,
            label: 'نوع السكن',
            child: projSelect(
              value: occupancy,
              options: <Opt>[for (final e in kOccupancyLabel.entries) Opt(e.key, e.value)],
              onChanged: (v) => setState(() => occupancy = v),
            ),
          ),
        ),
      if (s.floorLevel)
        GCell(
          FloorPick(
            value: floorLevel,
            number: floorNumber,
            onLevel: (v) => setState(() => floorLevel = v),
            onNumber: (v) => setState(() => floorNumber = v),
          ),
          span: true,
        ),
      if (s.floors) GCell(numField('عدد الطوابق', Lu.building2, floors, (v) => floors = v)),
      if (s.land) GCell(numField('مساحة الأرض', Lu.layers, landArea, (v) => landArea = v, placeholder: '0')),
      if (s.landUse)
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
        ),
      if (s.built) GCell(numField('مساحة البناء', Lu.maximize2, builtArea, (v) => builtArea = v, placeholder: '0')),
      if (s.age) GCell(numField('عمر العقار', Lu.calendar, age, (v) => age = v, placeholder: 'سنة')),
      if (s.income) GCell(numField('الدخل السنوي', Lu.wallet, income, (v) => income = v)),
      if (s.rooms)
        GCell(
          equalRow(
            <Widget>[
              numField('الغرف', Lu.bedDouble, rooms, (v) => rooms = v, placeholder: '0', compact: true),
              numField('الصالات', Lu.sofa, halls, (v) => halls = v, placeholder: '0', compact: true),
              numField('دورات المياه', Lu.bath, baths, (v) => baths = v, placeholder: '0', compact: true),
            ],
            columns: 3,
            gap: 10,
          ),
          span: true,
        ),
      GCell(
        equalRow(
          <Widget>[
            numField('السعر', Lu.fileText, price, (v) => price = v, placeholder: 'ر.س', signed: true),
            AddFlag(on: nego, onToggle: () => setState(() => nego = !nego), label: 'قابل للتفاوض', icon: Lu.handshake),
          ],
          columns: 2,
          gap: 10,
          align: CrossAxisAlignment.end,
        ),
        span: true,
      ),
      GCell(
        ProjField(
          icon: Lu.fileCheck,
          label: 'الوصف',
          textarea: true,
          count: '${desc.length}/500',
          child: projTextarea(
            value: desc,
            maxLength: 500,
            placeholder: 'أضف وصفًا للعقار',
            onChanged: (v) => setState(() {
              desc = v.length > 500 ? v.substring(0, 500) : v;
              aiReady = false;
            }),
          ),
        ),
        span: true,
      ),
      // the wording of this phone is a simulation: with a server it is its AI's, after «متابعة»
      if (!_server)
      GCell(
        AppButton(
          label: aiReady ? 'تم تنسيق الوصف بالذكاء' : 'تنسيق الوصف بالـ AI',
          tone: ButtonTone.tonal,
          icon: Lu.sparkles,
          onTap: desc.trim().isEmpty
              ? null
              : () => setState(() {
                  aiText = polishCopy(desc);
                  aiReady = true;
                }),
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
            title: 'تفاصيل العرض',
            sub: 'حدد مواصفات طلبك بدقة للحصول على أفضل النتائج',
            image: photos.isNotEmpty && photos[0].isNotEmpty ? photos[0] : typeImage(ptype),
            onBack: () => go(isBroker ? _Step.owner : (role == 'owner' ? _Step.contract : _Step.licence)),
          ),
          ProjCard(child: formGrid(cells)),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                if (error.isNotEmpty) ...<Widget>[FormError(error), const SizedBox(height: 10)],
                AppButton(label: _busy ? 'جارٍ النشر…' : 'متابعة', size: ButtonSize.large, onTap: _busy ? null : submitForm),
                if (aiReady && !_server) ...<Widget>[
                  const SizedBox(height: 10),
                  AppButton(label: 'نشر العرض', tone: ButtonTone.secondary, size: ButtonSize.large, onTap: () => publish(desc)),
                ],
              ],
            ),
          ),
          const SizedBox(height: 120),
        ],
      ),
    );
  }
}
