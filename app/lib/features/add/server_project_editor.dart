// His project's editor with a server (ServerMyProjects). The project's model on the server is not the phone's:
// a unit model carries a count of units available, not one card per unit. So this editor is its own:
//
// * the project: its title, his text, its map link — «حفظ المشروع» (PATCH). A new text goes back to the wording;
//   the public reads his own meanwhile.
// * each model: its price, its count available, his text, and in a ready project its licence asked of REGA —
//   «حفظ النموذج»; «بيع وحدات» (how many, and the price each sold for: the contract's commission is reckoned on
//   it); «حذف» asks first (the server keeps one model at least in a published project).
// * a new model: its name, area, rooms, price, count and text — sent once however often tried.
// * a text waiting for his decision opens the wording review (project_review_screen.dart).
//
// What he typed is checked before it is sent (a number, never a silent 0), and the server's refusal is said as is.
// Another account signed in under it: nothing of this one stays shown.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_my_projects.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/sale_price_dialog.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/add_kit.dart';

class ServerProjectEditor extends StatefulWidget {
  const ServerProjectEditor({super.key, required this.id});

  /// The project's number on the server.
  final int id;

  @override
  State<ServerProjectEditor> createState() => _ServerProjectEditorState();
}

class _ServerProjectEditorState extends State<ServerProjectEditor> {
  MyProject? _p;
  String? _loadError;
  int _epoch = store.serverEpoch;

  // the project's fields as typed
  String _title = '';
  String _text = '';
  String _maps = '';

  // each model's fields as typed, by its id
  final Map<int, String> _price = <int, String>{};
  final Map<int, String> _count = <int, String>{};
  final Map<int, String> _unitText = <int, String>{};
  final Map<int, String> _licence = <int, String>{};

  // a new model
  bool _adding = false;
  String _newName = '';
  String _newArea = '';
  String _newRooms = '';
  String _newPrice = '';
  String _newCount = '1';
  String _newText = '';
  final OperationKey _newOp = OperationKey('unit');

  /// What is being sent ('project', a model's id, 'new'), and what the last send said.
  String? _busy;
  String? _error;
  String? _done;

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
      _p = null;
      _adding = false;
      _busy = null;
      _error = null;
      _done = null;
    });
    unawaited(_load());
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _load() async {
    final epoch = _epoch;
    try {
      final p = await ServerMyProjects.detail(widget.id);
      if (!_current(epoch)) return;
      setState(() {
        _p = p;
        _loadError = null;
        _title = p.title;
        _text = p.text;
        _maps = p.mapsLink;
        for (final u in p.units) {
          _price[u.id] = u.price == null ? '' : editableNumber(u.price);
          _count[u.id] = '${u.available}';
          _unitText[u.id] = u.text;
          _licence[u.id] = u.licence ?? '';
        }
      });
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _loadError = e.forUser);
    }
  }

  /// Runs [send] for [what], then reads the project again; says [done] or the server's refusal.
  Future<void> _run(String what, Future<void> Function(int epoch) send, String done) async {
    final epoch = _epoch;
    setState(() {
      _busy = what;
      _error = null;
      _done = null;
    });
    try {
      await send(epoch);
      if (!_current(epoch)) return;
      await _load();
      if (_current(epoch)) setState(() => _done = done);
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } on AccountChanged {
      // nothing more under the next account
    } finally {
      if (_current(epoch)) setState(() => _busy = null);
    }
  }

  // ------------------------------------------------------------- checks of what he typed

  /// A price as typed: above zero (the server refuses zero), Arabic digits read, never a silent 0.
  static ({double? value, String? error}) _priceOf(String text, String what) {
    final r = parsePrice(text);
    if (r.error != null) return (value: null, error: '$what: ${r.error}');
    if (r.value == null || r.value! <= 0) return (value: null, error: '$what: اكتب السعر');
    return (value: r.value, error: null);
  }

  static int? _countOf(String text) {
    final n = int.tryParse(toAsciiDigits(text.trim()));
    return n == null || n < 0 || n > 5000 ? null : n;
  }

  // ------------------------------------------------------------- sends

  void _saveProject() {
    if (_title.trim().length < 3 || _text.trim().length < 10) {
      setState(() => _error = 'العنوان 3 أحرف فأكثر، والوصف 10 أحرف فأكثر');
      return;
    }
    final p = _p!;
    final body = <String, Object?>{'title': _title.trim(), 'description': _text.trim(), 'maps_link': _maps.trim().isEmpty ? null : _maps.trim()};
    final textChanged = _text.trim() != p.text.trim();
    unawaited(
      _run(
        'project',
        (epoch) => ServerMyProjects.update(p.id, body, epoch: epoch),
        textChanged ? 'حُفظ المشروع، ووصفه الجديد يُصاغ الآن. راجعه حين يجهز.' : 'حُفظ المشروع.',
      ),
    );
  }

  void _saveUnit(MyUnit u) {
    final price = _priceOf(_price[u.id] ?? '', 'سعر ${u.name}');
    final count = _countOf(_count[u.id] ?? '');
    final text = (_unitText[u.id] ?? '').trim();
    final problem =
        price.error ?? (count == null ? 'عدد ${u.name} رقم صحيح من 0 إلى 5000' : null) ?? (text.length < 10 ? 'وصف ${u.name} 10 أحرف فأكثر' : null);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    final p = _p!;
    final body = <String, Object?>{'price': _plain(price.value!), 'available_count': count, 'description': text};
    final licence = toAsciiDigits(_licence[u.id] ?? '').trim();
    final newLicence = p.ready && licence.isNotEmpty && licence != (u.licence ?? '');
    unawaited(
      _run('${u.id}', (epoch) async {
        await ServerMyProjects.updateUnit(p.id, u.id, body, epoch: epoch);
        if (newLicence) await ServerMyProjects.licence(p.id, u.id, licence, epoch: epoch); // asked of REGA
      }, 'حُفظ النموذج ${u.name}.'),
    );
  }

  Future<void> _sell(MyUnit u) async {
    if (u.available < 1) return;
    final count = await _askCount(u);
    if (count == null || !mounted) return;
    final price = await askSalePrice(context, listed: u.price ?? 0);
    if (price == null || !mounted) return;
    final p = _p!;
    await _run('${u.id}', (epoch) async {
      store.stillAccount(epoch);
      await ServerMyProjects.sell(p.id, u.id, count: count, salePrice: price);
    }, 'سُجّل بيع $count من ${u.name}.');
  }

  Future<void> _remove(MyUnit u) async {
    final ok = await showAppDialog(
      context,
      icon: Lu.trash2,
      title: 'حذف النموذج ${u.name}',
      message: 'يُحذف النموذج ووحداته المتاحة من المشروع.',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (!ok || !mounted) return;
    final p = _p!;
    await _run('${u.id}', (epoch) => ServerMyProjects.removeUnit(p.id, u.id, epoch: epoch), 'حُذف النموذج ${u.name}.');
  }

  void _addUnit() {
    final p = _p!;
    final price = _priceOf(_newPrice, 'السعر');
    final count = _countOf(_newCount);
    final area = parseUserNumber(_newArea);
    final rooms = _newRooms.trim().isEmpty ? null : int.tryParse(toAsciiDigits(_newRooms.trim()));
    final problem =
        (_newName.trim().isEmpty ? 'اسم النموذج' : null) ??
        price.error ??
        (count == null ? 'العدد رقم صحيح من 0 إلى 5000' : null) ??
        (_newArea.trim().isNotEmpty && (area == null || area <= 0) ? 'المساحة رقم أكبر من صفر' : null) ??
        (_newRooms.trim().isNotEmpty && (rooms == null || rooms < 0) ? 'الغرف رقم صحيح' : null) ??
        (_newText.trim().length < 10 ? 'وصف النموذج 10 أحرف فأكثر' : null);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    final type = p.propertyType;
    final s = specsForUnit(type);
    final body = <String, Object?>{
      'model_name': _newName.trim(),
      'property_type': type,
      if (area != null) (s.built ? 'area_built' : 'area_land'): _plain(area),
      if (s.rooms && rooms != null) 'bedrooms': rooms,
      'price': _plain(price.value!),
      'available_count': count,
      'description': _newText.trim(),
      'media_ids': <int>[],
    };
    unawaited(
      _run('new', (epoch) async {
        await ServerMyProjects.addUnit(p.id, body, operation: _newOp.forBody(body), epoch: epoch);
        _newOp.done();
        if (mounted) {
          setState(() {
            _adding = false;
            _newName = _newArea = _newRooms = _newPrice = _newText = '';
            _newCount = '1';
          });
        }
      }, 'أُضيف النموذج. وصفه يُصاغ الآن، وفي المشروع الجاهز يحتاج ترخيصه.'),
    );
  }

  static num _plain(double v) => v == v.roundToDouble() ? v.toInt() : v;

  /// How many of [u] sold: 1 to its count available.
  Future<int?> _askCount(MyUnit u) async {
    if (u.available == 1) return 1;
    var text = '1';
    String? error;
    return showAppFrame<int>(
      context,
      label: 'عدد المباع',
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => AppDialogCard(
          icon: Lu.circleAlert,
          title: 'كم وحدة بيعت من ${u.name}؟',
          message: 'المتاح ${u.available}.',
          extra: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              projInput(value: text, dir: TextDirection.ltr, keyboard: TextInputType.number, onChanged: (v) => set(() => text = v)),
              if (error case final String e) ...<Widget>[const SizedBox(height: 8), FormError(e)],
            ],
          ),
          buttons: ButtonPair(
            secondary: AppButton(label: 'إلغاء', tone: ButtonTone.tonal, onTap: () => Navigator.of(ctx).pop()),
            primary: AppButton(
              label: 'متابعة',
              onTap: () {
                final n = int.tryParse(toAsciiDigits(text.trim()));
                if (n == null || n < 1 || n > u.available) {
                  set(() => error = 'من 1 إلى ${u.available}');
                  return;
                }
                Navigator.of(ctx).pop(n);
              },
            ),
          ),
        ),
      ),
    );
  }

  // ------------------------------------------------------------- the page

  @override
  Widget build(BuildContext context) {
    final p = _p;
    if (p == null) {
      return Padding(
        padding: const EdgeInsets.all(20),
        child: _loadError != null
            ? EmptyBox(
                title: 'تعذّر جلب المشروع',
                text: _loadError!,
                textStyle: emptyTextStyle(),
                action: 'أعد المحاولة',
                onAction: () => unawaited(_load()),
              )
            : const LoadingBox(),
      );
    }
    Widget field(String icon, String label, String value, ValueChanged<String> on, {bool number = false, bool area = false}) => ProjField(
      icon: icon,
      label: label,
      textarea: area,
      child: area
          ? projTextarea(value: value, onChanged: on)
          : projInput(value: value, onChanged: on, dir: number ? TextDirection.ltr : null, keyboard: number ? TextInputType.number : null),
    );
    final sending = _busy != null;
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
      children: <Widget>[
        BackLink(label: 'مشاريعي', onTap: () => Nav.go(context, '/account/listings')),
        const SizedBox(height: 12),
        if (p.wordingWaits) ...<Widget>[
          AppButton(
            label: 'أوصاف تنتظر قرارك',
            icon: Lu.sparkles,
            tone: ButtonTone.secondary,
            onTap: () => Nav.go(context, '/account/projects/${p.id}/review'),
          ),
          const SizedBox(height: 12),
        ],
        if (_error case final String e) ...<Widget>[FormError(e), const SizedBox(height: 10)],
        if (_done case final String d) ...<Widget>[Tx(d, style: ts(14, w: 600, c: AppColors.forest)), const SizedBox(height: 10)],
        ProjCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              field(Lu.tag, 'العنوان', _title, (v) => setState(() => _title = v)),
              const SizedBox(height: 12),
              field(Lu.fileText, 'وصف المشروع', _text, (v) => setState(() => _text = v), area: true),
              const SizedBox(height: 12),
              field(Lu.link2, 'رابط الخريطة', _maps, (v) => setState(() => _maps = v)),
              const SizedBox(height: 14),
              AppButton(label: _busy == 'project' ? 'جارٍ الحفظ…' : 'حفظ المشروع', onTap: sending ? null : _saveProject),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Tx('النماذج — ${p.units.length}', style: ts(16, w: 700)),
        const SizedBox(height: 8),
        for (final u in p.units) ...<Widget>[
          ProjCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Tx('${u.name} · المتاح ${u.available}', style: ts(15, w: 700)),
                const SizedBox(height: 10),
                field(Lu.wallet, 'السعر الثابت', _price[u.id] ?? '', (v) => setState(() => _price[u.id] = v), number: true),
                const SizedBox(height: 10),
                field(Lu.layers, 'عدد الوحدات المتاحة', _count[u.id] ?? '', (v) => setState(() => _count[u.id] = v), number: true),
                const SizedBox(height: 10),
                field(Lu.fileText, 'وصف النموذج', _unitText[u.id] ?? '', (v) => setState(() => _unitText[u.id] = v), area: true),
                if (p.ready) ...<Widget>[
                  const SizedBox(height: 10),
                  field(Lu.fileCheck, 'رقم الترخيص الإعلاني', _licence[u.id] ?? '', (v) => setState(() => _licence[u.id] = v), number: true),
                ],
                const SizedBox(height: 12),
                AppButton(label: _busy == '${u.id}' ? 'جارٍ الإرسال…' : 'حفظ النموذج', onTap: sending ? null : () => _saveUnit(u)),
                const SizedBox(height: 8),
                ButtonPair(
                  secondary: AppButton(label: 'حذف', tone: ButtonTone.tonal, onTap: sending ? null : () => unawaited(_remove(u))),
                  primary: AppButton(
                    label: 'بيع وحدات',
                    tone: ButtonTone.secondary,
                    onTap: sending || u.available < 1 ? null : () => unawaited(_sell(u)),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
        ],
        if (_adding)
          ProjCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Tx('نموذج جديد', style: ts(15, w: 700)),
                const SizedBox(height: 10),
                field(Lu.tag, 'اسم النموذج', _newName, (v) => setState(() => _newName = v)),
                const SizedBox(height: 10),
                field(Lu.maximize2, 'المساحة م²', _newArea, (v) => setState(() => _newArea = v), number: true),
                if (specsForUnit(p.propertyType).rooms) ...<Widget>[
                  const SizedBox(height: 10),
                  field(Lu.bedDouble, 'الغرف', _newRooms, (v) => setState(() => _newRooms = v), number: true),
                ],
                const SizedBox(height: 10),
                field(Lu.wallet, 'السعر الثابت', _newPrice, (v) => setState(() => _newPrice = v), number: true),
                const SizedBox(height: 10),
                field(Lu.layers, 'عدد الوحدات من هذا النموذج', _newCount, (v) => setState(() => _newCount = v), number: true),
                const SizedBox(height: 10),
                field(Lu.fileText, 'وصف النموذج', _newText, (v) => setState(() => _newText = v), area: true),
                const SizedBox(height: 12),
                ButtonPair(
                  secondary: AppButton(label: 'إلغاء', tone: ButtonTone.tonal, onTap: sending ? null : () => setState(() => _adding = false)),
                  primary: AppButton(label: _busy == 'new' ? 'جارٍ الإضافة…' : 'إضافة النموذج', onTap: sending ? null : _addUnit),
                ),
              ],
            ),
          )
        else
          AppButton(label: 'إضافة نموذج', icon: Lu.plus, tone: ButtonTone.tonal, onTap: sending ? null : () => setState(() => _adding = true)),
      ],
    );
  }
}
