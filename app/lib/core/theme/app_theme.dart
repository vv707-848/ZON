// Material/Cupertino theme built from the design tokens.
//
// Most widgets of the app are custom and read the tokens directly; the theme
// makes the remaining framework widgets (text fields, date pickers, dialogs,
// selection handles…) use the same font (the system font — SF on iPhone)
// and colours.

import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

import 'package:zon/core/theme/colors.dart';
import 'package:zon/core/theme/typography.dart';

/// The Material theme of the app (colours, font, dialogs, pickers).
ThemeData buildAppTheme() {
  final base = baseTextStyle();
  TextStyle cupertino(double size, String family) =>
      TextStyle(fontFamily: family, fontFamilyFallback: base.fontFamilyFallback, fontSize: size, color: AppColors.ink);
  const scheme = ColorScheme.light(
    primary: AppColors.forest,
    onPrimary: AppColors.white,
    secondary: AppColors.deep,
    onSecondary: AppColors.white,
    surface: AppColors.white,
    onSurface: AppColors.ink,
    error: AppColors.danger,
    onError: AppColors.white,
    // No green "elevation tint" on Material surfaces: keeps cards pure white.
    surfaceTint: Colors.transparent,
  );
  return ThemeData(
    useMaterial3: true,
    fontFamily: kFontFamily,
    fontFamilyFallback: fontFallback.isEmpty ? null : fontFallback,
    colorScheme: scheme,
    scaffoldBackgroundColor: AppColors.paper,
    canvasColor: AppColors.paper,
    // The app has its own pressed states; no Material ink ripples.
    splashFactory: NoSplash.splashFactory,
    splashColor: Colors.transparent,
    highlightColor: Colors.transparent,
    hoverColor: Colors.transparent,
    textTheme: TextTheme(bodyLarge: base, bodyMedium: base, bodySmall: base, titleMedium: base, labelLarge: base),
    textSelectionTheme: const TextSelectionThemeData(
      cursorColor: AppColors.forest,
      selectionColor: Color(0x331C3228),
      selectionHandleColor: AppColors.forest,
    ),
    dialogTheme: const DialogThemeData(backgroundColor: AppColors.white, surfaceTintColor: Colors.transparent),
    datePickerTheme: const DatePickerThemeData(backgroundColor: AppColors.white, surfaceTintColor: Colors.transparent),
    timePickerTheme: const TimePickerThemeData(backgroundColor: AppColors.white),
    cupertinoOverrideTheme: CupertinoThemeData(
      primaryColor: AppColors.forest,
      textTheme: CupertinoTextThemeData(
        textStyle: cupertino(16, kFontFamily),
        dateTimePickerTextStyle: cupertino(20, kDisplayFontFamily),
        pickerTextStyle: cupertino(20, kDisplayFontFamily),
      ),
    ),
  );
}
