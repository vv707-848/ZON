// Screenshot harness (v3) — not part of the app.
//
// Runs the zon app inside the fshot embedder and follows a JSON plan
// (FSHOT_PLAN). Every scenario starts from an EMPTY device, then:
//   * `setup` — creates its demo account(s) and data through the app's own
//     code (LocalAuth, store, billing, ownerCases, notifications), so the
//     screens show what the app itself would save;
//   * `route` — the page to open;
//   * `ops`   — taps, typing, scrolling and captures.
//
// Stand-ins used ONLY here (never in the app):
//   * ShotDictation — the phone's speech service cannot run in this engine;
//     a scripted service shows the listening state and the words heard;
//   * the gallery — returns a photo file from the project's sample images
//     (the app copies it into its own photo folder, as on a phone);
//   * 'payHold' / 'payRelease' — hold the simulated gateway's answer to show
//     the dialog while a payment runs.
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zon/app/router.dart';
import 'package:zon/app/zon_app.dart';
import 'package:zon/core/services/dictation.dart';
import 'package:zon/core/services/photo_picker.dart';
import 'package:zon/core/services/photo_store.dart';
import 'package:zon/core/theme/typography.dart';
import 'package:zon/core/utils/clock.dart';
import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/store.dart';

const BasicMessageChannel<String> _ch = BasicMessageChannel<String>('fshot', StringCodec());

/// Password of every demo account (they live only in the harness run).
const String kDemoPassword = 'demo-pass-2026';

Future<void> send(String m) async {
  await _ch.send(m);
}

Future<void> log(String m) => send('log:$m');

Future<void> frames([int n = 3]) async {
  for (var i = 0; i < n; i++) {
    WidgetsBinding.instance.scheduleFrame();
    await WidgetsBinding.instance.endOfFrame;
  }
}

Future<void> settle([int ms = 250]) async {
  await frames();
  await Future<void>.delayed(Duration(milliseconds: ms));
  await frames();
  await Future<void>.delayed(const Duration(milliseconds: 120));
}

// ------------------------------------------------------------ stand-ins

/// Scripted speech service for the screenshots (see the file comment).
class ShotDictation implements DictationService {
  DictationAvailability availability = DictationAvailability.available;
  void Function(String words, bool isFinal)? _words;
  VoidCallback? _end;

  @override
  Future<DictationAvailability> prepare() async => availability;

  @override
  Future<bool> start({
    required void Function(String words, bool isFinal) onWords,
    required VoidCallback onEnd,
    required void Function(String code) onError,
  }) async {
    _words = onWords;
    _end = onEnd;
    return true;
  }

  @override
  Future<void> stop() async {
    final end = _end;
    _words = null;
    _end = null;
    end?.call();
  }

  @override
  Future<void> cancel() async {
    _words = null;
    _end = null;
  }

  void hear(String words, bool isFinal) {
    _words?.call(words, isFinal);
    if (isFinal) unawaited(stop());
  }
}

final ShotDictation shotDictation = ShotDictation();

/// The phone's text size setting as the app would receive it (null = the
/// engine's own value). The app itself clamps it to 1.0–1.2.
final ValueNotifier<double?> textScale = ValueNotifier<double?>(null);

/// Gives the app the phone's text size setting (MediaQuery above the app,
/// which WidgetsApp takes as its platform data).
class ScaleHost extends StatelessWidget {
  const ScaleHost({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => ValueListenableBuilder<double?>(
    valueListenable: textScale,
    builder: (context, v, _) => v == null
        ? child
        : MediaQuery(
            data: MediaQueryData.fromView(View.of(context)).copyWith(textScaler: TextScaler.linear(v)),
            child: child,
          ),
  );
}

PhotoPickResult nextPick = const PhotoPickResult(<String>[]);

/// Holds the simulated payment of the current scenario ('payHold').
Completer<void>? _payGate;

// ------------------------------------------------------------ finding

int _pointer = 1;

Future<void> tapAt(Offset p) async {
  final id = _pointer++;
  GestureBinding.instance.handlePointerEvent(PointerDownEvent(pointer: id, position: p));
  await Future<void>.delayed(const Duration(milliseconds: 40));
  GestureBinding.instance.handlePointerEvent(PointerUpEvent(pointer: id, position: p));
  await settle();
}

Future<void> dragFrom(Offset p, Offset delta) async {
  final id = _pointer++;
  GestureBinding.instance.handlePointerEvent(PointerDownEvent(pointer: id, position: p));
  const steps = 8;
  for (var i = 1; i <= steps; i++) {
    await Future<void>.delayed(const Duration(milliseconds: 16));
    GestureBinding.instance.handlePointerEvent(PointerMoveEvent(pointer: id, position: p + delta * (i / steps), delta: delta / steps.toDouble()));
  }
  GestureBinding.instance.handlePointerEvent(PointerUpEvent(pointer: id, position: p + delta));
  await settle(400);
}

/// Visits the elements of the CURRENT page (and of what is outside any
/// page); pages under the top one are skipped.
void _visitAll(void Function(Element e) f) {
  void visit(Element e) {
    final w = e.widget;
    if (w is Offstage && w.offstage) return;
    if (e is StatefulElement && e.state is ScrollableState ||
        w is RichText ||
        w is Semantics ||
        w is TextField ||
        (e is StatefulElement && e.state is EditableTextState) ||
        w.key is ValueKey<String>) {
      final route = ModalRoute.of(e);
      if (route != null && !route.isCurrent) return;
    }
    f(e);
    e.visitChildren(visit);
  }

  WidgetsBinding.instance.rootElement?.visitChildren(visit);
}

/// Elements of laid-out paragraphs whose text matches [text], in tree order.
List<Element> findTextElements(String text, {bool exact = true}) {
  final out = <Element>[];
  _visitAll((e) {
    final ro = e.renderObject;
    if (e.widget is RichText && ro is RenderParagraph && ro.attached && ro.hasSize) {
      final t = ro.text.toPlainText();
      if (exact ? t.trim() == text : t.contains(text)) out.add(e);
    }
  });
  return out;
}

Offset centerOfElement(Element e) {
  final b = e.renderObject! as RenderBox;
  return b.localToGlobal(b.size.center(Offset.zero));
}

Element? _byKey(String key) {
  Element? found;
  _visitAll((e) {
    if (found == null && e.widget.key == ValueKey<String>(key) && e.renderObject is RenderBox) found = e;
  });
  return found;
}

Element? _bySemanticsLabel(String label) {
  Element? found;
  _visitAll((e) {
    final w = e.widget;
    if (found == null && w is Semantics && w.properties.label == label && e.renderObject is RenderBox) found = e;
  });
  return found;
}

List<EditableTextState> _allEditables() {
  final out = <EditableTextState>[];
  _visitAll((e) {
    if (e is StatefulElement && e.state is EditableTextState) out.add(e.state as EditableTextState);
  });
  return out;
}

/// TextFields whose hint is [hint], in tree order.
List<StatefulElement> _fieldsWithHint(String hint) {
  final out = <StatefulElement>[];
  _visitAll((e) {
    final w = e.widget;
    if (w is TextField && w.decoration?.hintText == hint && e is StatefulElement) out.add(e);
  });
  return out;
}

/// Sets the text of a TextField as typing would (controller + onChanged).
void _setField(TextField field, String value) {
  field.controller!.value = TextEditingValue(
    text: value,
    selection: TextSelection.collapsed(offset: value.length),
  );
  field.onChanged?.call(value);
}

ScrollableState? primaryScrollable() {
  ScrollableState? best;
  double bestH = 0;
  _visitAll((e) {
    if (e is StatefulElement && e.state is ScrollableState) {
      final s = e.state as ScrollableState;
      final ro = e.renderObject;
      if (s.axisDirection == AxisDirection.down && ro is RenderBox && ro.hasSize && ro.size.height > bestH) {
        best = s;
        bestH = ro.size.height;
      }
    }
  });
  return best;
}

// ------------------------------------------------------------ setup

String _str(Map<String, dynamic> m, String k, String d) => m[k] as String? ?? d;
double _num(Map<String, dynamic> m, String k, double d) => (m[k] as num?)?.toDouble() ?? d;

/// Runs [f] with the app clock moved [minutes] into the past.
T _at<T>(int minutes, T Function() f) {
  final saved = appClock;
  final base = DateTime.now().subtract(Duration(minutes: minutes));
  appClock = () => base;
  try {
    return f();
  } finally {
    appClock = saved;
  }
}

Future<void> runSetup(List<dynamic> steps, String name) async {
  for (final raw in steps) {
    final step = raw as List<dynamic>;
    final kind = step[0] as String;
    final arg = step.length > 1 && step[1] is Map ? (step[1] as Map).cast<String, dynamic>() : <String, dynamic>{};
    switch (kind) {
      case 'account':
        // ['account', {role, name, username, city, minutesAgo}]
        final role = _str(arg, 'role', 'broker');
        final r = _at(
          (arg['minutesAgo'] as num?)?.toInt() ?? 0,
          () => LocalAuth.signUp(
            name: _str(arg, 'name', 'حساب تجريبي'),
            phone: _str(arg, 'phone', '0551234567'),
            username: _str(arg, 'username', 'demo.$role'),
            password: kDemoPassword,
            role: role,
            city: _str(arg, 'city', 'الرياض'),
          ),
        );
        if (!r.ok) await log('$name: sign up failed: ${r.error}');
      case 'signin':
        final r = LocalAuth.signIn(step[1] as String, kDemoPassword);
        if (!r.ok) await log('$name: sign in failed: ${r.error}');
      case 'signout':
        LocalAuth.signOut();
      case 'plan':
        // ['plan', planId, minutesAgo?]
        _at(step.length > 2 ? (step[2] as num).toInt() : 0, () => billing.startPeriod(step[1] as String, paymentRef: 'SIM-DEMO'));
      case 'listing':
        final featuredEnded = arg['featuredEndedMinutesAgo'] as num?;
        store.saveListing(
          Listing(
            id: _str(arg, 'id', 'demo-l1'),
            kind: 'unit',
            title: _str(arg, 'title', 'فيلا مودرن بحي الملقا'),
            purpose: _str(arg, 'purpose', 'sale'),
            city: _str(arg, 'city', 'الرياض'),
            neighborhood: _str(arg, 'hood', 'الملقا'),
            propertyType: _str(arg, 'type', 'villa'),
            source: _str(arg, 'source', 'broker'),
            price: _num(arg, 'price', 3200000),
            rooms: _num(arg, 'rooms', 5),
            baths: _num(arg, 'baths', 5),
            area: _num(arg, 'area', 420),
            images: ((arg['images'] as List<dynamic>?) ?? <dynamic>['/listings/villa-1.jpg']).cast<String>(),
            description: _str(arg, 'description', 'فيلا مودرن بتشطيب ممتاز، مدخلين ومسبح وقريبة من المدارس.'),
            advertiser: store.readSession()?.name ?? 'حساب تجريبي',
            advertiserRole: store.readSession()?.role ?? 'broker',
            license: _str(arg, 'license', '7200123456'),
            active: true,
            premiumUntil: featuredEnded == null ? null : DateTime.now().subtract(Duration(minutes: featuredEnded.toInt())).toIso8601String(),
          ),
        );
      case 'project':
        // ['project', {id, title, delivery, units: [{name, license, verified, price, image}]}]
        final units = <UnitModel>[];
        var i = 0;
        for (final u in (arg['units'] as List<dynamic>? ?? const <dynamic>[]).cast<Map<dynamic, dynamic>>()) {
          i++;
          final m = u.cast<String, dynamic>();
          units.add(
            UnitModel(
              id: '${_str(arg, 'id', 'demo-p1')}-u$i',
              name: _str(m, 'name', 'وحدة $i'),
              rooms: _num(m, 'rooms', 4),
              baths: _num(m, 'baths', 4),
              halls: 1,
              area: _num(m, 'area', 260),
              price: _num(m, 'price', 1850000),
              sold: false,
              image: _str(m, 'image', '/listings/apartment-1.jpg'),
              description: _str(m, 'description', 'وحدة بتشطيب فاخر وإطلالة على الحديقة.'),
              propertyType: _str(m, 'type', 'apartment'),
              license: m['license'] as String?,
              licenseVerified: m['verified'] as bool?,
            ),
          );
        }
        store.saveListing(
          Listing(
            id: _str(arg, 'id', 'demo-p1'),
            kind: 'project',
            title: _str(arg, 'title', 'مشروع النخبة السكني'),
            purpose: 'sale',
            city: 'الرياض',
            neighborhood: _str(arg, 'hood', 'النرجس'),
            propertyType: 'apartment',
            source: 'developer',
            price: units.isEmpty ? 0 : units.map((u) => u.price).reduce((a, b) => a < b ? a : b),
            rooms: 4,
            baths: 4,
            area: 260,
            images: ((arg['images'] as List<dynamic>?) ?? <dynamic>['/listings/project-1.jpg', '/listings/project-2.jpg']).cast<String>(),
            description: _str(arg, 'description', 'مشروع سكني متكامل الخدمات قريب من الطرق الرئيسية والمدارس.'),
            advertiser: store.readSession()?.name ?? 'شركة تطوير',
            advertiserRole: 'developer',
            license: _str(arg, 'license', ''),
            delivery: _str(arg, 'delivery', 'ready'),
            units: units,
            active: true,
          ),
        );
      case 'quota':
        // ['quota', {kind: featured|urgentListing|urgentRequest, id, minutesAgo}]
        final k = BoostKind.values.byName(_str(arg, 'kind', 'featured'));
        final out = _at((arg['minutesAgo'] as num?)?.toInt() ?? 0, () => billing.useQuota(k, _str(arg, 'id', 'demo-l1')));
        if (out != BoostOutcome.applied) await log('$name: quota $k → $out');
      case 'readNote':
        // ['readNote', part of the title]
        for (final n in notifications.list()) {
          if (n.title.contains(step[1] as String)) notifications.markRead(n.id);
        }
      case 'request':
        final s = store.readSession();
        _at((arg['minutesAgo'] as num?)?.toInt() ?? 0, () {
          store.saveRequest(
            RequestItem(
              id: _str(arg, 'id', 'demo-r1'),
              purpose: _str(arg, 'purpose', 'sale'),
              city: _str(arg, 'city', 'الرياض'),
              neighborhoods: ((arg['hoods'] as List<dynamic>?) ?? <dynamic>['الملقا']).cast<String>(),
              types: ((arg['types'] as List<dynamic>?) ?? <dynamic>['villa']).cast<String>(),
              priceMin: _num(arg, 'min', 2000000),
              priceMax: _num(arg, 'max', 3000000),
              areaMin: _num(arg, 'areaMin', 300),
              areaMax: _num(arg, 'areaMax', 500),
              roomsMin: _num(arg, 'roomsMin', 4),
              roomsMax: _num(arg, 'roomsMax', 6),
              description: _str(arg, 'description', 'عائلة تبحث عن فيلا قريبة من المدارس.'),
              author: s?.name ?? 'حساب تجريبي',
              role: s?.role ?? 'seeker',
              urgent: arg['urgent'] as bool?,
            ),
          );
        });
      case 'wish':
        store.saveWish(
          Wish(
            city: _str(arg, 'city', 'الرياض'),
            hoods: ((arg['hoods'] as List<dynamic>?) ?? <dynamic>['الملقا']).cast<String>(),
            types: ((arg['types'] as List<dynamic>?) ?? <dynamic>['villa']).cast<String>(),
            budget: _str(arg, 'budget', ''),
            text: _str(arg, 'text', 'فيلا عوائل قريبة من المدارس'),
          ),
        );
      case 'ownerCase':
        // ['ownerCase', {stage: submitted|approved|paid|published, listingId}]
        final stage = _str(arg, 'stage', 'submitted');
        var c = ownerCases.submitContract(deedNo: _str(arg, 'deed', '310115004512'));
        if (stage != 'submitted') c = ownerCases.approveContract(c.id, reference: 'SIM-CONTRACT-1042');
        if (stage == 'paid' || stage == 'published') await ownerCases.payLicenceFee(c.id);
        if (stage == 'published') ownerCases.markPublished(c.id, _str(arg, 'listingId', 'demo-owner-l1'));
      case 'note':
        // ['note', {kind, title, body, target, minutesAgo, read, demo}]
        final key = _str(arg, 'key', 'shot:${arg['title']}');
        _at((arg['minutesAgo'] as num?)?.toInt() ?? 0, () {
          notifications.add(
            kind: _str(arg, 'kind', NoteKind.message),
            title: _str(arg, 'title', ''),
            body: _str(arg, 'body', ''),
            target: _str(arg, 'target', '/'),
            key: key,
            demo: arg['demo'] as bool? ?? true,
          );
        });
        if (arg['read'] == true) {
          for (final n in notifications.list()) {
            if (n.key == key) notifications.markRead(n.id);
          }
        }
      case 'sync':
        _at((step.length > 1 ? step[1] as num : 0).toInt(), notifications.sync);
      case 'tasks':
        store.saveTasks(<Task>[
          for (final t in (step[1] as List<dynamic>).cast<Map<dynamic, dynamic>>())
            Task(
              id: t['id'] as String,
              title: t['title'] as String,
              body: t['body'] as String?,
              pri: t['pri'] as String? ?? 'مهم',
              remindAt: t['remindAt'] as String?,
            ),
        ]);
      case 'dictation':
        shotDictation.availability = DictationAvailability.values.byName(step[1] as String);
      case 'gallery':
        nextPick = PhotoPickResult((step[1] as List<dynamic>).cast<String>(), error: step.length > 2 ? step[2] as String? : null);
      case 'prefs':
        // ['prefs', {key: value}] — data saved by an older version, then the
        // app starts on it (its migrations run)
        // ignore: invalid_use_of_visible_for_testing_member
        SharedPreferences.setMockInitialValues(arg.map((k, v) => MapEntry(k, v as Object)));
        await Store.init();
      case 'listingJson':
        // ['listingJson', {...}] — a listing exactly as given (any field)
        store.saveListing(Listing.fromJson(arg.cast<String, Object?>()));
      default:
        await log('$name: unknown setup step $kind');
    }
  }
}

// ------------------------------------------------------------ ops

Future<void> runOps(List<dynamic> ops, String name) async {
  for (final raw in ops) {
    final op = raw as List<dynamic>;
    final kind = op[0] as String;
    switch (kind) {
      case 'wait':
        await settle((op[1] as num).toInt());
      case 'tapText':
        // ['tapText', text, 'exact'|'contains', index | 'last']
        final hits = findTextElements(op[1] as String, exact: op.length < 3 || op[2] != 'contains');
        if (hits.isEmpty) {
          await log('$name: text not found: ${op[1]}');
          continue;
        }
        final which = op.length > 3 ? op[3] : 0;
        final e = which == 'last' ? hits.last : hits[(which as num).toInt().clamp(0, hits.length - 1)];
        await Scrollable.ensureVisible(e, alignment: 0.5);
        await settle(150);
        await tapAt(centerOfElement(e));
      case 'tapKey':
        final e = _byKey(op[1] as String);
        if (e == null) {
          await log('$name: key not found: ${op[1]}');
          continue;
        }
        await Scrollable.ensureVisible(e, alignment: 0.5);
        await settle(150);
        await tapAt(centerOfElement(e));
      case 'tapSem':
        final e = _bySemanticsLabel(op[1] as String);
        if (e == null) {
          await log('$name: semantics label not found: ${op[1]}');
          continue;
        }
        await tapAt(centerOfElement(e));
      case 'tapAt':
        await tapAt(Offset((op[1] as num).toDouble(), (op[2] as num).toDouble()));
      case 'drag':
        await dragFrom(Offset((op[1] as num).toDouble(), (op[2] as num).toDouble()), Offset((op[3] as num).toDouble(), (op[4] as num).toDouble()));
      case 'scrollTo':
        final s = primaryScrollable();
        if (s != null) {
          final pos = s.position;
          final target = (op[1] as num).toDouble();
          pos.jumpTo(target < 0 ? pos.maxScrollExtent : target.clamp(0, pos.maxScrollExtent));
        }
        await settle();
      case 'scrollToText':
        // ['scrollToText', text, alignment, index]
        final hits = findTextElements(op[1] as String, exact: false);
        final at = op.length > 3 ? (op[3] as num).toInt() : 0;
        if (hits.length > at) {
          await Scrollable.ensureVisible(hits[at], alignment: (op.length > 2 ? (op[2] as num).toDouble() : 0.2));
        } else {
          await log('$name: text not found for scroll: ${op[1]}');
        }
        await settle();
      case 'shot':
        await settle(op.length > 2 ? (op[2] as num).toInt() : 250);
        await send('capture:$name${(op[1] as String).isEmpty ? '' : '-${op[1]}'}');
      case 'fillHint':
        // ['fillHint', hint, value, index]
        final fields = _fieldsWithHint(op[1] as String);
        final idx = op.length > 3 ? (op[3] as num).toInt() : 0;
        if (fields.length <= idx) {
          await log('$name: field with hint not found: ${op[1]}');
          continue;
        }
        _setField(fields[idx].widget as TextField, op[2] as String);
        await settle();
      case 'fillNth':
        // ['fillNth', index of the EditableText in tree order, value]
        final all = _allEditables();
        final idx = (op[1] as num).toInt();
        if (all.length <= idx) {
          await log('$name: only ${all.length} text fields');
          continue;
        }
        final c = all[idx].widget.controller;
        final v = op[2] as String;
        c.value = TextEditingValue(
          text: v,
          selection: TextSelection.collapsed(offset: v.length),
        );
        TextField? tf;
        all[idx].context.visitAncestorElements((a) {
          if (a.widget is TextField) {
            tf = a.widget as TextField;
            return false;
          }
          return true;
        });
        tf?.onChanged?.call(v);
        await settle();
      case 'fillAfter':
        // ['fillAfter', label, value, n]: the n-th text field after the label
        final label = findTextElements(op[1] as String);
        if (label.isEmpty) {
          await log('$name: label not found: ${op[1]}');
          continue;
        }
        final skip = op.length > 3 ? (op[3] as num).toInt() : 0;
        var seen = false;
        var count = 0;
        EditableTextState? target;
        _visitAll((e) {
          if (target != null) return;
          if (identical(e, label.first)) seen = true;
          if (seen && e is StatefulElement && e.state is EditableTextState) {
            if (count == skip) target = e.state as EditableTextState;
            count++;
          }
        });
        if (target == null) {
          await log('$name: no field after ${op[1]}');
          continue;
        }
        final v = op[2] as String;
        target!.widget.controller.value = TextEditingValue(
          text: v,
          selection: TextSelection.collapsed(offset: v.length),
        );
        TextField? owner;
        target!.context.visitAncestorElements((a) {
          if (a.widget is TextField) {
            owner = a.widget as TextField;
            return false;
          }
          return true;
        });
        owner?.onChanged?.call(v);
        await settle();
      case 'textScale':
        textScale.value = (op[1] as num?)?.toDouble();
        await settle(300);
      case 'dictate':
        // ['dictate', words, final]
        shotDictation.hear(op[1] as String, op.length > 2 && op[2] == true);
        await settle();
      case 'gallery':
        nextPick = PhotoPickResult((op[1] as List<dynamic>).cast<String>(), error: op.length > 2 ? op[2] as String? : null);
      case 'switch':
        // ['switch', username, role?, name?] — another account signs in now
        LocalAuth.signOut();
        final u = op[1] as String;
        if (store.accountByUsername(u) == null) {
          final r = LocalAuth.signUp(
            name: op.length > 3 ? op[3] as String : 'حساب تجريبي',
            phone: '0551234567',
            username: u,
            password: kDemoPassword,
            role: op.length > 2 ? op[2] as String : 'broker',
            city: 'الرياض',
          );
          if (!r.ok) await log('$name: switch sign up failed: ${r.error}');
        } else {
          final r = LocalAuth.signIn(u, kDemoPassword);
          if (!r.ok) await log('$name: switch sign in failed: ${r.error}');
        }
        await settle(200);
      case 'copyFile':
        // ['copyFile', from, to] — e.g. a photo the gallery hands back
        File(op[1] as String).copySync(op[2] as String);
      case 'deleteFile':
        // ['deleteFile', path] — the system cleans the gallery's temporary file
        final f = File(op[1] as String);
        if (f.existsSync()) f.deleteSync();
      case 'payHold':
        // the simulated gateway answers only after 'payRelease'
        _payGate = Completer<void>();
        // ignore: invalid_use_of_visible_for_testing_member
        PaymentSimulator.gate = _payGate!.future;
      case 'payRelease':
        _payGate?.complete();
        _payGate = null;
        // ignore: invalid_use_of_visible_for_testing_member
        PaymentSimulator.gate = null;
        await settle(300);
      case 'go':
        Nav.key.currentState!.pushNamed(op[1] as String, arguments: const NavArgs(animate: false));
        await settle(500);
      case 'back':
        Nav.key.currentState?.maybePop();
        await settle(400);
      case 'size':
        await send('metrics:${op[1]},${op[2]},${op.length > 3 ? op[3] : 2}');
        await settle(300);
      default:
        await log('unknown op $kind');
    }
  }
}

Future<void> main() => runHarness();

/// The app's font is the phone's system font (SF on iPhone), which this
/// engine does not have and which may not be copied: the screenshots use the
/// same licensed stand-ins as the tests (the app's `test/fonts/`, folder given
/// by ZON_FONTS_DIR) — Inter for Latin and digits, Noto Sans Arabic for Arabic.
Future<void> loadStandInFonts() async {
  final dir = Platform.environment['ZON_FONTS_DIR'] ?? '../../test/fonts';
  const weights = <String>['Regular', 'Medium', 'SemiBold', 'Bold'];
  Future<ByteData> read(String file) async => ByteData.sublistView(await File('$dir/$file').readAsBytes());
  for (final family in const <String>[kFontFamily, kDisplayFontFamily]) {
    final latin = FontLoader(family);
    for (final w in weights) {
      latin.addFont(read('Inter-$w.otf'));
    }
    await latin.load();
  }
  final arabic = FontLoader('ZonArabicStandIn');
  for (final w in weights) {
    arabic.addFont(read('NotoSansArabic-$w.ttf'));
  }
  await arabic.load();
  fontFallback = const <String>['ZonArabicStandIn'];
}

/// Runs the plan. [before] runs after binding init (e.g. to load fonts).
Future<void> runHarness({Future<void> Function()? before}) async {
  WidgetsFlutterBinding.ensureInitialized();
  await loadStandInFonts();
  if (before != null) await before();
  FlutterError.onError = (details) {
    final text = details.toString();
    send('log:FLUTTER ERROR: ${text.length > 2500 ? text.substring(0, 2500) : text}');
  };
  debugPrint = (String? message, {int? wrapWidth}) {
    if (message != null) send('log:$message');
  };
  dictation = shotDictation;
  photoPicker = ({bool multiple = true}) async {
    final r = nextPick;
    nextPick = const PhotoPickResult(<String>[]);
    return r;
  };
  // photos picked in a scenario are kept like on a phone (app folder)
  await PhotoStore.init(Directory.systemTemp.createTempSync('zon-shots-support-'));
  final planPath = Platform.environment['FSHOT_PLAN'] ?? '/tmp/shots/plan.json';
  final plan = jsonDecode(File(planPath).readAsStringSync()) as Map<String, dynamic>;
  // ignore: invalid_use_of_visible_for_testing_member
  SharedPreferences.setMockInitialValues(<String, Object>{});
  await Store.init();
  runApp(const ScaleHost(child: ZonApp()));
  await settle(600);
  for (final raw in plan['scenarios'] as List<dynamic>) {
    final sc = raw as Map<String, dynamic>;
    final name = sc['name'] as String;
    try {
      final size = (sc['size'] as List<dynamic>?) ?? plan['size'] as List<dynamic>? ?? <dynamic>[390, 844, 2];
      await send('metrics:${size[0]},${size[1]},${size.length > 2 ? size[2] : 2}');
      // an empty device for every scenario
      Nav.key.currentState!.pushNamedAndRemoveUntil('/legal/terms', (_) => false, arguments: const NavArgs(animate: false));
      await settle(200);
      // ignore: invalid_use_of_visible_for_testing_member
      SharedPreferences.setMockInitialValues(<String, Object>{});
      await Store.init();
      appClock = DateTime.now;
      textScale.value = null;
      shotDictation.availability = DictationAvailability.available;
      nextPick = const PhotoPickResult(<String>[]);
      _payGate = null;
      // ignore: invalid_use_of_visible_for_testing_member
      PaymentSimulator.gate = null;
      await runSetup((sc['setup'] as List<dynamic>?) ?? const <dynamic>[], name);
      Nav.key.currentState!.pushNamedAndRemoveUntil(sc['route'] as String? ?? '/', (_) => false, arguments: const NavArgs(animate: false));
      await settle(500);
      await runOps(
        (sc['ops'] as List<dynamic>?) ??
            <dynamic>[
              <dynamic>['shot', ''],
            ],
        name,
      );
    } catch (e, st) {
      await log('$name failed: $e\n$st');
    }
  }
  await send('exit');
}
