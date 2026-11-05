// Root widget: theme, Arabic RTL locale, routes and the proportional scaling.

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'package:zon/app/gate_overlay.dart';
import 'package:zon/app/router.dart';
import 'package:zon/core/layout/ui_scale.dart';
import 'package:zon/core/theme/app_theme.dart';
import 'package:zon/core/theme/theme.dart';

/// Largest text size the app follows from the phone's settings.
const double kMaxTextScale = 1.2;

/// The app: Material shell, Arabic locale, routes and global text/scale setup.
class ZonApp extends StatelessWidget {
  const ZonApp({super.key, this.initialRoute = '/'});

  /// First route (deep links / tests); the home feed by default.
  final String initialRoute;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'زون',
      debugShowCheckedModeBanner: false,
      navigatorKey: Nav.key,
      theme: buildAppTheme(),
      locale: const Locale('ar'),
      supportedLocales: const <Locale>[Locale('ar')],
      localizationsDelegates: const <LocalizationsDelegate<Object>>[
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      initialRoute: initialRoute,
      onGenerateRoute: onGenerateRoute,
      onGenerateInitialRoutes: (String name) => <Route<dynamic>>[buildRoute(RouteSettings(name: name), animate: false)],
      // Text follows the phone's larger-text setting (accessibility) up to
      // ×[kMaxTextScale]; beyond that the cards would no longer fit the
      // phone. Smaller-than-normal settings keep the design size. The main
      // screens are checked at ×1.2 on a 360-pt phone (test/text_scale_test).
      // Over every page, the server's gate (update required, maintenance).
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context).copyWith(textScaler: MediaQuery.textScalerOf(context).clamp(minScaleFactor: 1, maxScaleFactor: kMaxTextScale)),
        child: UiScale(
          child: DefaultTextStyle(
            style: baseTextStyle(),
            child: GateOverlay(child: child ?? const SizedBox.shrink()),
          ),
        ),
      ),
    );
  }
}
