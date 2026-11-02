// V01 — the dictation mic inside the description field (semantic search on
// offers and requests, and the wish of the matching assistant).
//
// The phone's speech service cannot run in an automated test, so a fake
// service stands in for it here. Real dictation must be tried on a phone.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/services/dictation.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/dictation_field.dart';

import 'support/test_env.dart';

class FakeDictation implements DictationService {
  DictationAvailability availability = DictationAvailability.available;
  bool startOk = true;
  void Function(String words, bool isFinal)? _words;
  VoidCallback? _end;
  void Function(String code)? _error;
  int starts = 0;
  int stops = 0;
  int cancels = 0;

  bool get listening => _words != null;

  @override
  Future<DictationAvailability> prepare() async => availability;

  @override
  Future<bool> start({
    required void Function(String words, bool isFinal) onWords,
    required VoidCallback onEnd,
    required void Function(String code) onError,
  }) async {
    starts++;
    if (!startOk) return false;
    _words = onWords;
    _end = onEnd;
    _error = onError;
    return true;
  }

  @override
  Future<void> stop() async {
    stops++;
    final end = _end;
    _clear();
    end?.call();
  }

  @override
  Future<void> cancel() async {
    cancels++;
    _clear();
  }

  void _clear() {
    _words = null;
    _end = null;
    _error = null;
  }

  void hear(String words, {bool isFinal = false}) {
    _words?.call(words, isFinal);
    if (isFinal) {
      final end = _end;
      _clear();
      end?.call();
    }
  }

  void fail(String code) {
    final err = _error;
    final end = _end;
    _clear();
    err?.call(code);
    end?.call();
  }
}

Finder get _mic => find.byKey(const ValueKey<String>('dictation-mic'));

/// The mic is inside the frame of its DictationField, at least 44×44 points,
/// and does not overlap the text area.
void expectMicInside(WidgetTester tester, {int index = 0}) {
  final frame = tester.getRect(find.byType(DictationField).at(index));
  final mic = tester.getRect(_mic.at(index));
  final text = tester.getRect(find.descendant(of: find.byType(DictationField).at(index), matching: find.byType(EditableText)));
  expect(mic.width >= 44 && mic.height >= 44, isTrue, reason: 'touch target ${mic.size}');
  expect(frame.contains(mic.topLeft) && frame.contains(mic.bottomRight - const Offset(0.5, 0.5)), isTrue, reason: 'mic $mic inside frame $frame');
  // touching edges is fine (half a point for rounding); running under is not
  expect(text.deflate(0.5).overlaps(mic), isFalse, reason: 'text area $text must not run under the mic $mic');
}

/// A tap at the centre of [f] reaches it (on screen, not covered).
bool _reachable(WidgetTester tester, Finder f) {
  final target = tester.renderObject(f);
  return tester.hitTestOnBinding(tester.getCenter(f)).path.any((e) => e.target == target);
}

Future<void> _choose(WidgetTester tester, String current, String option, {int index = 0}) async {
  await tapText(tester, current, index: index);
  await tapText(tester, option);
}

/// The description field of a minimal page (behaviour tests).
Future<TextEditingController> _pumpField(WidgetTester tester, {bool enabled = true, String text = ''}) async {
  final c = TextEditingController(text: text);
  addTearDown(c.dispose);
  await tester.pumpWidget(
    MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          body: Padding(
            padding: const EdgeInsets.all(16),
            child: DictationField(controller: c, enabled: enabled, textStyle: const TextStyle(fontSize: 15)),
          ),
        ),
      ),
    ),
  );
  return c;
}

void main() {
  late FakeDictation fake;
  setUp(() {
    fake = FakeDictation();
    dictation = fake;
    resetDictationQueue();
  });

  group('placement — inside the description field', () {
    testWidgets('semantic search on offers: disabled until city, district and type', (tester) async {
      await freshDevice();
      signUp('v01.offers', role: 'broker');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      await pumpApp(tester, '/account/search');
      expect(_mic, findsOneWidget);
      expectMicInside(tester);
      await tester.tap(_mic);
      await tester.pump();
      expect(fake.starts, 0, reason: 'disabled before the three choices');
      await _choose(tester, 'اختر', 'الرياض');
      await _choose(tester, 'اختر', 'الملقا');
      await _choose(tester, 'اختر', 'فيلا');
      expectMicInside(tester);
      await tester.tap(_mic);
      await tester.pump();
      expect(fake.starts, 1);
    });

    testWidgets('semantic search on requests (broker tab)', (tester) async {
      await freshDevice();
      signUp('v01.requests', role: 'broker');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      await pumpApp(tester, '/account/search');
      await tapText(tester, 'بحث طلبات');
      expect(_mic, findsOneWidget);
      expectMicInside(tester);
    });

    testWidgets('matching assistant — the wish description', (tester) async {
      await freshDevice();
      signUp('v01.match', role: 'seeker');
      await pumpApp(tester, '/account/matching');
      expect(_mic, findsOneWidget);
      expectMicInside(tester);
      await tester.tap(_mic);
      await tester.pump();
      expect(fake.starts, 1);
    });

    // §13: phones 360, 390 and 430 wide — no overflow and no button covered
    // by the text or the keyboard, also with the larger text setting.
    for (final place in <String>['offers', 'requests', 'matching']) {
      testWidgets('$place at 360, 390 and 430 wide, text ×1 and ×1.2, keyboard open: mic and button above the keyboard', (tester) async {
        await freshDevice();
        signUp('v01.kb.$place', role: place == 'matching' ? 'seeker' : 'broker');
        if (place != 'matching') billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        const keyboard = 336.0; // an iPhone keyboard with its suggestion bar
        for (final size in const <Size>[Size(360, 740), Size(390, 844), Size(430, 932)]) {
          for (final scale in const <double>[1.0, 1.2]) {
            final at = '${size.width.toInt()} wide, text ×$scale';
            tester.platformDispatcher.textScaleFactorTestValue = scale;
            await pumpApp(tester, place == 'matching' ? '/account/matching' : '/account/search', size: size);
            if (place == 'requests') await tapText(tester, 'بحث طلبات');
            if (place != 'matching') {
              await _choose(tester, 'اختر', 'الرياض');
              await _choose(tester, 'اختر', 'الملقا');
              await _choose(tester, 'اختر', 'فيلا');
            }
            expect(tester.takeException(), isNull, reason: at);
            expectMicInside(tester);

            // focus the description, the keyboard opens, a long text is typed
            final text = find.descendant(of: find.byType(DictationField), matching: find.byType(EditableText));
            await tester.showKeyboard(text);
            tester.view.viewInsets = FakeViewPadding(bottom: keyboard * tester.view.devicePixelRatio);
            await tester.pump();
            await tester.enterText(text, 'فيلا عوائل قرب المدارس والمسجد\nمدخلين وحديقة\nحتى ثلاثة ملايين\nشمال الرياض\nقريبة من طريق الملك فهد');
            await tester.pump();
            await tester.pump(const Duration(milliseconds: 400));
            await settleScrolls(tester, _mic); // the page finishes revealing the field
            expect(tester.takeException(), isNull, reason: at);

            final kbTop = size.height - keyboard;
            final mic = tester.getRect(_mic);
            expect(mic.bottom <= kbTop, isTrue, reason: '$at: mic $mic must stay above the keyboard (top $kbTop)');
            expect(_reachable(tester, _mic), isTrue, reason: '$at: the mic is covered');
            expectMicInside(tester);
            final button = find.ancestor(of: find.text(place == 'matching' ? 'حفظ الرغبة' : 'بحث'), matching: find.byType(AppButton));
            expect(tester.getRect(button).bottom <= kbTop, isTrue, reason: '$at: the button ${tester.getRect(button)} is under the keyboard');
            expect(_reachable(tester, button), isTrue, reason: '$at: the button is covered');
            tester.view.resetViewInsets();
            await tester.pump();
          }
        }
      });
    }
  });

  group('behaviour', () {
    testWidgets('partial results replace each other — no repeated words', (tester) async {
      final c = await _pumpField(tester, text: 'فيلا');
      await tester.tap(_mic);
      await tester.pump();
      expect(fake.listening, isTrue);
      expect(find.textContaining('يستمع'), findsOneWidget);
      fake.hear('قرب');
      fake.hear('قرب المدارس');
      fake.hear('قرب المدارس والمسجد', isFinal: true);
      await tester.pump();
      expect(c.text, 'فيلا قرب المدارس والمسجد');
      expect(find.textContaining('يستمع'), findsNothing);
    });

    testWidgets('permission refused: text unchanged, short message, typing still works', (tester) async {
      fake.availability = DictationAvailability.denied;
      final c = await _pumpField(tester, text: 'شقة عوائل');
      await tester.tap(_mic);
      await tester.pump();
      expect(c.text, 'شقة عوائل');
      expect(find.textContaining('اسمح بالميكروفون'), findsOneWidget);
      expect(find.textContaining('فيلا هادئة قرب مدارس'), findsNothing, reason: 'no sample words are ever inserted');
      await tester.enterText(find.byType(EditableText), 'شقة عوائل بالعليا');
      expect(c.text, 'شقة عوائل بالعليا');
    });

    testWidgets('service unavailable or an error: the text is not changed', (tester) async {
      fake.availability = DictationAvailability.unavailable;
      final c = await _pumpField(tester, text: 'أرض');
      await tester.tap(_mic);
      await tester.pump();
      expect(c.text, 'أرض');
      expect(find.textContaining('غير متاح'), findsOneWidget);

      fake.availability = DictationAvailability.available;
      await tester.tap(_mic);
      await tester.pump();
      fake.fail('error_network');
      await tester.pump();
      expect(c.text, 'أرض');
      expect(find.textContaining('اتصالًا بالإنترنت'), findsOneWidget);
    });

    testWidgets('tap again stops; typing by hand stops; disposing stops', (tester) async {
      final c = await _pumpField(tester);
      await tester.tap(_mic);
      await tester.pump();
      await tester.tap(_mic);
      await tester.pump();
      expect(fake.stops, 1);
      expect(fake.listening, isFalse);

      await tester.tap(_mic);
      await tester.pump();
      fake.hear('فيلا');
      await tester.enterText(find.byType(EditableText), 'فيلا مع مسبح');
      await tester.pump();
      expect(fake.cancels, 1, reason: 'typing by hand stops the recording');
      fake.hear('فيلا مع'); // a late result is ignored
      expect(c.text, 'فيلا مع مسبح');

      await tester.tap(_mic);
      await tester.pump();
      expect(fake.listening, isTrue);
      await tester.pumpWidget(const SizedBox.shrink());
      expect(fake.listening, isFalse, reason: 'leaving the screen stops the recording');
    });

    testWidgets('opening another page over the screen stops the recording', (tester) async {
      await freshDevice();
      signUp('v01.leave', role: 'seeker');
      await pumpApp(tester, '/account/matching');
      await tester.tap(_mic);
      await tester.pump();
      expect(fake.listening, isTrue);
      final nav = tester.state<NavigatorState>(find.byType(Navigator).first);
      nav.pushNamed('/');
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(fake.listening, isFalse);
      expect(fake.cancels, 1);
    });

    testWidgets('a disabled field has a disabled mic', (tester) async {
      await _pumpField(tester, enabled: false);
      await tester.tap(_mic);
      await tester.pump();
      expect(fake.starts, 0);
    });
  });

  test('the wish is saved per account (dictated text is ordinary text)', () async {
    await freshDevice();
    signUp('v01.wish', role: 'seeker');
    store.saveWish(const Wish(city: 'الرياض', hoods: <String>['الملقا'], types: <String>['villa'], budget: '', text: 'فيلا قرب المدارس'));
    expect(store.loadWish()!.text, 'فيلا قرب المدارس');
  });
}
