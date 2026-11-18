// Opening links outside the app.

import 'package:flutter/foundation.dart';
import 'package:url_launcher/url_launcher.dart';

/// What opens a link outside the app (replaced in tests, which cannot leave the app).
@visibleForTesting
Future<void> Function(Uri uri) openOutside = (uri) => launchUrl(uri, mode: LaunchMode.externalApplication);

/// Opens [url] in the browser or the matching app (e.g. Google Maps, WhatsApp).
Future<void> openExternal(String url) async {
  final uri = Uri.tryParse(url);
  if (uri == null) return;
  try {
    await openOutside(uri);
  } catch (_) {}
}

/// Starts a phone call to [number].
Future<void> callPhone(String number) async {
  try {
    await launchUrl(Uri(scheme: 'tel', path: number));
  } catch (_) {}
}
