// Editor of a single listing (sale or rent): every field of the add form —
// including the photos — in a compact layout, saved with
// «حفظ وتحديث العرض» (brief E01).
//
// * Fields follow the property type exactly like the add form
//   (`specsForType`), so no saved detail is dropped by an edit.
// * Photos: thumbnails, add from the gallery, delete, choose the cover
//   (PhotoManager). They are saved only with the button; going back without
//   saving keeps the original photos and fields.
// * Numbers accept Arabic digits; a price must be a number and never
//   negative (QA EDIT-02). A zero price is still accepted, as in the add
//   form — that rule waits for a product decision (QA LIST-02).
// * Saving checks again that the signed-in account owns the listing
//   (QA ACCESS-02). The licence, the advertiser and the boosts are kept.
// * Saving keeps the listing's state: a paused listing stays paused —
//   publishing it again is its own action in «عروضي» (QA F07).
// * The floor: version 1.1 saved the first and second floors as 'first' /
//   'second'; the editor shows them as the floor numbers 1 and 2 (never as
//   the ground floor), and a save that does not touch the floor keeps the
//   stored value exactly (QA F06).

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_my_listings.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/widgets/service_form.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/add_kit.dart';

/// The listing editor.
class ListingEditor extends StatefulWidget {
  const ListingEditor({super.key, required this.listing});

  final Listing listing;

  @override
  State<ListingEditor> createState() => _ListingEditorState();
}

class _ListingEditorState extends State<ListingEditor> {
  Listing get listing => widget.listing;

  late final TextEditingController _title = TextEditingController(text: listing.title);
  late String purpose = listing.purpose;
  late String city = listing.city;
  late String hood = listing.neighborhood;
  late PType type = listing.propertyType;
  late String landUse = listing.landUse ?? 'residential';
  late bool nego = listing.negotiable ?? true;
  late bool deed = listing.hasDeed ?? true;
  late bool bank = listing.bankOk ?? true;
  late String occupancy = listing.occupancy ?? 'families';

  /// The floor as the editor shows it (see the note on floors above).
  late final ({String level, num? number}) _floorShown = switch (listing.floorLevel) {
    'first' => (level: 'number', number: 1),
    'second' => (level: 'number', number: 2),
    'ground' || 'annex' || 'number' => (level: listing.floorLevel!, number: listing.floorNumber),
    _ => (level: 'ground', number: null),
  };
  late String floorLevel = _floorShown.level;
  late final TextEditingController _floorNumber = TextEditingController(text: editableNumber(_floorShown.number));
  late final TextEditingController _floors = TextEditingController(text: editableNumber(listing.floors));
  late final TextEditingController _income = TextEditingController(text: editableNumber(listing.annualIncome));
  late final TextEditingController _age = TextEditingController(text: editableNumber(listing.ageYears));
  late final TextEditingController _land = TextEditingController(text: editableNumber(listing.landArea));
  late final TextEditingController _built = TextEditingController(
    text: editableNumber(listing.builtArea ?? (specsForType(listing.propertyType).built ? listing.area : null)),
  );
  late final TextEditingController _price = TextEditingController(text: editableNumber(listing.price));
  late final TextEditingController _rooms = TextEditingController(text: editableNumber(listing.rooms));
  late final TextEditingController _halls = TextEditingController(text: editableNumber(listing.halls));
  late final TextEditingController _baths = TextEditingController(text: editableNumber(listing.baths));
  late final TextEditingController _maps = TextEditingController(text: listing.mapsLink ?? '');
  late final TextEditingController _desc = TextEditingController(text: listing.description);

  /// Photos being edited (the original list is untouched until saving).
  late List<String> photos = List<String>.of(listing.images);
  String error = '';
  bool _dirty = false;

  List<TextEditingController> get _all => <TextEditingController>[
    _title,
    _floorNumber,
    _floors,
    _income,
    _age,
    _land,
    _built,
    _price,
    _rooms,
    _halls,
    _baths,
    _maps,
    _desc,
  ];

  @override
  void initState() {
    super.initState();
    for (final c in _all) {
      c.addListener(_changed);
    }
  }

  @override
  void dispose() {
    for (final c in _all) {
      c.dispose();
    }
    super.dispose();
  }

  void _changed() {
    if (!_dirty || error.isNotEmpty) {
      setState(() {
        _dirty = true;
        error = '';
      });
    }
  }

  void _set(VoidCallback f) => setState(() {
    f();
    _dirty = true;
    error = '';
  });

  Widget _num(TextEditingController c, {bool decimal = false, bool signed = false, String? placeholder}) {
    return FieldBox(
      controller: c,
      placeholder: placeholder,
      style: kSelText,
      height: 48,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: kSelDeco,
      dir: TextDirection.ltr,
      keyboardType: TextInputType.numberWithOptions(decimal: decimal, signed: signed),
      // digits only (Arabic digits converted); the price keeps a leading
      // minus so saving can refuse it with a message (EDIT-02)
      inputFormatters: <AsciiDigitsFormatter>[AsciiDigitsFormatter(allowDecimal: decimal, allowMinus: signed)],
    );
  }

  Widget _flag(String label, bool on, VoidCallback onTap, String icon) => AddFlag(on: on, onToggle: onTap, label: label, icon: icon);

  /// First problem of the form, or null.
  String? _problem() {
    if (_title.text.trim().isEmpty) return 'اكتب عنوان العرض';
    final p = parseUserNumber(_price.text);
    if (p == null) return 'أدخل السعر بالأرقام';
    if (p < 0) return 'السعر لا يكون سالبًا';
    if (_desc.text.trim().isEmpty) return 'اكتب وصف العرض';
    if (photos.isEmpty) return 'أضف صورة واحدة على الأقل';
    return null;
  }

  bool _sending = false;

  /// With a server: the edit is its (PATCH /listings/{id}); new photos are uploaded first, the ones already there
  /// keep their ids. A changed description goes back to the wording, so its review opens.
  Future<void> _saveOnServer(Map<String, Object?> changes) async {
    final known = <String, int>{
      for (var i = 0; i < listing.images.length && i < (listing.photoIds?.length ?? 0); i++) listing.images[i]: listing.photoIds![i],
    };
    setState(() {
      _sending = true;
      error = '';
    });
    final epoch = store.serverEpoch;
    try {
      final ids = await ServerMyListings.upload(photos, known: known, epoch: epoch);
      final saved = await ServerMyListings.update(listing.id, listing.merged(changes), mediaIds: ids, epoch: epoch);
      store.keepServer(<Listing>[saved], epoch: epoch, detailed: true);
      if (!mounted || epoch != store.serverEpoch) return;
      setState(() {
        _sending = false;
        _dirty = false;
      });
      unawaited(Nav.go(context, saved.status == 'indexing' ? '/account/listings/${listing.id}/review' : '/account/listings'));
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          _sending = false;
          error = e.forUser;
        });
      }
    } on AccountChanged {
      if (mounted) setState(() => _sending = false); // nothing more went out under the new account
    }
  }

  void _save() {
    if (_sending) return;
    if (!store.canEditListing(listing.id)) {
      setState(() => error = 'لا يمكنك تعديل هذا العرض.');
      return;
    }
    final problem = _problem();
    if (problem != null) {
      setState(() => error = problem);
      return;
    }
    final s = specsForType(type);
    final rent = purpose == 'rent';
    final land = s.land ? optionalNum(_land.text) : null;
    final built = s.built ? optionalNum(_built.text) : null;
    Object num(TextEditingController c, bool on) => on ? (optionalNum(c.text) ?? kUndefined) : kUndefined;
    // the floor was not touched: the stored value is kept as it is (QA F06)
    final floorKept = floorLevel == _floorShown.level && _floorNumber.text.trim() == editableNumber(_floorShown.number);
    final changes = <String, Object?>{
      'title': _title.text.trim(),
      'purpose': purpose,
      'city': city,
      'neighborhood': hood,
      'propertyType': type,
      'price': parseUserNumber(_price.text)!,
      'negotiable': nego,
      'images': photos,
      'area': built ?? land ?? listing.area ?? kUndefined,
      'landArea': land ?? kUndefined,
      'builtArea': built ?? kUndefined,
      'landUse': s.landUse ? landUse : kUndefined,
      'floors': num(_floors, s.floors),
      'annualIncome': num(_income, s.income),
      'ageYears': num(_age, s.age),
      'rooms': num(_rooms, s.rooms),
      'halls': num(_halls, s.rooms),
      'baths': num(_baths, s.rooms),
      'hasDeed': deed,
      'bankOk': !rent && deed ? bank : kUndefined,
      'occupancy': s.occupancy && rent ? occupancy : kUndefined,
      'floorLevel': !s.floorLevel
          ? kUndefined
          : floorKept
          ? (listing.floorLevel ?? kUndefined)
          : floorLevel,
      'floorNumber': !s.floorLevel
          ? kUndefined
          : floorKept
          ? (listing.floorNumber ?? kUndefined)
          : floorLevel == 'number'
          ? (optionalNum(_floorNumber.text) ?? 1)
          : kUndefined,
      'mapsLink': _maps.text.trim().isNotEmpty ? _maps.text.trim() : kUndefined,
      'description': _desc.text.trim(),
      // no 'active' here: saving never re-publishes a paused listing (QA F07)
    };
    if (Api.on && ServerListings.isServer(listing.id)) {
      unawaited(_saveOnServer(changes));
      return;
    }
    store.patchListing(listing.id, changes);
    setState(() => _dirty = false);
    Nav.go(context, '/account/listings');
  }

  /// Leaves the editor; unsaved changes are dropped only after a confirmation.
  Future<void> _leave() async {
    if (_dirty) {
      final drop = await showAppDialog(
        context,
        icon: Lu.triangleAlert,
        title: 'تجاهل التعديلات؟',
        message: 'لم تُحفظ التعديلات. تبقى الصور والبيانات الأصلية كما هي.',
        confirmLabel: 'تجاهل',
        cancelLabel: 'متابعة التعديل',
      );
      if (!drop || !mounted) return;
      setState(() => _dirty = false);
    }
    await Nav.go(context, '/account/listings');
  }

  @override
  Widget build(BuildContext context) {
    final s = specsForType(type);
    final rent = purpose == 'rent';
    Widget grid(List<Widget> items, int columns) => Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: <Widget>[
        for (var i = 0; i < columns; i++) ...<Widget>[
          if (i > 0) const SizedBox(width: 8),
          Expanded(child: i < items.length ? items[i] : const SizedBox.shrink()),
        ],
      ],
    );
    final items = <Widget>[
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: BackLink(onTap: _leave),
      ),
      Tx('تعديل العرض', style: ts(18, w: 700, lh: 28, c: AppColors.ink)),
      SvcField(
        label: 'الصور',
        child: PhotoManager(photos: photos, onChanged: (next) => _set(() => photos = next)),
      ),
      SvcField(
        label: 'العنوان',
        child: selInput(controller: _title),
      ),
      grid(<Widget>[
        SvcField(
          label: 'الغرض',
          child: selSelect(
            value: purpose,
            options: const <Opt>[Opt('sale', 'بيع'), Opt('rent', 'إيجار سنوي')],
            onChanged: (v) => _set(() => purpose = v),
          ),
        ),
        SvcField(
          label: 'نوع العقار',
          child: selSelect(
            value: type,
            options: <Opt>[for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
            onChanged: (v) => _set(() => type = v),
          ),
        ),
      ], 2),
      grid(<Widget>[
        SvcField(
          label: 'المدينة',
          child: selSelect(
            value: city,
            options: optsOf(kCities.keys),
            onChanged: (v) => _set(() {
              city = v;
              hood = kCities[v]![0];
            }),
          ),
        ),
        SvcField(
          label: 'الحي',
          child: selSelect(value: hood, options: optsOf(kCities[city] ?? const <String>[]), onChanged: (v) => _set(() => hood = v)),
        ),
      ], 2),
      SvcField(
        label: 'رابط الموقع',
        child: selInput(controller: _maps, dir: TextDirection.ltr, keyboard: TextInputType.url, placeholder: 'https://maps.google.com/...'),
      ),
      grid(<Widget>[
        _flag('بصك', deed, () => _set(() => deed = !deed), Lu.fileCheck),
        if (!rent && deed) _flag('يقبل البنك', bank, () => _set(() => bank = !bank), Lu.landmark),
      ], 2),
      if (rent && s.occupancy)
        SvcField(
          label: 'نوع السكن',
          child: selSelect(
            value: occupancy,
            options: <Opt>[for (final e in kOccupancyLabel.entries) Opt(e.key, e.value)],
            onChanged: (v) => _set(() => occupancy = v),
          ),
        ),
      if (s.floorLevel)
        grid(<Widget>[
          SvcField(
            label: 'الطابق',
            child: selSelect(
              value: floorLevel,
              options: const <Opt>[Opt('ground', 'الأرضي'), Opt('annex', 'ملحق'), Opt('number', 'رقم')],
              onChanged: (v) => _set(() => floorLevel = v),
            ),
          ),
          if (floorLevel == 'number') SvcField(label: 'رقم الدور', child: _num(_floorNumber)),
        ], 2),
      if (s.floors || s.income)
        grid(<Widget>[
          if (s.floors) SvcField(label: 'عدد الطوابق', child: _num(_floors)),
          if (s.income) SvcField(label: 'الدخل السنوي', child: _num(_income)),
        ], 2),
      if (s.land || s.landUse || s.built)
        grid(<Widget>[
          if (s.land) SvcField(label: 'مساحة الأرض', child: _num(_land, decimal: true)),
          if (s.landUse)
            SvcField(
              label: 'نوع الأرض',
              child: selSelect(
                value: landUse,
                options: <Opt>[for (final e in kLandUseLabel.entries) Opt(e.key, e.value)],
                onChanged: (v) => _set(() => landUse = v),
              ),
            )
          else if (s.built)
            SvcField(label: 'مساحة البناء', child: _num(_built, decimal: true)),
        ], 2),
      if (s.age)
        SvcField(
          label: 'عمر العقار',
          child: _num(_age, placeholder: 'سنة'),
        ),
      if (s.rooms)
        grid(<Widget>[
          SvcField(label: 'الغرف', child: _num(_rooms)),
          SvcField(label: 'الصالات', child: _num(_halls)),
          SvcField(label: 'دورات المياه', child: _num(_baths)),
        ], 3),
      grid(<Widget>[
        SvcField(label: 'السعر', child: _num(_price, decimal: true, signed: true)),
        _flag('قابل للتفاوض', nego, () => _set(() => nego = !nego), Lu.handshake),
      ], 2),
      SvcField(
        label: 'الوصف',
        child: FieldBox(
          controller: _desc,
          style: ts(15, lh: 21.5, c: AppColors.ink),
          height: 112,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: kSelDeco,
          multiline: true,
        ),
      ),
      if (error.isNotEmpty) FormError(error),
      AppButton(label: _sending ? 'جارٍ الحفظ…' : 'حفظ وتحديث العرض', onTap: _sending ? null : _save),
      AppButton(label: 'إلغاء', tone: ButtonTone.ghost, onTap: _leave),
    ];
    return PopScope(
      canPop: !_dirty,
      onPopInvokedWithResult: (didPop, result) {
        if (!didPop) unawaited(_leave());
      },
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < items.length; i++) ...<Widget>[
              // 12 px between the blocks
              if (i > 1) const SizedBox(height: 12),
              items[i],
            ],
          ],
        ),
      ),
    );
  }
}
