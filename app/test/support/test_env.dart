// Shared set-up of the tests: a fresh device, app restarts, accounts.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zon/app/router.dart';
import 'package:zon/app/zon_app.dart';
import 'package:zon/core/services/photo_store.dart';
import 'package:zon/core/utils/clock.dart';
import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';

/// The app's support folder of the test device (its `photos` folder is
/// emptied by [freshDevice]).
final Directory testSupportDir = Directory.systemTemp.createTempSync('zon-support-');

/// A new device: empty storage (or [prefs]) and photo folder, nobody signed
/// in, real clock, instant simulated payments.
Future<void> freshDevice([Map<String, Object> prefs = const <String, Object>{}]) async {
  final photos = Directory('${testSupportDir.path}/photos');
  if (photos.existsSync()) photos.deleteSync(recursive: true);
  await PhotoStore.init(testSupportDir);
  SharedPreferences.setMockInitialValues(Map<String, Object>.of(prefs));
  appClock = DateTime.now;
  PaymentSimulator.latency = Duration.zero;
  PaymentSimulator.nextStatus = null;
  PaymentSimulator.gate = null;
  await Store.init();
}

/// Closes and reopens the app: memory is reloaded from what was saved.
Future<void> restartApp() => Store.init();

/// Creates an account (the sign-up path of the app) and signs it in.
Session signUp(String username, {String role = 'broker', String city = 'الرياض', String name = 'حساب اختبار', String password = 'secret-123'}) {
  final r = LocalAuth.signUp(name: name, phone: '0551234567', username: username, password: password, role: role, city: city);
  expect(r.ok, isTrue, reason: r.error);
  return r.session!;
}

/// Moves the app clock by [d].
void advanceClock(Duration d) {
  final base = appNow();
  appClock = () => base.add(d);
}

/// A unit listing published by the signed-in account.
Listing publishTestListing(String id, {String title = 'فيلا اختبار', List<String> images = const <String>['/listings/villa-1.jpg']}) {
  final l = Listing(
    id: id,
    kind: 'unit',
    title: title,
    purpose: 'sale',
    city: 'الرياض',
    neighborhood: 'الملقا',
    propertyType: 'villa',
    source: 'broker',
    price: 2500000,
    rooms: 5,
    baths: 4,
    area: 400,
    images: images,
    description: 'وصف اختبار',
    advertiser: 'حساب اختبار',
    advertiserRole: 'broker',
    license: '7200000001',
    active: true,
  );
  store.saveListing(l);
  return store.listingById(id)!;
}

/// Pumps the whole app at [route] on a phone-sized screen.
Future<void> pumpApp(WidgetTester tester, String route, {Size size = const Size(390, 844)}) async {
  tester.view.physicalSize = size * 2;
  tester.view.devicePixelRatio = 2;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const ZonApp());
  await tester.pump();
  Nav.key.currentState!.pushNamedAndRemoveUntil(route, (_) => false, arguments: const NavArgs(animate: false));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
}

/// Taps the widget showing [text] (the first one, the [index]th, or the
/// [last] one — e.g. a dialog button above a page with the same label).
Future<void> tapText(WidgetTester tester, String text, {int index = 0, bool last = false}) async {
  final all = find.text(text);
  expect(all, findsWidgets, reason: 'نص غير موجود: $text');
  final f = last ? all.last : all.at(index);
  // A page that just shrank (an error line went away) springs back first,
  // and a scroll view ignores taps while it moves — as on a phone, wait.
  await settleScrolls(tester, f);
  // centre it (a pinned header may cover the top edge of the page)
  await Scrollable.ensureVisible(tester.element(f), alignment: 0.5);
  await tester.pump();
  await settleScrolls(tester, f);
  await tester.tap(f, warnIfMissed: false);
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
}

/// Pumps until every scroll view around [f] has stopped moving (at most 2 s).
Future<void> settleScrolls(WidgetTester tester, Finder f) async {
  for (var i = 0; i < 40; i++) {
    var moving = false;
    var s = Scrollable.maybeOf(tester.element(f));
    while (s != null) {
      if (s.position.isScrollingNotifier.value) moving = true;
      s = Scrollable.maybeOf(s.context);
    }
    if (!moving) return;
    await tester.pump(const Duration(milliseconds: 50));
  }
}
