// QA F06 / F07 — saving a listing keeps what the edit did not touch: the
// floor (also the values 'first' / 'second' saved by version 1.1, never
// turned into the ground floor) and the paused state (showing a paused
// listing again is its own action).

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/listing_editor.dart';
import 'package:zon/features/details/listing_screen.dart';

import 'support/test_env.dart';

/// An apartment of the signed-in account.
Listing _apartment(String id, {String? floorLevel, num? floorNumber, bool active = true}) {
  store.saveListing(
    Listing.fromJson(<String, Object?>{
      'id': id,
      'kind': 'unit',
      'title': 'شقة اختبار',
      'purpose': 'sale',
      'city': 'الرياض',
      'neighborhood': 'الملقا',
      'propertyType': 'apartment',
      'source': 'broker',
      'price': 900000,
      'rooms': 3,
      'baths': 2,
      'area': 140,
      'images': <String>['/listings/apt-1.jpg'],
      'description': 'وصف الشقة',
      'advertiser': 'حساب اختبار',
      'advertiserRole': 'broker',
      'license': '7200000001',
      'active': active,
      'floorLevel': ?floorLevel,
      'floorNumber': ?floorNumber,
    }),
  );
  return store.listingById(id)!;
}

Finder _field(String label) => find.descendant(
  of: find.ancestor(of: find.text(label), matching: find.byType(Column)).first,
  matching: find.byType(EditableText),
);

String _text(WidgetTester tester, Finder f) => tester.widget<EditableText>(f.first).controller.text;

Future<void> _enter(WidgetTester tester, Finder f, String text) async {
  await tester.ensureVisible(f.first);
  await tester.enterText(f.first, text);
  await tester.pump();
}

Future<void> _save(WidgetTester tester) async {
  await tapText(tester, 'حفظ وتحديث العرض');
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  group('F06 — the floor is kept', () {
    testWidgets('1.1 «second»: shown as floor 2, kept after a price-only edit', (tester) async {
      await freshDevice();
      signUp('f06.second', role: 'broker');
      _apartment('fl-1', floorLevel: 'second');
      await pumpApp(tester, '/account/listings/fl-1');
      expect(find.byType(ListingEditor), findsOneWidget);
      expect(find.text('رقم الدور'), findsOneWidget, reason: 'not the ground floor');
      expect(_text(tester, _field('رقم الدور')), '2');

      await _enter(tester, _field('السعر'), '950000');
      await _save(tester);
      final l = store.listingById('fl-1')!;
      expect(l.price, 950000);
      expect(l.floorLevel, 'second', reason: 'untouched floor, stored value kept');
      expect(l.floorNumber, isNull);
      await pumpApp(tester, '/listings/fl-1');
      expect(find.byType(ListingScreen), findsOneWidget);
      expect(find.text('الثاني'), findsOneWidget);
    });

    testWidgets('1.1 «first»: shown as floor 1, kept after a description-only edit', (tester) async {
      await freshDevice();
      signUp('f06.first', role: 'broker');
      _apartment('fl-2', floorLevel: 'first');
      await pumpApp(tester, '/account/listings/fl-2');
      expect(_text(tester, _field('رقم الدور')), '1');
      await _enter(tester, find.text('وصف الشقة'), 'وصف جديد للشقة');
      await _save(tester);
      final l = store.listingById('fl-2')!;
      expect(l.description, 'وصف جديد للشقة');
      expect(l.floorLevel, 'first');
    });

    testWidgets('current values (number 7, annex, ground, none) are kept by a price-only edit', (tester) async {
      await freshDevice();
      signUp('f06.current', role: 'broker');
      _apartment('fl-3', floorLevel: 'number', floorNumber: 7);
      _apartment('fl-4', floorLevel: 'annex');
      _apartment('fl-5', floorLevel: 'ground');
      _apartment('fl-6');
      for (final id in <String>['fl-3', 'fl-4', 'fl-5', 'fl-6']) {
        final before = store.listingById(id)!;
        await pumpApp(tester, '/account/listings/$id');
        await _enter(tester, _field('السعر'), '960000');
        await _save(tester);
        final after = store.listingById(id)!;
        expect(after.price, 960000, reason: id);
        expect(after.floorLevel, before.floorLevel, reason: id);
        expect(after.floorNumber, before.floorNumber, reason: id);
      }
    });

    testWidgets('changing the floor of a 1.1 listing saves the new floor', (tester) async {
      await freshDevice();
      signUp('f06.change', role: 'broker');
      _apartment('fl-7', floorLevel: 'second');
      await pumpApp(tester, '/account/listings/fl-7');
      await _enter(tester, _field('رقم الدور'), '٣');
      await _save(tester);
      final l = store.listingById('fl-7')!;
      expect(l.floorLevel, 'number');
      expect(l.floorNumber, 3);
    });
  });

  group('F07 — a paused listing stays paused', () {
    testWidgets('an edit keeps it paused; «تنشيط» in «عروضي» shows it again', (tester) async {
      await freshDevice();
      signUp('f07.listing', role: 'broker');
      _apartment('pa-1', active: false);
      await pumpApp(tester, '/account/listings/pa-1');
      await _enter(tester, _field('السعر'), '870000');
      await _save(tester);
      expect(store.listingById('pa-1')!.price, 870000);
      expect(store.listingById('pa-1')!.active, isFalse, reason: 'saving is not publishing');
      // still in the «غير النشط» list of «عروضي»
      await pumpApp(tester, '/account/listings');
      await tapText(tester, 'غير النشط 1');
      expect(find.text('شقة اختبار'), findsOneWidget);

      await tapText(tester, 'تنشيط');
      expect(store.listingById('pa-1')!.active, isTrue);
    });

    testWidgets('an active listing stays active', (tester) async {
      await freshDevice();
      signUp('f07.active', role: 'broker');
      _apartment('pa-2');
      await pumpApp(tester, '/account/listings/pa-2');
      await _enter(tester, _field('السعر'), '880000');
      await _save(tester);
      expect(store.listingById('pa-2')!.active, isTrue);
    });
  });
}
