// Firebase Cloud Messaging on the phone — the app's [PushProvider]. On in a build with
// --dart-define=ZON_PUSH=true and the Firebase project's files in the app (android/app/google-services.json,
// ios/Runner/GoogleService-Info.plist; docs/integrations.md «الإشعارات — Firebase»). Without them the app has no
// push and nothing else changes.

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import 'package:zon/data/push/push.dart';

/// Whether this build has Firebase.
const bool kPush = bool.fromEnvironment('ZON_PUSH');

/// FCM through firebase_messaging.
class FirebasePush implements PushProvider {
  FirebasePush._(this._messaging);

  final FirebaseMessaging _messaging;

  /// Firebase when the build has it; null otherwise, or when its files are missing.
  static Future<FirebasePush?> start() async {
    if (!kPush || kIsWeb) return null;
    try {
      await Firebase.initializeApp();
      final messaging = FirebaseMessaging.instance;
      // In the foreground the app shows its own banner (lib/shared/widgets/push_banner.dart), on both systems.
      await messaging.setForegroundNotificationPresentationOptions(alert: false, badge: true, sound: true);
      return FirebasePush._(messaging);
    } catch (_) {
      return null; // a ZON_PUSH build without the Firebase files: the app runs without push
    }
  }

  @override
  Future<bool> requestPermission() async {
    final settings = await _messaging.requestPermission();
    return settings.authorizationStatus == AuthorizationStatus.authorized || settings.authorizationStatus == AuthorizationStatus.provisional;
  }

  @override
  Future<String?> token() async {
    try {
      if (defaultTargetPlatform == TargetPlatform.iOS) {
        // On iOS the FCM token needs the APNs one first, which can take a moment after the permission.
        for (var i = 0; i < 5 && await _messaging.getAPNSToken() == null; i++) {
          await Future<void>.delayed(const Duration(seconds: 1));
        }
      }
      return await _messaging.getToken();
    } catch (_) {
      return null; // no token now: the next start asks again
    }
  }

  @override
  Stream<String> get tokenRefresh => _messaging.onTokenRefresh;

  @override
  Stream<PushMessage> get opened => FirebaseMessaging.onMessageOpenedApp.map(_message);

  @override
  Stream<PushMessage> get received => FirebaseMessaging.onMessage.map(_message);

  @override
  Future<PushMessage?> initial() async {
    final message = await _messaging.getInitialMessage();
    return message == null ? null : _message(message);
  }

  static PushMessage _message(RemoteMessage m) {
    final target = m.data['target'];
    return PushMessage(title: m.notification?.title ?? '', body: m.notification?.body ?? '', target: target is String ? target : '');
  }
}
