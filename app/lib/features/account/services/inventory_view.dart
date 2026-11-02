// المخزون — listings the broker keeps unpublished; publish one in a tap.

import 'package:flutter/material.dart';

import 'package:image_picker/image_picker.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/widgets/desk.dart';
import 'package:zon/features/account/services/widgets/service_form.dart';
import 'package:zon/shared/widgets/listing_card.dart';

/// المخزون: unpublished listings kept for follow-up.
class InventoryView extends StatefulWidget {
  const InventoryView({super.key});

  @override
  State<InventoryView> createState() => _InventoryViewState();
}

class _InventoryViewState extends State<InventoryView> {
  String city = 'all';
  String hood = 'all';
  String type = 'all';

  /// "all" | "sale" | "rent"
  String purpose = 'all';
  bool adding = false;
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
    final rows = store.inventory();
    final hoods = city == 'all' ? const <String>[] : (kCities[city] ?? const <String>[]);
    final saleN = rows.where((l) => l.purpose != 'rent').length;
    final rentN = rows.where((l) => l.purpose == 'rent').length;
    final q = _q.text.trim();
    final shown = rows.where((l) {
      if (city != 'all' && l.city != city) return false;
      if (hood != 'all' && l.neighborhood != hood) return false;
      if (type != 'all' && l.propertyType != type) return false;
      if (purpose == 'sale' && l.purpose == 'rent') return false;
      if (purpose == 'rent' && l.purpose != 'rent') return false;
      if (q.isNotEmpty && !'${l.title} ${l.neighborhood} ${kTypeLabel[l.propertyType]}'.contains(q)) return false;
      return true;
    }).toList();

    final children = <Widget>[
      DeskCard(
        showScan: false,
        children: <Widget>[
          DeskJewel(value: '${rows.length}', small: 'عروض', title: 'دفتر المخزون'),
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
          // search field
          Container(
            height: 44,
            margin: const EdgeInsets.only(top: 14),
            padding: const EdgeInsets.symmetric(horizontal: 14),
            decoration: BoxDecoration(color: const Color(0x14FFFFFF), borderRadius: BorderRadius.circular(14)),
            child: Row(
              children: <Widget>[
                const LucideIcon(Lu.search, size: 16, color: kDeskMint),
                const SizedBox(width: 8),
                Expanded(
                  child: FieldBox(
                    controller: _q,
                    placeholder: 'ابحث بالاسم…',
                    style: ts(15, w: 600, lh: 22.5, c: AppColors.white),
                    placeholderColor: kDeskSoft,
                    placeholderWeight: 500,
                    height: null,
                    padding: EdgeInsets.zero,
                  ),
                ),
              ],
            ),
          ),
          // action buttons
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Row(
              children: <Widget>[
                Expanded(flex: 100, child: _act('بيع $saleN', purpose == 'sale', () => setState(() => purpose = purpose == 'sale' ? 'all' : 'sale'))),
                const SizedBox(width: 6),
                Expanded(
                  flex: 100,
                  child: _act('إيجار $rentN', purpose == 'rent', () => setState(() => purpose = purpose == 'rent' ? 'all' : 'rent')),
                ),
                const SizedBox(width: 6),
                Expanded(flex: 115, child: _act(adding ? 'إغلاق' : 'إضافة عرض', adding, () => setState(() => adding = !adding))),
              ],
            ),
          ),
        ],
      ),
      if (adding) StockForm(onDone: () => setState(() => adding = false), onCancel: () => setState(() => adding = false)),
      if (shown.isEmpty && !adding)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 64),
          child: Column(
            children: <Widget>[
              Container(
                width: 64,
                height: 64,
                decoration: const BoxDecoration(color: Color(0x0D121214), shape: BoxShape.circle),
                alignment: Alignment.center,
                child: const LucideIcon(Lu.warehouse, size: 24, strokeWidth: 1.6, color: AppColors.ink),
              ),
              const SizedBox(height: 12),
              Tx(
                'المخزون فارغ',
                style: ts(16, w: 600, lh: 24, c: AppColors.deep),
                align: TextAlign.center,
              ),
              const SizedBox(height: 12),
              Tx(
                'أضف عرضاً غير منشور لترتيب المتابعة.',
                style: ts(15, lh: 21.5, c: AppColors.muted),
                align: TextAlign.center,
              ),
            ],
          ),
        ),
      // the list
      Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < shown.length; i++) ...<Widget>[
              if (i > 0) const SizedBox(height: 12),
              Padding(
                key: ValueKey<String>(shown[i].id),
                padding: const EdgeInsets.only(bottom: 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    ListingCard(listing: shown[i]),
                    Tap(
                      onTap: () {
                        store.publishStock(shown[i].id);
                        setState(() {});
                      },
                      child: Container(
                        height: 44,
                        decoration: BoxDecoration(
                          color: AppColors.surface,
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(color: AppColors.line),
                        ),
                        alignment: Alignment.center,
                        child: Tx('نشر العرض', style: ts(15, w: 500, lh: 21.5, c: AppColors.deep)),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
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

  Widget _act(String label, bool on, VoidCallback onTap) {
    return Tap(
      onTap: onTap,
      child: Container(
        height: 44,
        decoration: BoxDecoration(color: on ? AppColors.white : const Color(0x14FFFFFF), borderRadius: BorderRadius.circular(14)),
        alignment: Alignment.center,
        child: Tx(
          label,
          style: ts(14, w: 700, lh: 21, c: on ? AppColors.forest : AppColors.white),
          align: TextAlign.center,
        ),
      ),
    );
  }
}

/// One photo picked from the gallery.
class StockPhoto extends StatelessWidget {
  const StockPhoto({super.key, required this.value, required this.onChange});

  final List<String> value;
  final ValueChanged<List<String>> onChange;

  Future<void> _pick() async {
    try {
      final f = await ImagePicker().pickImage(source: ImageSource.gallery);
      if (f != null) onChange(<String>[f.path]);
    } catch (_) {
      // cancelled / unavailable: nothing selected (like an empty file input)
    }
  }

  @override
  Widget build(BuildContext context) {
    final picker = Tap(
      onTap: _pick,
      child: CustomPaint(
        foregroundPainter: const DashedRRectPainter(color: AppColors.line, radius: 18),
        child: Container(
          constraints: const BoxConstraints(minHeight: 88),
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(color: const Color(0x05121214), borderRadius: BorderRadius.circular(18)),
          alignment: Alignment.center,
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const LucideIcon(Lu.imagePlus, size: 16, color: AppColors.muted),
              const SizedBox(width: 8),
              Tx('أضف صوراً من الاستوديو', style: ts(14, w: 600, lh: 21, c: AppColors.muted)),
            ],
          ),
        ),
      ),
    );
    return Padding(
      // photos
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          picker,
          if (value.isNotEmpty) ...<Widget>[
            const SizedBox(height: 8),
            LayoutBuilder(
              builder: (context, c) {
                final w = (c.maxWidth - 3 * 8) / 4;
                return Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: <Widget>[
                    for (final src in value)
                      SizedBox(
                        key: ValueKey<String>(src),
                        width: w,
                        height: w,
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: Stack(
                            fit: StackFit.expand,
                            children: <Widget>[
                              const ColoredBox(color: AppColors.ink),
                              AppImage(src),
                              Positioned(
                                top: 4,
                                left: 4,
                                child: Tap(
                                  onTap: () => onChange(const <String>[]),
                                  child: Container(
                                    width: 22,
                                    height: 22,
                                    decoration: const BoxDecoration(color: Color(0xB8121214), shape: BoxShape.circle),
                                    alignment: Alignment.center,
                                    child: Tx('×', style: ts(15, lh: 15, c: AppColors.white)),
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                );
              },
            ),
          ],
        ],
      ),
    );
  }
}

/// Form: add a listing to the inventory.
class StockForm extends StatefulWidget {
  const StockForm({super.key, required this.onDone, required this.onCancel});

  final VoidCallback onDone;
  final VoidCallback onCancel;

  @override
  State<StockForm> createState() => _StockFormState();
}

class _StockFormState extends State<StockForm> {
  String city = 'الرياض';
  String hood = kCities['الرياض']![0];
  PType type = 'villa';

  /// "sale" | "rent"
  String purpose = 'sale';
  List<String> photos = const <String>[];
  final TextEditingController _desc = TextEditingController();

  @override
  void initState() {
    super.initState();
    _desc.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _desc.dispose();
    super.dispose();
  }

  void _save() {
    final user = store.readSession();
    final desc = _desc.text;
    store.saveStock(
      Listing(
        id: 'st-${DateTime.now().millisecondsSinceEpoch}',
        kind: 'unit',
        title: '${kTypeLabel[type]} — $hood',
        purpose: purpose,
        city: city,
        neighborhood: hood,
        propertyType: type,
        source: 'broker',
        price: 0,
        rooms: type == 'land' ? null : 0,
        baths: type == 'land' ? null : 0,
        halls: type == 'land' ? null : 0,
        area: 0,
        images: photos,
        description: desc,
        advertiser: user?.name ?? 'وسيط',
        advertiserRole: user?.role == 'broker' ? 'broker' : (user?.role ?? 'broker'),
        license: '—',
        occupancy: purpose == 'rent' ? 'families' : null,
        floorLevel: type == 'apartment' ? 'first' : null,
        hasDeed: true,
        bankOk: purpose == 'sale' ? true : null,
        active: false,
      ),
    );
    widget.onDone();
  }

  @override
  Widget build(BuildContext context) {
    final blocked = _desc.text.trim().isEmpty || photos.isEmpty;
    final items = <Widget>[
      Tx('عرض للمخزون', style: ts(16, w: 700, lh: 24, c: AppColors.deep)),
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
      SvcField(
        label: 'نوع العقار',
        child: selSelect(
          value: type,
          options: <Opt>[for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
          onChanged: (v) => setState(() => type = v),
        ),
      ),
      Tx('صورة واحدة', style: ts(15, w: 500, lh: 21.5, c: AppColors.deep)),
      StockPhoto(value: photos, onChange: (v) => setState(() => photos = v.take(1).toList())),
      SvcField(
        label: 'بيع أو إيجار',
        child: selSelect(
          value: purpose,
          options: const <Opt>[Opt('sale', 'بيع'), Opt('rent', 'إيجار سنوي')],
          onChanged: (v) => setState(() => purpose = v),
        ),
      ),
      SvcField(
        label: 'وصف عقاري — كما تكتبه، بلا تنقية',
        child: FieldBox(
          controller: _desc,
          style: ts(15, lh: 21.5, c: AppColors.deep),
          height: 96,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: kSelDeco,
          multiline: true,
        ),
      ),
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            child: Tap(
              onTap: widget.onCancel,
              child: Container(
                height: 44,
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: AppColors.line),
                ),
                alignment: Alignment.center,
                child: Tx('إلغاء', style: ts(15, w: 500, lh: 21.5, c: AppColors.deep)),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: AppButton(label: 'حفظ', onTap: blocked ? null : _save),
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
