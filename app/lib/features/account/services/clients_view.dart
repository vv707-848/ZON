// إدارة العملاء — the broker's client book: list with filters, add/edit
// form, and each client's profile with notes and AI advice.
//
// With a server (ServerClients) the book is the server's: a client added once however often tried, his notes
// added and removed there (a note is kept as written: removed, not edited), «تلخيص» is the server's model (402 →
// the plans), and a client can be removed. The form asks only what the server keeps — the least area and rooms —
// and starts empty: no neighbourhood, type or rooms chosen for him. Another account signed in: its own book.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/input.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_desk.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/people_view.dart' show CrmMark;
import 'package:zon/features/account/services/widgets/client_kit.dart';
import 'package:zon/features/account/services/widgets/desk.dart';

/// Client types are saved as labels: "فيلا · شقة" → ["villa", "apartment"].
/// "دور" (the label before version 1.1) still reads as دور مستقل.
List<PType> parseTypes(String? s) {
  final labels = (s ?? '').split('·').map((t) => t.trim()).where((t) => t.isNotEmpty).map((t) => t == 'دور' ? 'دور مستقل' : t).toList();
  final out = kPropertyTypes.where((t) => labels.contains(kTypeLabel[t])).toList();
  return out.isNotEmpty ? out : <PType>['villa'];
}

/// AI-style recommendation from a client's notes (accepted / rejected / thinking).
String briefClientRec(Client client) {
  final n = client.notes;
  final ok = n.where((x) => x.kind == 'قبول').length;
  final no = n.where((x) => x.kind == 'رفض').length;
  final think = n.where((x) => x.kind == 'تفكير').length;
  var rec = 'المتابعة مستمرّة. كل ملاحظة تبني قراءة أدق في المرة القادمة.';
  if (n.isEmpty) {
    rec = 'ما فيه سجل بعد. أول ملاحظة تبني ملف العميل.';
  } else if (no > 0 && no >= ok) {
    rec = 'الرفض ظاهر. لا تعرض نفس النوع قبل فهم سبب الرفض.';
  } else if (ok > 0 && no == 0) {
    rec = 'فيه قبول. هذا وقت الإغلاق أو عرض بديل قريب من رغبته.';
  } else if (think > 0) {
    rec = 'ما زال يفكّر. ملاحظة اليوم أهم من عرض جديد.';
  }
  return rec;
}

/// دفتر العملاء — the broker's client list with filters.
class ClientsView extends StatefulWidget {
  const ClientsView({super.key});

  @override
  State<ClientsView> createState() => _ClientsViewState();
}

class _ClientsViewState extends State<ClientsView> {
  final TextEditingController _q = TextEditingController();
  String city = 'all';
  String hood = 'all';

  /// Holds a type *label* (the options' values are labels).
  String type = 'all';
  bool adding = false;
  String? openId;

  /// With a server: his book as it said, asking it, and what it could not answer.
  List<Client> _server = <Client>[];
  bool _loading = Api.on;
  String? _error;
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    _q.addListener(() => setState(() {}));
    if (Api.on) {
      store.addListener(_onAccount);
      unawaited(_load());
    }
  }

  @override
  void dispose() {
    if (Api.on) store.removeListener(_onAccount);
    _q.dispose();
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    setState(() {
      _server = <Client>[];
      _loading = true;
      _error = null;
      adding = false;
      openId = null;
    });
    unawaited(_load());
  }

  Future<void> _load() async {
    final epoch = _epoch;
    try {
      final rows = await ServerClients.list();
      if (mounted && epoch == store.serverEpoch) setState(() => _server = rows);
    } on ApiError catch (e) {
      if (mounted && epoch == store.serverEpoch) setState(() => _error = e.forUser);
    } finally {
      if (mounted && epoch == store.serverEpoch) setState(() => _loading = false);
    }
  }

  /// After a change: his book asked again with a server.
  void _changed() {
    if (Api.on) unawaited(_load());
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final rows = Api.on ? _server : store.clients();
    final hoods = city == 'all' ? const <String>[] : (kCities[city] ?? const <String>[]);
    Client? selected;
    for (final c in rows) {
      if (c.id == openId) selected = c;
    }
    if (selected != null) {
      return ClientProfile(
        key: ValueKey<String>('cp-${selected.id}'),
        client: selected,
        onBack: () => setState(() => openId = null),
        onChange: _changed,
        onRemoved: () {
          setState(() => openId = null);
          _changed();
        },
      );
    }
    final q = _q.text.trim();
    final shown = rows.where((c) {
      if (city != 'all' && c.city != city) return false;
      if (hood != 'all' && !c.hood.split('·').map((x) => x.trim()).contains(hood) && c.hood != hood) return false;
      if (type != 'all' && !c.types.contains(type) && !c.types.contains(kTypeLabel[type] ?? type)) return false;
      if (q.isNotEmpty && !<String>[c.name, c.city, c.hood, c.types].join(' ').contains(q)) return false;
      return true;
    }).toList();

    final children = <Widget>[
      DeskCard(
        showScan: false,
        children: <Widget>[
          DeskJewel(value: '${rows.length}', small: 'عملاء', title: 'دفتر العملاء'),
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
                  options: <Opt>[const Opt('all', 'الكل'), for (final t in kPropertyTypes) Opt(kTypeLabel[t]!, kTypeLabel[t]!)],
                  onChanged: (v) => setState(() => type = v),
                ),
                'نوع عقار',
              ),
            ],
          ),
        ],
      ),
      DeskToolbar(title: 'قائمة العملاء', open: adding, addLabel: 'عميل جديد', onToggle: () => setState(() => adding = !adding)),
      FindBox(controller: _q),
      if (adding)
        Padding(
          // the form keeps 14 px below it
          padding: const EdgeInsets.only(bottom: 14),
          child: ClientForm(
            onDone: () {
              setState(() => adding = false);
              _changed();
            },
            onCancel: () => setState(() => adding = false),
          ),
        ),
      if (_error case final String e) FormError(e),
      if (_loading)
        const LoadingBox()
      else if (shown.isEmpty)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 14),
          child: Tx(
            'لا عملاء في هذا النطاق.',
            style: ts(15, lh: 25, c: AppColors.muted),
            align: TextAlign.center,
          ),
        )
      else
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < shown.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 10), _card(shown[i])],
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

  Widget _card(Client c) {
    final who = <String>[c.types, c.city, c.hood].where((s) => s.isNotEmpty).join(' · ');
    return Tap(
      key: ValueKey<String>(c.id),
      onTap: () => setState(() => openId = c.id),
      child: Container(
        height: 88,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(20),
          boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
        ),
        child: Row(
          children: <Widget>[
            CrmMark(letter: firstChar(c.name), bg: AppColors.forest, fg: AppColors.white, weight: 700),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Tx(
                    c.name,
                    style: ts(16, w: 700, lh: 19, c: AppColors.forest),
                    maxLines: 1,
                    softWrap: false,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 3),
                  Tx(
                    who.isNotEmpty ? who : 'بدون تفاصيل',
                    style: ts(13, w: 600, lh: 15.5, c: AppColors.muted),
                    maxLines: 1,
                    softWrap: false,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: <Widget>[
                  Tx(
                    c.budget.isNotEmpty ? moneyTxt(c.budget) : '—',
                    style: ts(14, w: 700, lh: 21, c: AppColors.forest),
                    maxLines: 1,
                    softWrap: false,
                  ),
                  const SizedBox(height: 4),
                  Tx('${c.notesCount ?? c.notes.length} سجلات', style: ts(13, w: 600, lh: 19.5, c: AppColors.muted), maxLines: 1, softWrap: false),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// New client / edit a client's data.
class ClientForm extends StatefulWidget {
  const ClientForm({super.key, required this.onDone, required this.onCancel, this.initial, this.ink = AppColors.deep});

  final VoidCallback onDone;
  final VoidCallback onCancel;
  final Client? initial;

  /// Text colour of the surrounding page.
  final Color ink;

  @override
  State<ClientForm> createState() => _ClientFormState();
}

class _ClientFormState extends State<ClientForm> {
  late final Client? init = widget.initial;
  late final TextEditingController name = TextEditingController(text: init?.name ?? '');
  late final TextEditingController phone = TextEditingController(text: init?.phone ?? '');
  late String purpose = init?.purpose ?? 'sale';
  late String city = init?.city ?? 'الرياض';
  // with a server nothing is chosen for him: an empty preference is sent empty
  late List<String> hoods = (init != null && init!.hood.isNotEmpty)
      ? init!.hood.split('·').map((h) => h.trim()).where((h) => h.isNotEmpty).toList()
      : (Api.on ? <String>[] : <String>[kCities['الرياض']![0]]);
  late List<PType> types = Api.on && (init?.types ?? '').isEmpty ? <PType>[] : parseTypes(init?.types);
  bool openHoods = false;
  bool openTypes = false;
  late final TextEditingController pmin = TextEditingController(text: splitRange(init?.budget).$1);
  late final TextEditingController pmax = TextEditingController(text: splitRange(init?.budget).$2);
  late final TextEditingController amin = TextEditingController(text: splitRange(init?.area).$1);
  late final TextEditingController amax = TextEditingController(text: splitRange(init?.area).$2);
  late final TextEditingController rmin = TextEditingController(text: Api.on ? splitRange(init?.rooms).$1 : _or(splitRange(init?.rooms).$1, '2'));
  late final TextEditingController rmax = TextEditingController(text: Api.on ? '' : _or(splitRange(init?.rooms).$2, '4'));
  late final TextEditingController desc = TextEditingController(text: init?.description ?? '');
  String kind = 'تفكير';
  final TextEditingController note = TextEditingController();

  /// With a server: being sent, what it refused, and the key of the new client and his first note.
  bool _busy = false;
  String? _error;
  final OperationKey _newOp = OperationKey('client');
  final OperationKey _noteOp = OperationKey('note');

  static String _or(String a, String b) => a.isNotEmpty ? a : b;

  @override
  void initState() {
    super.initState();
    name.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    for (final c in <TextEditingController>[name, phone, pmin, pmax, amin, amax, rmin, rmax, desc, note]) {
      c.dispose();
    }
    super.dispose();
  }

  List<T> _toggle<T>(List<T> arr, T v) => arr.contains(v) ? arr.where((x) => x != v).toList() : <T>[...arr, v];

  /// What the server keeps of him: numbers as numbers, nothing for what is not written.
  Map<String, Object?> _body() {
    num? n(TextEditingController c) => num.tryParse(toAsciiDigits(c.text).replaceAll(RegExp(r'[^\d.]'), ''));
    return <String, Object?>{
      'name': name.text.trim(),
      'phone': phone.text.trim().isEmpty ? null : toAsciiDigits(phone.text.trim()),
      'purpose': purpose,
      'city': city,
      'neighborhoods': hoods,
      'property_types': types,
      'budget_min': n(pmin),
      'budget_max': n(pmax),
      'area_min': n(amin),
      'rooms_min': n(rmin)?.toInt(),
      'description': desc.text.trim().isEmpty ? null : desc.text.trim(),
    };
  }

  /// On the server: the client (once however often tried), then his first note if written.
  Future<void> _saveOnServer() async {
    final epoch = store.serverEpoch;
    final body = _body();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (init != null) {
        await ServerClients.update(init!.id, body, epoch: epoch);
      } else {
        final made = await ServerClients.create(body, operation: _newOp.forBody(body), epoch: epoch);
        _newOp.done();
        final text = note.text.trim();
        if (text.isNotEmpty) {
          await ServerClients.notes.add(
            made.id,
            kind,
            text,
            operation: _noteOp.forBody(<String, Object?>{'c': made.id, 'k': kind, 't': text}),
            epoch: epoch,
          );
          _noteOp.done();
        }
      }
      if (mounted && epoch == store.serverEpoch) widget.onDone();
    } on ApiError catch (e) {
      if (mounted) setState(() => _error = e.forUser);
    } on AccountChanged {
      // nothing more under the next account
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _save() {
    if (Api.on) {
      unawaited(_saveOnServer());
      return;
    }
    final now = DateTime.now().millisecondsSinceEpoch;
    store.saveClient(
      Client(
        id: init?.id ?? 'c-$now',
        name: name.text,
        phone: phone.text,
        city: city,
        hood: (hoods.isNotEmpty ? hoods : <String>[kCities[city]![0]]).join(' · '),
        types: (types.isNotEmpty ? types : <PType>['villa']).map((t) => kTypeLabel[t]!).join(' · '),
        budget: joinRange(pmin.text, pmax.text),
        purpose: purpose,
        area: joinRange(amin.text, amax.text),
        rooms: joinRange(rmin.text, rmax.text),
        description: desc.text.trim(),
        notes: init?.notes ?? (note.text.isNotEmpty ? <Note>[Note(id: 'n-$now', kind: kind, text: note.text)] : const <Note>[]),
      ),
    );
    widget.onDone();
  }

  Widget _pair(String a, TextEditingController ca, String b, TextEditingController cb) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: <Widget>[
      Expanded(child: cliLabel(a, cliInput(ca, ltr: true, keyboard: TextInputType.number, digits: true))),
      const SizedBox(width: 8),
      Expanded(child: cliLabel(b, cliInput(cb, ltr: true, keyboard: TextInputType.number, digits: true))),
    ],
  );

  Widget _textarea(TextEditingController c, {String? placeholder}) => FieldBox(
    controller: c,
    placeholder: placeholder,
    style: ts(18, lh: 26, c: AppColors.ink),
    height: 110,
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(14)),
    multiline: true,
  );

  Widget _btn(String label, bool solid, VoidCallback? onTap) {
    final box = Container(
      height: 48,
      decoration: BoxDecoration(color: solid ? AppColors.ink : AppColors.paper, borderRadius: BorderRadius.circular(16)),
      alignment: Alignment.center,
      child: Tx(label, style: ts(16, w: 600, lh: 24, c: solid ? AppColors.white : AppColors.ink)),
    );
    if (onTap == null) return Opacity(opacity: 0.35, child: box);
    return Tap(onTap: onTap, child: box);
  }

  @override
  Widget build(BuildContext context) {
    final ink = widget.ink;
    final pad = <Widget>[
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(child: cliLabel('الاسم', cliInput(name))),
          const SizedBox(width: 8),
          Expanded(child: cliLabel('رقم الجوال', cliInput(phone, ltr: true, keyboard: TextInputType.phone))),
        ],
      ),
      // buy / rent choice
      Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(16)),
        child: Row(
          children: <Widget>[
            for (final e in const <List<String>>[
              <String>['sale', 'شراء'],
              <String>['rent', 'إيجار'],
            ]) ...<Widget>[
              if (e[0] == 'rent') const SizedBox(width: 4),
              Expanded(
                child: Tap(
                  onTap: () => setState(() => purpose = e[0]),
                  child: Container(
                    height: 44,
                    decoration: BoxDecoration(
                      color: purpose == e[0] ? AppColors.ink : const Color(0x00000000),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    alignment: Alignment.center,
                    child: Tx(e[1], style: ts(18, w: 600, lh: 27, c: purpose == e[0] ? AppColors.white : AppColors.muted)),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
      cliLabel(
        'المدينة',
        cliSelect(
          value: city,
          options: optsOf(kCities.keys),
          onChanged: (v) => setState(() {
            city = v;
            hoods = <String>[kCities[v]![0]];
          }),
        ),
      ),
      CliPick(
        icon: Lu.mapPin,
        title: 'الأحياء',
        sub: 'اختيار متعدد',
        open: openHoods,
        onToggle: () => setState(() => openHoods = !openHoods),
        selectedLabels: hoods,
        all: optsOf(kCities[city] ?? const <String>[]),
        on: hoods.toSet(),
        onPick: (h) => setState(() => hoods = _toggle(hoods, h)),
        ink: ink,
      ),
      CliPick(
        icon: Lu.home,
        title: 'نوع العقار',
        sub: 'واحد أو أكثر',
        open: openTypes,
        onToggle: () => setState(() => openTypes = !openTypes),
        selectedLabels: <String>[for (final t in types) kTypeLabel[t] ?? t],
        all: <Opt>[for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
        on: types.toSet(),
        onPick: (t) => setState(() => types = _toggle(types, t)),
        ink: ink,
      ),
      _pair('الميزانية من', pmin, 'إلى', pmax),
      // the server keeps the least area and rooms he wants
      if (Api.on) ...<Widget>[
        cliLabel('المساحة من م²', cliInput(amin, ltr: true, keyboard: TextInputType.number, digits: true)),
        cliLabel('الغرف من', cliInput(rmin, ltr: true, keyboard: TextInputType.number, digits: true)),
      ] else ...<Widget>[_pair('المساحة من م²', amin, 'إلى', amax), _pair('الغرف من', rmin, 'إلى', rmax)],
      cliLabel('وصف نص حر', _textarea(desc, placeholder: 'اكتب الرغبة بشكل واضح')),
      if (init == null) ...<Widget>[
        Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Tx('نوع الملاحظة', style: ts(14, w: 600, lh: 21, c: ink)),
        ),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: <Widget>[
            for (final k in kNoteKinds)
              Tap(
                onTap: () => setState(() => kind = k),
                child: Container(
                  height: 36,
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  decoration: BoxDecoration(color: kind == k ? AppColors.ink : AppColors.paper, borderRadius: BorderRadius.circular(999)),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[Tx(k, style: ts(14, w: 600, lh: 21, c: kind == k ? AppColors.white : ink))],
                  ),
                ),
              ),
          ],
        ),
        cliLabel('الملاحظة (اختياري)', _textarea(note)),
      ],
      if (_error case final String e) FormError(e),
      Row(
        children: <Widget>[
          Expanded(child: _btn('إلغاء', false, widget.onCancel)),
          const SizedBox(width: 8),
          Expanded(
            child: _btn(
              _busy ? 'جارٍ الحفظ…' : (init != null ? 'حفظ التعديل' : 'حفظ العميل'),
              true,
              name.text.trim().isEmpty || _busy ? null : _save,
            ),
          ),
        ],
      ),
    ];
    return Container(
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: kCliLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 10),
            child: Tx(init != null ? 'تعديل بيانات العميل' : 'عميل جديد', style: ts(18, w: 600, lh: 27, c: ink)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 0, 14, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                for (var i = 0; i < pad.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 12), pad[i]],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A client's page: summary, preferences, AI advice and notes.
class ClientProfile extends StatefulWidget {
  const ClientProfile({super.key, required this.client, required this.onBack, required this.onChange, this.onRemoved});

  final Client client;
  final VoidCallback onBack;
  final VoidCallback onChange;

  /// He was removed (a server's book).
  final VoidCallback? onRemoved;

  @override
  State<ClientProfile> createState() => _ClientProfileState();
}

class _ClientProfileState extends State<ClientProfile> with CliNotesState<ClientProfile> {
  bool edit = false;

  Client get client => widget.client;

  @override
  DeskNotes? get serverNotes => Api.on ? ServerClients.notes : null;

  @override
  String get personId => client.id;

  @override
  String get summaryScope => 'client';

  @override
  void notesChanged() => widget.onChange();

  @override
  List<Note> get notes => client.notes;

  @override
  void persist(List<Note> next) {
    store.saveClient(client.withNotes(next));
    widget.onChange();
  }

  Future<void> _remove() async {
    final ok = await showAppDialog(
      context,
      icon: Lu.trash2,
      title: 'حذف العميل؟',
      message: '«${client.name}» وملاحظاته تُحذف من دفترك نهائياً.',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (!ok || !mounted) return;
    await sendDesk((epoch) async {
      await ServerClients.remove(client.id, epoch: epoch);
      if (notesCurrent) widget.onRemoved?.call();
    });
  }

  @override
  String computeBrief() => briefClientRec(client);

  @override
  String get whoNoun => 'العميل';

  @override
  Widget build(BuildContext context) {
    // with a server the server says whether his plan includes it (402)
    final aiOn = Api.on ? !needsPlan : billing.isSubscribed();
    final extra = (client.description ?? '').trim();
    final children = <Widget>[
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: CliBack(label: 'العملاء', onTap: widget.onBack),
      ),
    ];
    if (edit) {
      children.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: ClientForm(
            initial: client,
            ink: AppColors.ink,
            onDone: () {
              setState(() => edit = false);
              widget.onChange();
            },
            onCancel: () => setState(() => edit = false),
          ),
        ),
      );
    } else {
      children.addAll(<Widget>[
        CliHero(
          name: client.name,
          roleLabel: 'عميل',
          notes: shownNotes,
          phone: client.phone,
          // a client of his desk is not an account: with a server there is no conversation with him to open
          second: Api.on
              ? const CliHeroAct(icon: Lu.messageCircle, label: 'محادثة', off: true)
              : CliHeroAct(
                  icon: Lu.messageCircle,
                  label: 'محادثة',
                  onTap: () {
                    store.openChat(Chat(id: client.id, name: client.name, role: 'seeker', last: 'من إدارة العملاء'));
                    Nav.go(context, '/chats/${client.id}');
                  },
                ),
        ),
        CliPrefs(
          icon: Lu.slidersHorizontal,
          title: 'تفضيلات العميل',
          specs: <(String, String)>[
            ('الغرض', client.purpose == 'rent' ? 'إيجار' : 'شراء'),
            ('النوع', client.types.isNotEmpty ? client.types : '—'),
            if (more) ...<(String, String)>[
              ('المدينة', client.city.isNotEmpty ? client.city : '—'),
              ('الحي', client.hood.isNotEmpty ? client.hood : '—'),
              ('الميزانية', client.budget.isNotEmpty ? moneyTxt(client.budget) : '—'),
              ('الغرف', (client.rooms ?? '').isNotEmpty ? client.rooms! : '—'),
              ('المساحة', (client.area ?? '').isNotEmpty ? '${client.area} م²' : '—'),
            ],
          ],
          wish: more && extra.isNotEmpty ? extra : null,
          more: more,
          onMore: () => setState(() => more = !more),
          onEdit: more ? () => setState(() => edit = true) : null,
        ),
        CliBrief(aiOn: aiOn, hasNotes: shownNotes.isNotEmpty, busy: busy, rec: brief, onRun: runBrief),
        buildNotes(),
        if (Api.on && widget.onRemoved != null) ...<Widget>[
          const SizedBox(height: 18),
          AppButton(label: 'حذف العميل', tone: ButtonTone.ghost, icon: Lu.trash2, onTap: sendingNote ? null : () => unawaited(_remove())),
        ],
      ]);
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
      child: DefaultTextStyle.merge(
        style: const TextStyle(color: AppColors.ink),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
      ),
    );
  }
}
