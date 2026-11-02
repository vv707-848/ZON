// Opening links outside the app.

import 'package:url_launcher/url_launcher.dart';

/// Opens [url] in the browser or the matching app (e.g. Google Maps).
Future<void> openExternal(String url) async {
  final uri = Uri.tryParse(url);
  if (uri == null) return;
  try {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  } catch (_) {}
}

/// Starts a phone call to [number].
Future<void> callPhone(String number) async {
  try {
    await launchUrl(Uri(scheme: 'tel', path: number));
  } catch (_) {}
}
