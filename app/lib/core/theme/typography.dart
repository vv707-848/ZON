// Typography of زون: one typeface, one size scale, one helper.
//
// * Font: San Francisco — the iPhone's own system font: SF Pro for Latin
//   letters and digits and SF Arabic for Arabic letters (the system Arabic
//   font since iOS 15), drawn as one family. No font file is bundled:
//   Apple's licence allows SF only through the system, never copied into an
//   app. On Android the same names give Android's system font (Roboto with
//   Noto Sans Arabic). Nothing in the app names another font.
// * The names are Flutter's proxies for the system font:
//   'CupertinoSystemText' (SF Pro Text, below 20 pt) and
//   'CupertinoSystemDisplay' (SF Pro Display, 20 pt and above).
// * Sizes: only the steps listed in [kTypeScale]. Pick the step by role, e.g.
//   12 for meta lines, 15 for body text, 18 for section titles.
// * `ts()` builds a [TextStyle] from (size, weight, colour, line-height);
//   `Tx` renders text with that exact line-height so rows never jump.
//
// Full reference with examples: docs/DESIGN_SYSTEM.md.

import 'package:flutter/material.dart';

import 'package:zon/core/theme/colors.dart';

/// The app's font: the system font (SF Pro Text with SF Arabic on iPhone).
const String kFontFamily = 'CupertinoSystemText';

/// The same system font for sizes of 20 and above (SF Pro Display on iPhone).
const String kDisplayFontFamily = 'CupertinoSystemDisplay';

/// Sizes from this one use [kDisplayFontFamily] (Apple's switch point).
const double kDisplayFontFrom = 20;

/// Extra families tried after the system font — empty in the app. Tests and
/// the screenshot tool, where the system fonts do not exist, put licensed
/// stand-in fonts here (see test/flutter_test_config.dart).
List<String> fontFallback = const <String>[];

/// [fontFallback] as a [TextStyle] value (null when empty).
List<String>? get _fallback => fontFallback.isEmpty ? null : fontFallback;

/// Every font size used in the app (logical pixels, before [UiScale]).
///
/// | size | role |
/// |-----:|------|
/// | 11 | tiny badges, tab-bar labels |
/// | 12 | meta text, captions, small pills |
/// | 13 | secondary labels, chips |
/// | 14 | compact body, buttons, list rows |
/// | 15 | body text |
/// | 16 | emphasised body, inputs, card titles |
/// | 18 | section titles |
/// | 20 | key numbers, dialog titles |
/// | 22 | page titles |
/// | 24 / 28 / 32 | hero numbers and display text |
const List<double> kTypeScale = <double>[11, 12, 13, 14, 15, 16, 18, 20, 22, 24, 28, 32];

/// The four weights of the design (regular, medium, semibold, bold); `ts()`
/// accepts only these so the hierarchy stays the same everywhere.
const List<int> kFontWeights = <int>[400, 500, 600, 700];

/// Default text style of the whole app (body text, ink colour).
TextStyle baseTextStyle() => TextStyle(
  fontFamily: kFontFamily,
  fontFamilyFallback: _fallback,
  fontSize: 15,
  height: 1.5,
  fontWeight: FontWeight.w400,
  letterSpacing: 0,
  color: AppColors.ink,
  leadingDistribution: TextLeadingDistribution.even,
  decoration: TextDecoration.none,
);

/// Builds a text style.
///
/// * [size] — a step of [kTypeScale].
/// * [w] — weight: 400 regular, 500 medium, 600 semibold, 700 bold.
/// * [c] — colour (inherits the surrounding colour when null).
/// * [lh] — line height in logical pixels (null → the font's natural height).
TextStyle ts(double size, {int w = 400, Color? c, double? lh, FontStyle? style, TextDecoration? decoration}) {
  assert(kTypeScale.contains(size), 'Font size $size is not on the type scale (kTypeScale).');
  assert(kFontWeights.contains(w), 'Font weight $w is not a design weight (use 400/500/600/700).');
  return TextStyle(
    fontFamily: size >= kDisplayFontFrom ? kDisplayFontFamily : kFontFamily,
    fontFamilyFallback: _fallback,
    fontSize: size,
    fontWeight: FontWeight(w),
    color: c,
    height: lh == null ? null : lh / size,
    fontStyle: style,
    decoration: decoration,
    leadingDistribution: TextLeadingDistribution.even,
  );
}

/// Text with a fixed line height.
///
/// When the style has a line height, every line of the paragraph is exactly
/// that tall (a forced strut). Mixed Arabic/Latin text or a fallback glyph
/// therefore can never make a row taller, which keeps cards and lists aligned.
class Tx extends StatelessWidget {
  const Tx(this.text, {super.key, required this.style, this.align, this.maxLines, this.overflow, this.softWrap, this.dir});

  final String text;
  final TextStyle style;
  final TextAlign? align;
  final int? maxLines;
  final TextOverflow? overflow;
  final bool? softWrap;

  /// Forces a text direction (e.g. LTR for phone numbers).
  final TextDirection? dir;

  @override
  Widget build(BuildContext context) {
    final eff = DefaultTextStyle.of(context).style.merge(style);
    return Text(
      text,
      style: style,
      strutStyle: fixedStrut(eff),
      textAlign: align,
      maxLines: maxLines,
      overflow: overflow,
      softWrap: softWrap,
      textDirection: dir,
    );
  }
}

/// The forced strut used by [Tx] (null when the style has no line height).
StrutStyle? fixedStrut(TextStyle eff) {
  if (eff.height == null) return null;
  return StrutStyle(
    fontFamily: eff.fontFamily,
    fontFamilyFallback: eff.fontFamilyFallback,
    fontSize: eff.fontSize,
    height: eff.height,
    fontWeight: eff.fontWeight,
    leadingDistribution: TextLeadingDistribution.even,
    forceStrutHeight: true,
  );
}
