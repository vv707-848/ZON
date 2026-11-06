// Entry point of زون.
//
// 1. Prepares Flutter, then error tracking (Sentry, only with SENTRY_DSN) so
//    the errors of the start are caught too, and the status-bar style. (The
//    font is the phone's system font — SF on iPhone — so no font file or
//    licence is bundled.)
// 2. Prepares the app's photo folder (photos picked from the gallery are
//    kept there — QA F05), then loads the local store (shared_preferences)
//    before the first frame, so every screen can read data synchronously.
// 3. Reads this phone's identity and, in a build with a server
//    (ZON_API_URL), connects to it: the version gate, the saved session and
//    push notifications (lib/app/server.dart).
// 4. Runs [ZonApp] (lib/app/zon_app.dart).

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:zon/app/server.dart';
import 'package:zon/app/zon_app.dart';
import 'package:zon/core/monitoring/monitoring.dart';
import 'package:zon/core/services/photo_store.dart';
import 'package:zon/data/store.dart';

/// Loads the saved data, then starts the app.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Monitoring.init();
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(statusBarColor: Color(0x00000000), statusBarIconBrightness: Brightness.dark, statusBarBrightness: Brightness.light),
  );
  await PhotoStore.init();
  await Store.init();
  await startApp();
  runApp(const ZonApp());
}
