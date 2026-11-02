// إدارة العملاء — the broker's client book: list with filters, add/edit
// form, and each client's profile with notes and AI advice.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
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
    final rows = store.clients();
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
        onChange: () => setState(() {}),
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
          child: ClientForm(onDone: () => setState(() => adding = false), onCancel: () => setState(() => adding = false)),
        ),
      if (shown.isEmpty)
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
                  Tx('${c.notes.length} سجلات', style: ts(13, w: 600, lh: 19.5, c: AppColors.muted), maxLines: 1, softWrap: false),
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
  late List<String> hoods = (init != null && init!.hood.isNotEmpty)
      ? init!.hood.split('·').map((h) => h.trim()).where((h) => h.isNotEmpty).toList()
      : <String>[kCities['الرياض']![0]];
  late List<PType> types = parseTypes(init?.types);
  bool openHoods = false;
  bool openTypes = false;
  late final TextEditingController pmin = TextEditingController(text: splitRange(init?.budget).$1);
  late final TextEditingController pmax = TextEditingController(text: splitRange(init?.budget).$2);
  late final TextEditingController amin = TextEditingController(text: splitRange(init?.area).$1);
  late final TextEditingController amax = TextEditingController(text: splitRange(init?.area).$2);
  late final TextEditingController rmin = TextEditingController(text: _or(splitRange(init?.rooms).$1, '2'));
  late final TextEditingController rmax = TextEditingController(text: _or(splitRange(init?.rooms).$2, '4'));
  late final TextEditingController desc = TextEditingController(text: init?.description ?? '');
  String kind = 'تفكير';
  final TextEditingController note = TextEditingController();

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

  void _save() {
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
      _pair('المساحة من م²', amin, 'إلى', amax),
      _pair('الغرف من', rmin, 'إلى', rmax),
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
      Row(
        children: <Widget>[
          Expanded(child: _btn('إلغاء', false, widget.onCancel)),
          const SizedBox(width: 8),
          Expanded(child: _btn(init != null ? 'حفظ التعديل' : 'حفظ العميل', true, name.text.isEmpty ? null : _save)),
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
  const ClientProfile({super.key, required this.client, required this.onBack, required this.onChange});

  final Client client;
  final VoidCallback onBack;
  final VoidCallback onChange;

  @override
  State<ClientProfile> createState() => _ClientProfileState();
}

class _ClientProfileState extends State<ClientProfile> with CliNotesState<ClientProfile> {
  bool edit = false;

  Client get client => widget.client;

  @override
  List<Note> get notes => client.notes;

  @override
  void persist(List<Note> next) {
    store.saveClient(client.withNotes(next));
    widget.onChange();
  }

  @override
  String computeBrief() => briefClientRec(client);

  @override
  String get whoNoun => 'العميل';

  @override
  Widget build(BuildContext context) {
    final aiOn = billing.isSubscribed();
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
          notes: client.notes,
          phone: client.phone,
          second: CliHeroAct(
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
        CliBrief(aiOn: aiOn, hasNotes: client.notes.isNotEmpty, busy: busy, rec: brief, onRun: runBrief),
        buildNotes(),
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
