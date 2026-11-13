// «المخزون» with a server (ServerStock): his private stock — never indexed, never shown to anyone.
//
// * Each property as the server keeps it: no price, area or rooms made up when he did not give them.
// * Added once however often tried (its photo uploaded first), edited, removed after asking.
// * «نشر كإعلان»: what a listing needs that the stock lacks is asked here — REGA's licence (checked with
//   «تحقق»), a price, a description of ten letters — and the server publishes it through the listings' own path,
//   once: published again it answers that it is. Then its wording waits for his decision (the review page).
// * His plan's stock limit, or any refusal, is said as the server says it, and nothing changes.
// * Another account signed in: its own stock, nothing of the previous one.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/utils/input.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_desk.dart';
import 'package:zon/data/server/server_my_listings.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/inventory_view.dart' show StockPhoto;
import 'package:zon/features/account/services/widgets/desk.dart';
import 'package:zon/features/account/services/widgets/service_form.dart';

class ServerStockView extends StatefulWidget {
  const ServerStockView({super.key});

  @override
  State<ServerStockView> createState() => _ServerStockViewState();
}

class _ServerStockViewState extends State<ServerStockView> {
  List<StockItem> _items = <StockItem>[];
  bool _loading = true;
  String? _error;
  String? _busy;
  bool _adding = false;
  String? _editing;
  String? _publishing;
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    store.addListener(_onAccount);
    unawaited(_load());
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
      _items = <StockItem>[];
      _loading = true;
      _error = null;
      _busy = null;
      _adding = false;
      _editing = null;
      _publishing = null;
    });
    unawaited(_load());
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _load() async {
    final epoch = _epoch;
    try {
      final rows = await ServerStock.list();
      if (_current(epoch)) setState(() => _items = rows);
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } finally {
      if (_current(epoch)) setState(() => _loading = false);
    }
  }

  Future<void> _remove(StockItem item) async {
    final ok = await showAppDialog(
      context,
      icon: Lu.trash2,
      title: 'حذف من المخزون؟',
      message: '«${item.title}» يُحذف من مخزونك نهائياً.${item.listingId != null ? ' إعلانه المنشور لا يتأثر.' : ''}',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (!ok || !mounted) return;
    final epoch = _epoch;
    setState(() {
      _busy = item.id;
      _error = null;
    });
    try {
      await ServerStock.remove(item.id, epoch: epoch);
      if (_current(epoch)) {
        setState(
          () => _items = <StockItem>[
            for (final x in _items)
              if (x.id != item.id) x,
          ],
        );
      }
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } on AccountChanged {
      // the next account's stock is its own
    } finally {
      if (_current(epoch)) setState(() => _busy = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sale = _items.where((i) => i.purpose != 'rent').length;
    final children = <Widget>[
      DeskCard(
        showScan: false,
        children: <Widget>[
          DeskJewel(value: '${_items.length}', small: 'عقار', title: 'دفتر المخزون'),
          deskHint('خاص بك: لا يُفهرس ولا يظهر لأحد. بيع $sale · إيجار ${_items.length - sale}'),
        ],
      ),
      DeskToolbar(
        title: 'مخزوني',
        open: _adding,
        addLabel: 'إضافة عقار',
        onToggle: () => setState(() {
          _adding = !_adding;
          _editing = null;
        }),
      ),
      if (_adding)
        StockServerForm(
          onSaved: (item) => setState(() {
            _items = <StockItem>[item, ..._items];
            _adding = false;
          }),
          onCancel: () => setState(() => _adding = false),
        ),
      if (_error case final String e) FormError(e),
      if (_loading)
        const LoadingBox()
      else if (_items.isEmpty && !_adding)
        Tx(
          'المخزون فارغ. أضف عقاراً غير منشور لترتيب المتابعة.',
          style: ts(15, lh: 22.5, c: AppColors.muted),
          align: TextAlign.center,
        ),
      for (final item in _items)
        if (_editing == item.id)
          StockServerForm(
            key: ValueKey<String>('edit-${item.id}'),
            initial: item,
            onSaved: (row) => setState(() {
              _items = <StockItem>[for (final x in _items) x.id == row.id ? row : x];
              _editing = null;
            }),
            onCancel: () => setState(() => _editing = null),
          )
        else ...<Widget>[
          _StockCard(
            key: ValueKey<String>(item.id),
            item: item,
            busy: _busy == item.id,
            onEdit: () => setState(() {
              _editing = item.id;
              _adding = false;
            }),
            onRemove: () => unawaited(_remove(item)),
            onPublish: () => setState(() => _publishing = _publishing == item.id ? null : item.id),
          ),
          if (_publishing == item.id)
            _PublishCard(
              key: ValueKey<String>('publish-${item.id}'),
              item: item,
              onPublished: (listing) => unawaited(Nav.go(context, '/account/listings/$listing/review')),
              onAlready: () {
                setState(() => _publishing = null);
                unawaited(_load());
              },
            ),
        ],
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
}

/// One property of his stock: what he gave of it, and what can be done.
class _StockCard extends StatelessWidget {
  const _StockCard({super.key, required this.item, required this.busy, required this.onEdit, required this.onRemove, required this.onPublish});

  final StockItem item;
  final bool busy;
  final VoidCallback onEdit;
  final VoidCallback onRemove;
  final VoidCallback onPublish;

  @override
  Widget build(BuildContext context) {
    final area = item.area;
    final facts = <String>[
      kTypeLabel[item.type] ?? item.type,
      item.purpose == 'rent' ? 'إيجار' : 'بيع',
      '${item.city} · ${item.neighborhood}',
      if (area != null) '${loc(area)} م²',
      if (item.bedrooms != null) '${item.bedrooms} غرف',
    ].join(' · ');
    final cover = item.cover;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(20),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: SizedBox(
                  width: 72,
                  height: 72,
                  child: cover != null
                      ? AppImage(cover)
                      : const ColoredBox(
                          color: AppColors.mist,
                          child: Center(child: LucideIcon(Lu.warehouse, size: 22, color: AppColors.forest)),
                        ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Tx(item.title, style: ts(16, w: 700, lh: 22.5, c: AppColors.deep)),
                    const SizedBox(height: 4),
                    Tx(facts, style: ts(13, w: 600, lh: 19.5, c: AppColors.muted)),
                    const SizedBox(height: 4),
                    if (item.price case final num p)
                      SarText(sar(p), style: ts(15, w: 700, lh: 22.5, c: AppColors.forest), iconW: 15, iconH: 16, gap: 5)
                    else
                      Tx('بلا سعر', style: ts(14, w: 600, lh: 21, c: AppColors.muted)),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          if (item.listingId != null)
            AppButton(label: 'منشور — إعلاناتي', tone: ButtonTone.tonal, icon: Lu.check, onTap: () => Nav.go(context, '/account/listings'))
          else
            Row(
              children: <Widget>[
                Expanded(
                  child: AppButton(label: 'نشر كإعلان', onTap: busy ? null : onPublish),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: AppButton(label: 'تعديل', tone: ButtonTone.tonal, onTap: busy ? null : onEdit),
                ),
              ],
            ),
          const SizedBox(height: 8),
          AppButton(label: busy ? 'لحظة…' : 'حذف', tone: ButtonTone.ghost, icon: Lu.trash2, onTap: busy ? null : onRemove),
        ],
      ),
    );
  }
}

/// Add or edit a property of his stock: what he knows of it, nothing more.
class StockServerForm extends StatefulWidget {
  const StockServerForm({super.key, this.initial, required this.onSaved, required this.onCancel});

  final StockItem? initial;
  final ValueChanged<StockItem> onSaved;
  final VoidCallback onCancel;

  @override
  State<StockServerForm> createState() => _StockServerFormState();
}

class _StockServerFormState extends State<StockServerForm> {
  late final StockItem? init = widget.initial;
  late String purpose = init?.purpose ?? 'sale';
  late String type = init?.type ?? 'villa';
  late String city = init?.city ?? 'الرياض';
  late String hood = init?.neighborhood ?? kCities['الرياض']![0];
  late final TextEditingController _title = TextEditingController(text: init?.title ?? '');
  late final TextEditingController _price = TextEditingController(text: init?.price == null ? '' : plainNumber(init!.price!));
  late final TextEditingController _area = TextEditingController(text: init?.area == null ? '' : plainNumber(init!.area!));
  late final TextEditingController _rooms = TextEditingController(text: init?.bedrooms?.toString() ?? '');
  late final TextEditingController _desc = TextEditingController(text: init?.description ?? '');
  List<String> photos = const <String>[];
  int? _photoId;
  bool _busy = false;
  String? _error;
  final OperationKey _op = OperationKey('stock');

  @override
  void dispose() {
    for (final c in <TextEditingController>[_title, _price, _area, _rooms, _desc]) {
      c.dispose();
    }
    super.dispose();
  }

  num? _num(TextEditingController c) => num.tryParse(toAsciiDigits(c.text).replaceAll(RegExp(r'[^\d.]'), ''));

  /// What the server keeps: nothing for what he did not write. The title, when left empty, says what it is.
  Map<String, Object?> _body() {
    final area = _num(_area);
    return <String, Object?>{
      'purpose': purpose,
      'property_type': type,
      'title': _title.text.trim().isEmpty ? '${kTypeLabel[type]} — $hood' : _title.text.trim(),
      'city': city,
      'neighborhood': hood,
      'price': _num(_price),
      'area_land': type == 'land' ? area : null,
      'area_built': type == 'land' ? null : area,
      'bedrooms': type == 'land' ? null : _num(_rooms)?.toInt(),
      'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
    };
  }

  Future<void> _save() async {
    final epoch = store.serverEpoch;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final photo = photos.isEmpty ? null : photos.first;
      if (photo != null && _photoId == null) _photoId = await ServerStock.upload(photo, epoch: epoch); // once, kept for a retry
      final body = <String, Object?>{
        ..._body(),
        if (_photoId != null) 'media_ids': <int>[_photoId!],
      };
      final StockItem saved;
      if (init != null) {
        saved = await ServerStock.update(init!.id, body, epoch: epoch);
      } else {
        saved = await ServerStock.create(body, operation: _op.forBody(body), epoch: epoch);
        _op.done();
      }
      if (mounted && epoch == store.serverEpoch) widget.onSaved(saved);
    } on ApiError catch (e) {
      if (mounted) setState(() => _error = e.forUser);
    } on AccountChanged {
      // nothing more under the next account
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    Widget field(String label, TextEditingController c, {bool number = false, String? hint}) => SvcField(
      label: label,
      child: selInput(controller: c, placeholder: hint, keyboard: number ? TextInputType.number : null),
    );
    final items = <Widget>[
      Tx(init != null ? 'تعديل عقار المخزون' : 'عقار للمخزون', style: ts(16, w: 700, lh: 24, c: AppColors.deep)),
      SvcField(
        label: 'بيع أو إيجار',
        child: selSelect(
          value: purpose,
          options: const <Opt>[Opt('sale', 'بيع'), Opt('rent', 'إيجار')],
          onChanged: (v) => setState(() => purpose = v),
        ),
      ),
      SvcField(
        label: 'نوع العقار',
        child: selSelect(
          value: type,
          options: <Opt>[for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
          onChanged: (v) => setState(() => type = v),
        ),
      ),
      SvcField(
        label: 'المدينة',
        child: selSelect(
          value: city,
          options: optsOf(kCities.keys),
          onChanged: (v) => setState(() {
            city = v;
            hood = kCities[v]![0];
          }),
        ),
      ),
      SvcField(
        label: 'الحي',
        child: selSelect(value: hood, options: optsOf(kCities[city] ?? const <String>[]), onChanged: (v) => setState(() => hood = v)),
      ),
      field('العنوان (اختياري)', _title, hint: '${kTypeLabel[type]} — $hood'),
      field('السعر (ريال، اختياري)', _price, number: true),
      field(type == 'land' ? 'مساحة الأرض م² (اختياري)' : 'المساحة المبنية م² (اختياري)', _area, number: true),
      if (type != 'land') field('الغرف (اختياري)', _rooms, number: true),
      if (init == null) ...<Widget>[
        Tx('صورة واحدة (اختيارية)', style: ts(15, w: 500, lh: 21.5, c: AppColors.deep)),
        StockPhoto(
          value: photos,
          onChange: (v) => setState(() {
            photos = v.take(1).toList();
            _photoId = null;
          }),
        ),
      ],
      SvcField(
        label: 'الوصف (اختياري)',
        child: FieldBox(
          controller: _desc,
          style: ts(15, lh: 21.5, c: AppColors.deep),
          height: 96,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: kSelDeco,
          multiline: true,
        ),
      ),
      if (_error case final String e) FormError(e),
      Row(
        children: <Widget>[
          Expanded(
            child: AppButton(label: 'إلغاء', tone: ButtonTone.tonal, onTap: _busy ? null : widget.onCancel),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: AppButton(label: _busy ? 'جارٍ الحفظ…' : 'حفظ', onTap: _busy ? null : () => unawaited(_save())),
          ),
        ],
      ),
    ];
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: AppColors.surface, borderRadius: BorderRadius.circular(22), boxShadow: AppShadows.card),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < items.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 12), items[i]],
        ],
      ),
    );
  }
}

/// «نشر كإعلان»: what a listing needs that the stock lacks, then the server publishes it.
class _PublishCard extends StatefulWidget {
  const _PublishCard({super.key, required this.item, required this.onPublished, required this.onAlready});

  final StockItem item;
  final ValueChanged<String> onPublished;
  final VoidCallback onAlready;

  @override
  State<_PublishCard> createState() => _PublishCardState();
}

class _PublishCardState extends State<_PublishCard> {
  final TextEditingController _licence = TextEditingController();
  late final TextEditingController _price = TextEditingController();
  late final TextEditingController _desc = TextEditingController(text: widget.item.description ?? '');
  DateTime? _checkedUntil;
  String? _checkedFor;
  bool _checking = false;
  bool _busy = false;
  String? _error;

  StockItem get item => widget.item;
  bool get _needsPrice => item.price == null;
  bool get _needsDesc => (item.description ?? '').trim().length < 10;
  String get _number => toAsciiDigits(_licence.text).trim();

  @override
  void initState() {
    super.initState();
    for (final c in <TextEditingController>[_licence, _price, _desc]) {
      c.addListener(() => setState(() {}));
    }
  }

  @override
  void dispose() {
    for (final c in <TextEditingController>[_licence, _price, _desc]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _check() async {
    setState(() {
      _checking = true;
      _error = null;
    });
    final number = _number;
    try {
      final until = await ServerMyListings.checkLicence(number);
      if (mounted) {
        setState(() {
          _checkedUntil = until;
          _checkedFor = number;
        });
      }
    } on ApiError catch (e) {
      if (mounted) setState(() => _error = e.forUser);
    } finally {
      if (mounted) setState(() => _checking = false);
    }
  }

  Future<void> _publish() async {
    final epoch = store.serverEpoch;
    setState(() {
      _busy = true;
      _error = null;
    });
    final price = num.tryParse(toAsciiDigits(_price.text).replaceAll(RegExp(r'[^\d.]'), ''));
    try {
      final (listing, _) = await ServerStock.publish(item.id, <String, Object?>{
        'licence_number': _number,
        if (_needsPrice) 'price': price,
        if (_needsDesc) 'description': _desc.text.trim(),
      }, epoch: epoch);
      if (mounted && epoch == store.serverEpoch) widget.onPublished(listing);
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.code == 'ALREADY_ACTIVE') {
        widget.onAlready(); // a first try whose answer was lost: it is published
        return;
      }
      setState(() => _error = e.forUser);
    } on AccountChanged {
      // nothing more under the next account
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final checked = _checkedUntil != null && _checkedFor == _number;
    final ready = checked && (!_needsPrice || _price.text.trim().isNotEmpty) && (!_needsDesc || _desc.text.trim().length >= 10) && !_busy;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: AppColors.surface, borderRadius: BorderRadius.circular(22), boxShadow: AppShadows.card),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx('نشر «${item.title}» كإعلان', style: ts(16, w: 700, lh: 24, c: AppColors.deep)),
          const SizedBox(height: 6),
          Tx('يُنشر عبر مسار الإعلانات نفسه: ترخيص الهيئة، ثم صياغة الوصف وقرارك.', style: ts(13, w: 500, lh: 19.5, c: AppColors.muted)),
          const SizedBox(height: 12),
          SvcField(
            label: 'رقم الترخيص الإعلاني',
            child: Row(
              children: <Widget>[
                Expanded(
                  child: selInput(controller: _licence, keyboard: TextInputType.number, dir: TextDirection.ltr),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  width: 96,
                  child: AppButton(
                    label: _checking ? 'لحظة…' : 'تحقق',
                    tone: ButtonTone.tonal,
                    onTap: _checking || !RegExp(r'^\d{6,12}$').hasMatch(_number) ? null : () => unawaited(_check()),
                  ),
                ),
              ],
            ),
          ),
          if (checked) ...<Widget>[
            const SizedBox(height: 6),
            Tx('تم التحقق لدى الهيئة — ساري حتى ${arDate(_checkedUntil!)}', style: ts(13, w: 600, lh: 19.5, c: AppColors.success)),
          ],
          if (_needsPrice) ...<Widget>[
            const SizedBox(height: 12),
            SvcField(
              label: 'السعر (ريال)',
              child: selInput(controller: _price, keyboard: TextInputType.number),
            ),
          ],
          if (_needsDesc) ...<Widget>[
            const SizedBox(height: 12),
            SvcField(
              label: 'الوصف (10 أحرف فأكثر)',
              child: FieldBox(
                controller: _desc,
                style: ts(15, lh: 21.5, c: AppColors.deep),
                height: 96,
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: kSelDeco,
                multiline: true,
              ),
            ),
          ],
          if (_error case final String e) ...<Widget>[const SizedBox(height: 10), FormError(e)],
          const SizedBox(height: 12),
          AppButton(label: _busy ? 'جارٍ النشر…' : 'انشر الإعلان', onTap: ready ? () => unawaited(_publish()) : null),
        ],
      ),
    );
  }
}
