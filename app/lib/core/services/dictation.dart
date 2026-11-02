// Dictation: speech → text through the phone's OWN speech recognition
// service (Apple Speech on iOS, the system SpeechRecognizer on Android), via
// the speech_to_text plugin. No paid AI service is involved.
//
// * The keyboard's own dictation mic stays available — it is controlled by
//   the system; the app's mic button only uses the same system service.
// * Whether recognition works offline depends on the phone and the language;
//   nothing here promises it.
// * The screens use `DictationField` (shared/widgets/dictation_field.dart).
//   Tests replace [dictation] with a fake service.

import 'package:flutter/foundation.dart';

import 'package:speech_to_text/speech_recognition_error.dart';
import 'package:speech_to_text/speech_to_text.dart';

/// Result of preparing the speech service.
enum DictationAvailability {
  available,

  /// The microphone / speech permission was refused.
  denied,

  /// No speech service on this phone (or the plugin is missing).
  unavailable,
}

/// The speech recognition service used by the description fields.
abstract class DictationService {
  /// Asks for the permission the first time and checks the service.
  Future<DictationAvailability> prepare();

  /// Starts listening. [onWords] receives everything heard so far in this
  /// session (each call replaces the previous one) and [isFinal] on the last
  /// call. [onEnd] runs once when listening stops; [onError] with a short
  /// code (e.g. `error_network`). Returns false when it could not start.
  Future<bool> start({
    required void Function(String words, bool isFinal) onWords,
    required VoidCallback onEnd,
    required void Function(String code) onError,
  });

  /// Stops listening and delivers the final words.
  Future<void> stop();

  /// Stops listening and drops what was not delivered yet.
  Future<void> cancel();
}

/// The service used by the app (tests may replace it).
DictationService dictation = SystemDictation();

/// [DictationService] backed by the phone's speech recognition.
class SystemDictation implements DictationService {
  final SpeechToText _stt = SpeechToText();
  bool _ready = false;
  String? _localeId;
  VoidCallback? _onEnd;
  void Function(String code)? _onError;

  @override
  Future<DictationAvailability> prepare() async {
    if (_ready) return DictationAvailability.available;
    try {
      _ready = await _stt.initialize(onStatus: _status, onError: _error);
    } catch (_) {
      return DictationAvailability.unavailable; // plugin missing / platform error
    }
    if (!_ready) {
      try {
        if (!await _stt.hasPermission) return DictationAvailability.denied;
      } catch (_) {}
      return DictationAvailability.unavailable;
    }
    _localeId = await _arabicLocale();
    return DictationAvailability.available;
  }

  /// An Arabic recognition locale of the phone (Saudi first), else the
  /// phone's default.
  Future<String?> _arabicLocale() async {
    try {
      final ids = (await _stt.locales()).map((l) => l.localeId).toList();
      String? pick(bool Function(String id) test) {
        for (final id in ids) {
          if (test(id.toLowerCase().replaceAll('-', '_'))) return id;
        }
        return null;
      }

      return pick((id) => id == 'ar_sa') ?? pick((id) => id.startsWith('ar'));
    } catch (_) {
      return null;
    }
  }

  @override
  Future<bool> start({
    required void Function(String words, bool isFinal) onWords,
    required VoidCallback onEnd,
    required void Function(String code) onError,
  }) async {
    _onEnd = onEnd;
    _onError = onError;
    try {
      await _stt.listen(
        onResult: (r) => onWords(r.recognizedWords, r.finalResult),
        listenOptions: SpeechListenOptions(
          localeId: _localeId,
          listenFor: const Duration(seconds: 60),
          pauseFor: const Duration(seconds: 5),
          partialResults: true,
          cancelOnError: true,
          listenMode: ListenMode.dictation,
          autoPunctuation: true,
        ),
      );
      return true;
    } catch (_) {
      _end();
      return false;
    }
  }

  void _status(String s) {
    if (s == SpeechToText.doneStatus || s == SpeechToText.notListeningStatus) _end();
  }

  void _error(SpeechRecognitionError e) {
    final cb = _onError;
    _onError = null;
    cb?.call(e.errorMsg);
    _end();
  }

  void _end() {
    final cb = _onEnd;
    _onEnd = null;
    _onError = null;
    cb?.call();
  }

  @override
  Future<void> stop() async {
    try {
      await _stt.stop();
    } catch (_) {}
  }

  @override
  Future<void> cancel() async {
    _onEnd = null;
    _onError = null;
    try {
      await _stt.cancel();
    } catch (_) {}
  }
}
