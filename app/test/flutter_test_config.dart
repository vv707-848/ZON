// Run by `flutter test` before the tests of every file.
//
// The app draws its text with the phone's system font — San Francisco on
// iPhone (SF Pro + SF Arabic), see lib/core/theme/typography.dart. The test
// engine has no system fonts, and Apple's licence does not allow copying SF,
// so the tests measure text with licensed stand-ins of similar proportions
// (test/fonts/, SIL Open Font License — never part of the app):
//
// * Inter — for SF Pro (Latin letters, digits, punctuation), registered under
//   the app's two family names;
// * Noto Sans Arabic — for SF Arabic, tried after it for Arabic letters.
//
// Without them the tests would measure the square test font, far wider than
// Arabic text, and report overflows the app does not have. The real look
// must be checked on an iPhone.

import 'dart:async';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/theme/typography.dart';

/// Family name of the Arabic stand-in (only in tests and the screenshot tool).
const String kArabicStandIn = 'ZonArabicStandIn';

const List<String> _weights = <String>['Regular', 'Medium', 'SemiBold', 'Bold'];

Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  TestWidgetsFlutterBinding.ensureInitialized();
  await loadStandInFonts();
  await testMain();
}

/// Registers the stand-in fonts of `test/fonts/` and puts the Arabic one
/// after the system font in every text style of the app.
Future<void> loadStandInFonts([String dir = 'test/fonts']) async {
  Future<ByteData> read(String file) async {
    final f = File('$dir/$file');
    if (!f.existsSync()) throw StateError('Stand-in font missing: ${f.absolute.path} (run the tests from the project folder).');
    return ByteData.sublistView(await f.readAsBytes());
  }

  for (final family in const <String>[kFontFamily, kDisplayFontFamily]) {
    final latin = FontLoader(family);
    for (final w in _weights) {
      latin.addFont(read('Inter-$w.otf'));
    }
    await latin.load();
  }
  final arabic = FontLoader(kArabicStandIn);
  for (final w in _weights) {
    arabic.addFont(read('NotoSansArabic-$w.ttf'));
  }
  await arabic.load();
  fontFallback = const <String>[kArabicStandIn];
}
