// مهامي — personal tasks: summary card, filters, add / edit / delete,
// grouped by priority (مهم / مؤجل / هادي) with a done section.
//
// With a server (ServerTasks) the tasks are the server's: each change is sent and shown once it is taken, a new
// task once however often tried, «تم» says which (sent twice it stays), and its reminder comes from the server.
// «لخّص يومي» is the server's model (402 → the plans); nothing is simulated. Another account signed in: its own
// tasks, nothing of the previous one.

import 'dart:async';

import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_desk.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/widgets/desk.dart';
import 'package:zon/features/account/services/widgets/service_form.dart';

const Map<String, String> _priKey = <String, String>{'مهم': 'important', 'مؤجل': 'postponed', 'عادي': 'normal', 'هادي': 'normal'};
const List<String> _priFilters = <String>['الكل', 'مهم', 'مؤجل', 'هادي'];

/// The reminder in Arabic: "12 أكتوبر، 4:05 م" (null when there is none or
/// when the saved value cannot be read — nothing like "Invalid Date").
String? remindLabel(String? v) {
  if (v == null || v.isEmpty) return null;
  final d = DateTime.tryParse(v);
  if (d == null) return null;
  return '${arDate(d)}، ${arTime(d)}';
}

/// مهامي — the personal task list grouped by priority.
class TasksView extends StatefulWidget {
  const TasksView({super.key});

  @override
  State<TasksView> createState() => _TasksViewState();
}

class _TasksViewState extends State<TasksView> {
  late List<Task> items = Api.on ? <Task>[] : store.tasks();
  bool open = false;
  String? edit;
  String sum = '';
  bool scan = false;
  String filter = 'الكل';
  Timer? _timer;

  /// With a server: asking it, what it could not do, the task being sent, and whether its summary needs a plan.
  bool _loading = Api.on;
  String? _error;
  String? _busy;
  bool _needsPlan = false;
  int _epoch = store.serverEpoch;
  final OperationKey _newOp = OperationKey('task');

  @override
  void initState() {
    super.initState();
    if (Api.on) {
      store.addListener(_onAccount);
      unawaited(_load());
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    if (Api.on) store.removeListener(_onAccount);
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    setState(() {
      items = <Task>[];
      _loading = true;
      _error = null;
      _busy = null;
      sum = '';
      open = false;
      edit = null;
      _needsPlan = false;
    });
    unawaited(_load());
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _load() async {
    final epoch = _epoch;
    try {
      final rows = await ServerTasks.list();
      if (_current(epoch)) setState(() => items = rows);
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } finally {
      if (_current(epoch)) setState(() => _loading = false);
    }
  }

  /// Sends [what] for [id] (one at a time); its error is said and nothing changes.
  Future<void> _send(String id, Future<void> Function(int epoch) what) async {
    if (_busy != null) return;
    final epoch = _epoch;
    setState(() {
      _busy = id;
      _error = null;
    });
    try {
      await what(epoch);
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } on AccountChanged {
      // the next account's tasks are its own
    } finally {
      if (_current(epoch)) setState(() => _busy = null);
    }
  }

  void _put(Task row) => setState(() => items = <Task>[for (final t in items) t.id == row.id ? row : t]);

  bool _matchPri(String pri, String label) => label == 'هادي' ? pri == 'عادي' : pri == label;

  void _persist(List<Task> next) {
    setState(() => items = next);
    store.saveTasks(next);
  }

  void _toggle(Task t) {
    if (!Api.on) return _persist(<Task>[for (final x in items) x.id == t.id ? x.toggled() : x]);
    unawaited(_send(t.id, (epoch) async {
      final row = await ServerTasks.done(t.id, done: t.done != true, epoch: epoch);
      if (_current(epoch)) _put(row);
    }));
  }

  /// A task saved from the form: on the server, or on this phone.
  void _saved(Task row) {
    final before = edit == null ? null : items.where((t) => t.id == edit).firstOrNull;
    if (!Api.on) {
      _persist(before != null ? <Task>[for (final t in items) t.id == row.id ? row : t] : <Task>[row, ...items]);
      setState(() {
        open = false;
        edit = null;
      });
      return;
    }
    unawaited(_send(before?.id ?? 'new', (epoch) async {
      if (before == null) {
        final made = await ServerTasks.create(row, operation: _newOp.forBody(ServerTasks.body(row)), epoch: epoch);
        _newOp.done();
        if (_current(epoch)) setState(() => items = <Task>[made, ...items]);
      } else {
        final kept = await ServerTasks.update(row, before: before, epoch: epoch);
        if (_current(epoch)) _put(kept);
      }
      if (_current(epoch)) {
        setState(() {
          open = false;
          edit = null;
        });
      }
    }));
  }

  /// «لخّص يومي»: the server's model with a server; without one, the order of his tasks.
  void _summarize(List<Task> openRows) {
    setState(() => scan = true);
    if (Api.on) {
      final epoch = _epoch;
      unawaited(() async {
        try {
          final text = await ServerSummaries.of('tasks');
          if (_current(epoch)) setState(() => sum = text);
        } on NeedsPlan {
          if (_current(epoch)) setState(() => _needsPlan = true);
        } on ApiError catch (e) {
          if (_current(epoch)) setState(() => _error = e.forUser);
        } finally {
          if (_current(epoch)) setState(() => scan = false);
        }
      }());
      return;
    }
    _timer = Timer(const Duration(milliseconds: 1600), () {
      if (!mounted) return;
      setState(() {
        scan = false;
        if (openRows.isNotEmpty) {
          Task? first;
          for (final t in openRows) {
            if (t.pri == 'مهم') {
              first = t;
              break;
            }
          }
          sum = 'ابدأ بـ «${(first ?? openRows.first).title}» ثم أكمل الباقي حسب الأولوية.';
        } else {
          sum = '';
        }
      });
    });
  }

  /// Asks for confirmation, then removes [t] for good.
  Future<void> _delete(Task t) async {
    final ok = await showAppDialog(
      context,
      icon: Lu.trash2,
      title: 'حذف المهمة؟',
      message: '«${t.title}» ستُحذف من قائمتك نهائياً.',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (!ok || !mounted) return;
    if (edit == t.id) edit = null;
    if (Api.on) {
      return _send(t.id, (epoch) async {
        await ServerTasks.remove(t.id, epoch: epoch);
        if (_current(epoch)) setState(() => items = <Task>[for (final x in items) if (x.id != t.id) x]);
      });
    }
    _persist(<Task>[
      for (final x in items)
        if (x.id != t.id) x,
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final openRows = items.where((t) => t.done != true).toList();
    final done = items.where((t) => t.done == true).toList();
    final hot = openRows.where((t) => t.pri == 'مهم').length;
    final hold = openRows.where((t) => t.pri == 'مؤجل').length;
    final ok = openRows.where((t) => t.pri == 'عادي').length;
    final pct = items.isNotEmpty ? ((done.length / items.length) * 100).round() : 0;
    // with a server the server says whether his plan includes it (402)
    final sub = Api.on ? !_needsPlan : billing.isSubscribed();

    final children = <Widget>[
      DeskCard(
        scanning: scan,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Tx('مكتب اليوم', style: ts(12, w: 700, lh: 18, c: kDeskSoft)),
                    const SizedBox(height: 6),
                    Text.rich(
                      TextSpan(
                        children: <InlineSpan>[
                          TextSpan(
                            text: '${openRows.length}',
                            style: ts(32, w: 700, c: AppColors.white),
                          ),
                          const WidgetSpan(child: SizedBox(width: 8)),
                          TextSpan(
                            text: 'مفتوحة',
                            style: ts(14, w: 600, c: kDeskSoft),
                          ),
                        ],
                      ),
                      strutStyle: const StrutStyle(fontSize: 32, height: 1, forceStrutHeight: true),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              DeskRing(pct: pct.toDouble(), label: '$pct%'),
            ],
          ),
          DeskMeters(
            items: <DeskMeter>[
              DeskMeter(value: '$hot', label: 'مهم', tone: 'hot', on: filter == 'مهم', onTap: () => setState(() => filter = 'مهم')),
              DeskMeter(value: '$hold', label: 'مؤجل', tone: 'hold', on: filter == 'مؤجل', onTap: () => setState(() => filter = 'مؤجل')),
              DeskMeter(value: '$ok', label: 'هادي', tone: 'ok', on: filter == 'هادي', onTap: () => setState(() => filter = 'هادي')),
            ],
          ),
          if (sub)
            DeskAi(
              label: scan ? 'الذكاء يرتّب يومك…' : 'لخّص يومي بالذكاء',
              icon: Lu.sparkles,
              disabled: openRows.isEmpty || scan,
              onTap: () => _summarize(openRows),
            )
          else
            DeskAi(label: 'تلخيص الذكاء ضمن الخدمة', onTap: () => Nav.go(context, '/account/plans')),
          if (sum.isNotEmpty) deskBrief('تلخيص الذكاء', sum) else deskHint('يقرأ مهامك ويرتّب وش تبدأ فيه الحين.'),
        ],
      ),
      DeskToolbar(
        title: 'قائمة اليوم',
        open: open,
        addLabel: 'مهمة جديدة',
        onToggle: () => setState(() {
          open = !open;
          edit = null;
        }),
      ),
      OwnStrip(
        items: <StripItem>[for (final f in _priFilters) StripItem(label: f, on: filter == f, onTap: () => setState(() => filter = f))],
      ),
    ];

    if (open || edit != null) {
      Task? task;
      for (final t in items) {
        if (t.id == edit) task = t;
      }
      children.add(
        TaskForm(
          key: ValueKey<String?>(edit),
          task: task,
          busy: _busy != null,
          onSaved: _saved,
        ),
      );
    }
    if (_error case final String e) children.add(FormError(e));
    if (_loading) {
      children.add(const LoadingBox());
    } else if (items.isEmpty) {
      children.add(Tx('لا مهام — مثال: روح أكلم خالد يشتري الفيلا — حدّد المنبّه والأولوية.', style: ts(14, w: 500, lh: 22.5, c: AppColors.muted)));
    }
    for (final label in const <String>['مهم', 'مؤجل', 'هادي']) {
      if (filter != 'الكل' && filter != label) continue;
      final lane = openRows.where((t) => _matchPri(t.pri, label)).toList();
      if (lane.isEmpty) {
        if (filter == label) {
          children.add(Tx('لا مهام «$label» حالياً', style: ts(14, w: 500, lh: 22.5, c: AppColors.muted)));
        }
        continue;
      }
      children.add(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            LaneHead(title: label, hint: label == 'مهم' ? 'ابدأ من هنا' : (label == 'مؤجل' ? 'لاحقًا' : 'روتيني'), count: '${lane.length}'),
            for (final t in lane) ...<Widget>[
              const SizedBox(height: 8),
              Ticket(task: t, onToggle: () => _toggle(t), onEdit: () => setState(() => edit = t.id), onDelete: () => _delete(t)),
            ],
          ],
        ),
      );
    }
    final doneShown = done.where((t) => filter == 'الكل' || _matchPri(t.pri, filter)).toList();
    if (doneShown.isNotEmpty) {
      children.add(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            LaneHead(title: 'منجزة', hint: 'أُغلقت اليوم', count: '${doneShown.length}'),
            for (final t in doneShown) ...<Widget>[
              const SizedBox(height: 8),
              Ticket(task: t, onToggle: () => _toggle(t), onEdit: () => setState(() => edit = t.id), onDelete: () => _delete(t)),
            ],
          ],
        ),
      );
    }

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

/// One task card: done toggle, priority, reminder, edit and delete.
class Ticket extends StatelessWidget {
  const Ticket({super.key, required this.task, required this.onToggle, required this.onEdit, required this.onDelete});

  final Task task;
  final VoidCallback onToggle;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final p = _priKey[task.pri] ?? 'normal';
    final done = task.done == true;
    final when = remindLabel(task.remindAt);
    final (Color pillBg, Color pillFg) = switch (p) {
      'important' => (const Color(0xFFF8E4E0), const Color(0xFF8A3A32)),
      'postponed' => (const Color(0xFFF6F0D8), const Color(0xFF6B5A1A)),
      _ => (AppColors.mist, AppColors.forest),
    };
    final card = Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          SizedBox(
            width: 36,
            child: Align(
              alignment: AlignmentDirectional.topStart,
              child: Semantics(
                button: true,
                label: done ? 'إعادة المهمة' : 'إنجاز المهمة',
                child: Tap(
                onTap: onToggle,
                child: Container(
                  width: 28,
                  height: 28,
                  margin: const EdgeInsets.only(top: 2),
                  decoration: BoxDecoration(
                    color: done ? AppColors.forest : AppColors.white,
                    shape: BoxShape.circle,
                    border: Border.all(color: AppColors.forest),
                  ),
                  alignment: Alignment.center,
                  child: done ? const LucideIcon(Lu.check, size: 14, color: AppColors.white) : null,
                ),
              ),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                SizedBox(
                  height: 28,
                  child: Row(
                    children: <Widget>[
                      Container(
                        height: 22,
                        padding: const EdgeInsets.symmetric(horizontal: 8),
                        decoration: BoxDecoration(color: pillBg, borderRadius: BorderRadius.circular(999)),
                        alignment: Alignment.center,
                        child: Tx(task.pri == 'عادي' ? 'هادي' : task.pri, style: ts(12, w: 700, lh: 18, c: pillFg)),
                      ),
                      const Spacer(),
                      const SizedBox(width: 8),
                      _TicketAction(label: 'تعديل', icon: Lu.pencil, color: AppColors.forest, onTap: onEdit),
                      const SizedBox(width: 6),
                      _TicketAction(label: 'حذف', icon: Lu.trash2, color: AppColors.danger, onTap: onDelete),
                    ],
                  ),
                ),
                const SizedBox(height: 6),
                Tx(task.title, style: ts(16, w: 700, lh: 22.5, c: AppColors.deep)),
                // the reminder in Arabic («12 أكتوبر، 4:05 م») has its own
                // line: next to the pill and the actions it was cut off.
                if (when != null) ...<Widget>[
                  const SizedBox(height: 4),
                  Row(
                    children: <Widget>[
                      const LucideIcon(Lu.bell, size: 12, color: AppColors.muted),
                      const SizedBox(width: 4),
                      Flexible(
                        child: Tx(when, style: ts(12, lh: 18, c: AppColors.muted)),
                      ),
                    ],
                  ),
                ],
                if ((task.body ?? '').isNotEmpty) ...<Widget>[
                  const SizedBox(height: 6),
                  Tx(task.body!, style: ts(14, w: 500, lh: 22.5, c: AppColors.sage)),
                ],
              ],
            ),
          ),
        ],
      ),
    );
    final withEdit = card;
    return done ? Opacity(opacity: 0.55, child: withEdit) : withEdit;
  }
}

/// Small pill button on a task card ("تعديل" / "حذف").
class _TicketAction extends StatelessWidget {
  const _TicketAction({required this.label, required this.icon, required this.color, required this.onTap});

  final String label;
  final String icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: onTap,
      child: Container(
        height: 28,
        padding: const EdgeInsets.symmetric(horizontal: 10),
        decoration: BoxDecoration(color: color.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(999)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            LucideIcon(icon, size: 13, strokeWidth: 2, color: color),
            const SizedBox(width: 4),
            Tx(label, style: ts(13, w: 600, lh: 19.5, c: color)),
          ],
        ),
      ),
    );
  }
}

/// Add / edit task form.
class TaskForm extends StatefulWidget {
  const TaskForm({super.key, this.task, required this.onSaved, this.busy = false});

  final Task? task;
  final ValueChanged<Task> onSaved;

  /// Being sent to the server: the button waits.
  final bool busy;

  @override
  State<TaskForm> createState() => _TaskFormState();
}

class _TaskFormState extends State<TaskForm> {
  late final TextEditingController _title = TextEditingController(text: widget.task?.title ?? '');
  late final TextEditingController _body = TextEditingController(text: widget.task?.body ?? '');
  late String pri = widget.task?.pri ?? 'مهم';
  late String remind = widget.task?.remindAt ?? '';
  String error = '';

  @override
  void initState() {
    super.initState();
    _title.addListener(() => setState(() => error = ''));
  }

  /// A title of spaces only is refused (QA TASK-05); the saved title and
  /// details are trimmed.
  void _save() {
    final title = _title.text.trim();
    if (title.isEmpty) {
      setState(() => error = 'اكتب عنوان المهمة — لا يكفي فراغ.');
      return;
    }
    widget.onSaved(
      Task(
        id: widget.task?.id ?? 't-${DateTime.now().millisecondsSinceEpoch}',
        title: title,
        body: _body.text.trim(),
        pri: pri,
        remindAt: remind,
        done: widget.task?.done,
      ),
    );
  }

  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    Widget duo(String k) {
      final on = (pri == 'عادي' ? 'هادي' : pri) == k;
      return Tap(
        onTap: () => setState(() => pri = k == 'هادي' ? 'عادي' : k),
        child: Container(
          height: 40,
          decoration: BoxDecoration(
            color: on ? AppColors.forest : AppColors.surface,
            borderRadius: BorderRadius.circular(12),
            boxShadow: on ? null : AppShadows.hairline,
          ),
          alignment: Alignment.center,
          child: Tx(k, style: ts(14, w: 700, lh: 21, c: on ? AppColors.white : AppColors.deep)),
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SvcField(
            label: 'المهمة',
            child: selInput(controller: _title, placeholder: 'روح أكلم خالد يشتري الفيلا'),
          ),
          const SizedBox(height: 12),
          SvcField(
            label: 'التفاصيل',
            child: FieldBox(
              controller: _body,
              placeholder: 'بكرك عندي كذا وكذا…',
              style: ts(15, lh: 21.5, c: AppColors.deep),
              height: 80,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: kSelDeco,
              multiline: true,
            ),
          ),
          const SizedBox(height: 12),
          Tx('الأولوية', style: ts(13, w: 600, lh: 19.5, c: AppColors.deep)),
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              Expanded(child: duo('مهم')),
              const SizedBox(width: 6),
              Expanded(child: duo('هادي')),
            ],
          ),
          const SizedBox(height: 6),
          Row(
            children: <Widget>[
              Expanded(child: duo('مؤجل')),
              const SizedBox(width: 6),
              const Expanded(child: SizedBox()),
            ],
          ),
          const SizedBox(height: 12),
          SvcField(
            label: 'المنبّه',
            child: DateTimeLocalField(value: remind, onChanged: (v) => setState(() => remind = v)),
          ),
          const SizedBox(height: 12),
          if (error.isNotEmpty) ...<Widget>[FormError(error), const SizedBox(height: 12)],
          AppButton(
            label: widget.busy ? 'جارٍ الحفظ…' : (widget.task != null ? 'حفظ التعديل' : 'حفظ المهمة'),
            onTap: _title.text.isEmpty || widget.busy ? null : _save,
          ),
        ],
      ),
    );
  }
}

/// Date-and-time field that opens the platform pickers; shows the value in
/// Arabic ("12 أكتوبر 2026، 4:05 م") or "اختر الموعد".
class DateTimeLocalField extends StatelessWidget {
  const DateTimeLocalField({super.key, required this.value, required this.onChanged});

  /// `YYYY-MM-DDTHH:mm` or empty.
  final String value;
  final ValueChanged<String> onChanged;

  static String _two(int n) => n.toString().padLeft(2, '0');

  String _shown() {
    final d = DateTime.tryParse(value);
    if (value.isEmpty || d == null) return 'اختر الموعد';
    return arDateTime(d);
  }

  Future<void> _pick(BuildContext context) async {
    final now = DateTime.tryParse(value) ?? DateTime.now();
    final platform = Theme.of(context).platform;
    DateTime? picked;
    if (platform == TargetPlatform.iOS || platform == TargetPlatform.macOS) {
      var temp = now;
      picked = await showCupertinoModalPopup<DateTime>(
        context: context,
        builder: (ctx) => Container(
          height: 300,
          color: CupertinoColors.systemBackground.resolveFrom(ctx),
          child: SafeArea(
            top: false,
            child: Column(
              children: <Widget>[
                Container(
                  height: 44,
                  color: CupertinoColors.secondarySystemBackground.resolveFrom(ctx),
                  alignment: AlignmentDirectional.centerEnd,
                  child: CupertinoButton(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    onPressed: () => Navigator.of(ctx).pop(temp),
                    child: const Text('تم', style: TextStyle(fontWeight: FontWeight.w600)),
                  ),
                ),
                Expanded(
                  child: CupertinoDatePicker(initialDateTime: now, onDateTimeChanged: (d) => temp = d),
                ),
              ],
            ),
          ),
        ),
      );
    } else {
      final date = await showDatePicker(context: context, initialDate: now, firstDate: DateTime(1900), lastDate: DateTime(2200));
      if (date == null || !context.mounted) return;
      final time = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(now));
      if (time == null) return;
      picked = DateTime(date.year, date.month, date.day, time.hour, time.minute);
    }
    if (picked == null) return;
    onChanged('${picked.year}-${_two(picked.month)}-${_two(picked.day)}T${_two(picked.hour)}:${_two(picked.minute)}');
  }

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: () => _pick(context),
      child: Container(
        height: 48,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        decoration: kSelDeco,
        child: Row(
          children: <Widget>[
            Expanded(
              child: Tx(_shown(), style: ts(15, lh: 21.5, c: value.isEmpty ? AppColors.muted : AppColors.ink), maxLines: 1, softWrap: false),
            ),
            const LucideIcon(Lu.calendarDays, size: 16, color: AppColors.ink),
          ],
        ),
      ),
    );
  }
}
