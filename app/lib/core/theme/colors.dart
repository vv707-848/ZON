// Colour palette of زون: white surfaces and a forest-green brand.
//
// Use these tokens instead of raw `Color(0x…)` values so the whole app keeps
// one consistent look. To re-brand, change the values here.

import 'package:flutter/painting.dart';

/// Every colour of the app (see docs/DESIGN_SYSTEM.md for when to use which).
abstract final class AppColors {
  // ---------------------------------------------------------------- brand
  /// Brand green (the logo's green): primary buttons, active tabs, prices,
  /// selected states.
  static const forest = Color(0xFF173124);

  /// Very dark green used for headings and dark buttons.
  static const deep = Color(0xFF1C2B24);

  /// Secondary greens (success badges, accents on dark cards).
  static const success = Color(0xFF136B43);
  static const teal = Color(0xFF0F5C4C);

  // ---------------------------------------------------------------- logo
  /// The three colours of the logo (core/widgets/zon_logo.dart). [mint] is
  /// also the light accent on dark green surfaces (e.g. the ZON card).
  static const logoGreen = Color(0xFF173124);
  static const cream = Color(0xFFFAF6EA);
  static const mint = Color(0xFF9EF3C7);

  // ---------------------------------------------------------------- text
  /// Primary text.
  static const ink = Color(0xFF121214);

  /// Secondary text (labels, captions).
  static const sage = Color(0xFF5C635E);

  /// Tertiary text (hints, meta lines).
  static const muted = Color(0xFF66706B);

  /// Disabled / placeholder-like text.
  static const stone = Color(0xFF77817C);

  // ---------------------------------------------------------------- surfaces
  /// Pure white: cards, sheets, the detail pages.
  static const white = Color(0xFFFFFFFF);

  /// White used by raised surfaces (tab bar, inputs).
  static const surface = Color(0xFFFFFFFF);

  /// Page background of the feeds (home, requests, chats…): almost white.
  static const offerPaper = Color(0xFFF8FAF9);

  /// Page background of forms and account pages: light grey-green.
  static const paper = Color(0xFFF4F6F5);

  /// Soft green-grey fill for info blocks inside white cards.
  static const mist = Color(0xFFEEF2EE);

  /// Lighter fill for spec cells and secondary buttons.
  static const tint = Color(0xFFF3F6F4);

  // ---------------------------------------------------------------- lines
  /// Hairline borders and dividers.
  static const line = Color(0xFFE2E8E4);

  /// Border of [mist] blocks.
  static const mistLine = Color(0xFFE6EBE7);

  // ---------------------------------------------------------------- accents
  /// Gold accent — reserved for premium ("مميز") badges and paid plans.
  static const gold = Color(0xFFB89A5A);
  static const gold2 = Color(0xFFC9AD6E);

  /// Errors, "sold" stamps, destructive actions.
  static const danger = Color(0xFF9B1C1C);

  /// Liked heart.
  static const liked = Color(0xFFC45C5C);
}
