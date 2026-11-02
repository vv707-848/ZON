// QA F08 / F09 — dictation when the speech service is slow: a stop while
// the permission is pending or the service is starting cancels it for good
// (also when leaving the page or switching to another field), and a final
// result arriving after «stop» never replaces text typed by hand meanwhile.
//
// The phone's speech service cannot run in an automated test: this fake
// holds its answers until the test releases them. The real permission and
// speech service must still be tried on a phone.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/services/dictation.dart';
import 'package:zon/shared/widgets/dictation_field.dart';

/// A speech service whose permission and start answer only when released.
class SlowDictation implements DictationService {
  /// Hold the next prepare / start until the test completes them.
  bool holdPrepare = false;
  bool holdStart = false;
  final List<Completer<DictationAvailability>> prepares = <Completer<DictationAvailability>>[];
  final List<Completer<bool>> startCalls = <Completer<bool>>[];

  /// What happened, in order (start = called, started = really listening).
  final List<String> log = <String>[];
  int starts = 0;
  int stops = 0;
  int cancels = 0;

  /// The microphone is really recording.
  bool listening = false;

  /// Callbacks of every start, oldest first.
  final List<void Function(String words, bool isFinal)> sessions = <void Function(String words, bool isFinal)>[];
  void Function(String words, bool isFinal)? _words;
  VoidCallback? _end;

  @override
  Future<DictationAvailability> prepare() {
    log.add('prepare');
    if (!holdPrepare) return Future<DictationAvailability>.value(DictationAvailability.available);
    final c = Completer<DictationAvailability>();
    prepares.add(c);
    return c.future;
  }

  @override
  Future<bool> start({
    required void Function(String words, bool isFinal) onWords,
    required VoidCallback onEnd,
    required void Function(String code) onError,
  }) {
    starts++;
    log.add('start');
    _words = onWords;
    _end = onEnd;
    sessions.add(onWords);
    // like the plugin: the microphone is on once the start has answered
    void on(bool ok) {
      if (!ok) return;
      listening = true;
      log.add('started');
    }

    if (!holdStart) {
      on(true);
      return Future<bool>.value(true);
    }
    final c = Completer<bool>();
    startCalls.add(c);
    return c.future.then((ok) {
      on(ok);
      return ok;
    });
  }

  /// Stop keeps the session's callbacks: the final words may still come.
  @override
  Future<void> stop() async {
    stops++;
    log.add('stop');
    listening = false;
  }

  @override
  Future<void> cancel() async {
    cancels++;
    log.add('cancel');
    listening = false;
    _words = null;
    _end = null;
  }

  void hear(String words, {bool isFinal = false}) => _words?.call(words, isFinal);

  void end() {
    final e = _end;
    _words = null;
    _end = null;
    listening = false;
    e?.call();
  }
}

Finder get _mics => find.byKey(const ValueKey<String>('dictation-mic'));

/// One page with [count] description fields.
Future<List<TextEditingController>> _pump(WidgetTester tester, {int count = 1, String text = ''}) async {
  final cs = <TextEditingController>[for (var i = 0; i < count; i++) TextEditingController(text: text)];
  for (final c in cs) {
    addTearDown(c.dispose);
  }
  await tester.pumpWidget(
    MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          body: ListView(
            padding: const EdgeInsets.all(16),
            children: <Widget>[
              for (final c in cs)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: DictationField(controller: c, textStyle: const TextStyle(fontSize: 15)),
                ),
            ],
          ),
        ),
      ),
    ),
  );
  return cs;
}

Future<void> _tapMic(WidgetTester tester, [int i = 0]) async {
  await tester.tap(_mics.at(i));
  await tester.pump();
}

void main() {
  late SlowDictation fake;
  setUp(() {
    fake = SlowDictation();
    dictation = fake;
    resetDictationQueue();
  });

  group('F08 — a cancelled start never starts later', () {
    testWidgets('stop while the permission is pending: the recording never starts', (tester) async {
      fake.holdPrepare = true;
      await _pump(tester);
      await _tapMic(tester);
      await _tapMic(tester); // stop while waiting for the permission
      expect(find.textContaining('يستمع'), findsNothing);
      fake.prepares.single.complete(DictationAvailability.available);
      await tester.pump();
      expect(fake.starts, 0, reason: 'startsAfterStop: 0');
      expect(fake.listening, isFalse);
    });

    testWidgets('stop while the service is starting: the late start is stopped at once', (tester) async {
      fake.holdStart = true;
      await _pump(tester);
      await _tapMic(tester);
      expect(fake.starts, 1);
      await _tapMic(tester); // stop before the service answered
      fake.startCalls.single.complete(true);
      await tester.pump();
      expect(fake.log, <String>['prepare', 'start', 'started', 'cancel'], reason: 'the cancel reaches the service after the start');
      expect(fake.listening, isFalse);
      expect(find.textContaining('يستمع'), findsNothing);
      fake.hear('كلام متأخر');
      expect(find.text('كلام متأخر'), findsNothing);
    });

    testWidgets('leaving the page while the permission is pending or the service is starting', (tester) async {
      fake.holdPrepare = true;
      await _pump(tester);
      await _tapMic(tester);
      await tester.pumpWidget(const SizedBox.shrink());
      fake.prepares.single.complete(DictationAvailability.available);
      await tester.pump();
      expect(fake.starts, 0);

      fake
        ..holdPrepare = false
        ..holdStart = true;
      await _pump(tester);
      await _tapMic(tester);
      await tester.pumpWidget(const SizedBox.shrink());
      fake.startCalls.single.complete(true);
      await tester.pump();
      expect(fake.listening, isFalse, reason: 'nothing keeps recording after the page is gone');
      expect(fake.log.last, 'cancel');
    });

    testWidgets('switching to another field while the first is starting: only the second dictates', (tester) async {
      fake.holdStart = true;
      final cs = await _pump(tester, count: 2);
      await _tapMic(tester, 0);
      await _tapMic(tester, 1); // the second field takes over
      fake.startCalls.first.complete(true); // the first start answers late
      await tester.pump();
      expect(fake.startCalls, hasLength(2), reason: 'the second start waits for the first to be cancelled');
      fake.startCalls.last.complete(true);
      await tester.pump(); // the answer arrives
      await tester.pump(); // the field shows it
      expect(fake.log, <String>['prepare', 'start', 'started', 'cancel', 'prepare', 'start', 'started']);
      expect(find.textContaining('يستمع'), findsOneWidget);
      fake.hear('شقة قرب المترو');
      await tester.pump();
      expect(cs[0].text, isEmpty);
      expect(cs[1].text, 'شقة قرب المترو');
    });
  });

  group('F09 — late words after «stop»', () {
    testWidgets('a late final result does not replace newer text typed by hand', (tester) async {
      final c = (await _pump(tester, text: 'الوصف الأصلي')).single;
      await _tapMic(tester);
      fake.hear('فيلا قرب المدرسة');
      await tester.pump();
      expect(c.text, 'الوصف الأصلي فيلا قرب المدرسة');
      await _tapMic(tester); // stop: the final words are still on their way
      expect(fake.stops, 1);
      await tester.enterText(find.byType(EditableText), 'وصف جديد كتبته بنفسي');
      await tester.pump();
      fake.hear('فيلا قرب المدرسة', isFinal: true);
      fake.end();
      await tester.pump();
      expect(c.text, 'وصف جديد كتبته بنفسي');
    });

    testWidgets('without typing, the final words are kept — once, after the earlier text', (tester) async {
      final c = (await _pump(tester, text: 'شقة')).single;
      await _tapMic(tester);
      fake.hear('قرب');
      await _tapMic(tester);
      fake.hear('قرب الحديقة', isFinal: true);
      fake.end();
      await tester.pump();
      expect(c.text, 'شقة قرب الحديقة');
      fake.hear('قرب الحديقة العامة'); // anything after the end is ignored
      await tester.pump();
      expect(c.text, 'شقة قرب الحديقة');
    });

    testWidgets('a new session ignores the late words of the previous one', (tester) async {
      final c = (await _pump(tester)).single;
      await _tapMic(tester);
      fake.hear('أرض');
      await _tapMic(tester); // stop
      await _tapMic(tester); // a new session at once
      fake.hear('شقة');
      await tester.pump();
      expect(c.text, 'أرض شقة');
      fake.sessions.first('أرض تجارية', true); // the first session's final, late
      await tester.pump();
      expect(c.text, 'أرض شقة');
    });
  });
}
