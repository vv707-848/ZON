// A description field with the dictation mic INSIDE its frame (brief V01).
//
// Used by the semantic search (offers and requests) and by the wish of the
// matching assistant. The frame holds the text area on top and, below it, a
// band with the mic button (48×48 design units — at least 44×44 points even
// on the smallest phones after UiScale — at the end corner) and a short
// status. The text never runs under the mic: the text area ends above the
// band and scrolls inside it.
//
// Behaviour:
// * tap → the phone's speech service listens (see core/services/dictation);
//   what is heard is added after the existing text and can be edited;
// * partial results replace each other (no repeated words);
// * tap again → stop; leaving the screen, the app going to the background,
//   typing by hand or the field being disabled → stop recording;
// * a tap while the service is still preparing (permission) or starting
//   cancels it: the recording never starts afterwards, and a start that
//   was already on its way is stopped (QA F08). The speech service is
//   called one step at a time, in order, so a «cancel» reaches it after the
//   step it follows;
// * after «stop» the final words of the session may still arrive: they are
//   written — unless the text was changed by hand meanwhile or a new
//   session began; then they are ignored and the text typed stays (QA F09);
// * one field dictates at a time: starting in another field stops this one;
// * permission refused / no service / an error → a short message, the text
//   is not changed and typing stays available;
// * disabled field (search before city, district and type) → disabled mic.
//
// Keyboard: when the field gets focus or the keyboard opens, the page scrolls
// so that the whole frame — the mic included — and the button under it
// ([revealBelow]) stay above the keyboard.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/services/dictation.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

enum _Mic { idle, starting, listening }

/// Forgets the speech-service queue and the dictating field (between tests,
/// which each run in their own zone).
@visibleForTesting
void resetDictationQueue() {
  _DictationFieldState._tail = null;
  _DictationFieldState._running = 0;
  _DictationFieldState._owner = null;
}

/// Touch target of the mic (design units; ≥ 44 pt at the smallest UiScale).
const double _hit = 48;

/// Multi-line description field with a dictation mic inside its frame.
class DictationField extends StatefulWidget {
  const DictationField({
    super.key,
    required this.controller,
    required this.textStyle,
    this.enabled = true,
    this.placeholder,
    this.placeholderColor,
    this.height = 140,
    this.decoration,
    this.padding = const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 4),
    this.header,
    this.dark = false,
    this.revealBelow = 72,
  });

  final TextEditingController controller;
  final TextStyle textStyle;

  /// When false the text and the mic are both disabled.
  final bool enabled;
  final String? placeholder;
  final Color? placeholderColor;

  /// Height of the whole frame (text area + mic band).
  final double height;

  /// The frame (border / fill). Light fields pass the form's box.
  final BoxDecoration? decoration;
  final EdgeInsetsGeometry padding;

  /// Shown inside the frame above the text (e.g. the label on the dark card).
  final Widget? header;

  /// White text and mic for the dark matching card.
  final bool dark;

  /// Room under the frame that is also scrolled above the keyboard (the
  /// search / save button that follows the field).
  final double revealBelow;

  @override
  State<DictationField> createState() => _DictationFieldState();
}

class _DictationFieldState extends State<DictationField> with WidgetsBindingObserver, SingleTickerProviderStateMixin {
  _Mic mic = _Mic.idle;
  String note = '';
  bool noteIsError = false;

  /// Text before the current session ("" or the text + one space).
  String _prefix = '';

  /// Last text written by dictation (to notice typing by hand).
  String? _written;

  /// Callbacks of an older session are ignored.
  int _session = 0;

  /// After «stop»: the final words of this session may still come (QA F09).
  bool _awaitingFinal = false;

  /// The field whose session owns the speech service (one at a time).
  static _DictationFieldState? _owner;

  /// Calls to the speech service run one after the other, in order (QA F08):
  /// [_tail] is the last call still running (null when none is).
  static Future<void>? _tail;
  static int _running = 0;

  static Future<T> _serial<T>(Future<T> Function() step) {
    final before = _tail;
    final next = before == null ? step() : before.then((_) => step());
    final done = next.then<void>((_) {}, onError: (Object _) {});
    _tail = done;
    _running++;
    unawaited(
      done.whenComplete(() {
        if (--_running == 0) _tail = null;
      }),
    );
    return next;
  }

  late final AnimationController _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 900));

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    widget.controller.addListener(_onText);
  }

  @override
  void didUpdateWidget(DictationField old) {
    super.didUpdateWidget(old);
    if (old.controller != widget.controller) {
      old.controller.removeListener(_onText);
      widget.controller.addListener(_onText);
    }
    if (!widget.enabled && (mic != _Mic.idle || _awaitingFinal)) _abort();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Another page was opened over this one → stop recording.
    final route = ModalRoute.of(context);
    if (route != null && !route.isCurrent && mic != _Mic.idle) _abort();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mic != _Mic.idle) _abort();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    widget.controller.removeListener(_onText);
    if (mic != _Mic.idle || _awaitingFinal) {
      _session++;
      // after a start that may still be on its way (QA F08)
      unawaited(_serial(dictation.cancel));
    }
    if (_owner == this) _owner = null;
    _pulse.dispose();
    super.dispose();
  }

  void _onText() {
    // Typing by hand while recording, or while the final words are still on
    // their way after «stop»: stop, keep what was typed and ignore the late
    // words (QA F09).
    final byHand = _written != null && widget.controller.text != _written;
    if (byHand && (mic != _Mic.idle || _awaitingFinal)) _abort();
    if (noteIsError && note.isNotEmpty && mounted) setState(() => note = '');
  }

  void _say(String text, {bool error = false}) {
    note = text;
    noteIsError = error;
  }

  Future<void> _toggle() async {
    if (!widget.enabled) return;
    if (mic == _Mic.listening) {
      await _stopByUser();
      return;
    }
    if (mic == _Mic.starting) {
      // still preparing or starting: cancel — it must not start later (F08)
      _abort();
      return;
    }
    // one field at a time: another field that is dictating stops first
    final other = _owner;
    if (other != null && other != this && other.mounted) other._abort();
    _owner = this;
    final session = ++_session;
    _awaitingFinal = false;
    _written = null;
    setState(() {
      mic = _Mic.starting;
      _say('');
    });
    final availability = await _serial(dictation.prepare);
    if (!mounted || session != _session) return;
    if (availability != DictationAvailability.available) {
      setState(() {
        mic = _Mic.idle;
        _say(
          availability == DictationAvailability.denied
              ? 'اسمح بالميكروفون والتعرّف على الكلام من إعدادات الهاتف، أو اكتب الوصف.'
              : 'الإملاء غير متاح على هذا الهاتف — اكتب الوصف.',
          error: true,
        );
      });
      return;
    }
    final base = widget.controller.text.trimRight();
    _prefix = base.isEmpty ? '' : '$base ';
    _written = widget.controller.text;
    final started = await _serial(
      () => dictation.start(
        onWords: (words, isFinal) {
          if (!mounted || session != _session) return;
          // after the session ended, nothing more is written
          if (mic == _Mic.idle && !_awaitingFinal) return;
          _write(words);
          if (isFinal) _awaitingFinal = false;
        },
        onEnd: () {
          if (!mounted || session != _session) return;
          _awaitingFinal = false;
          _pulse.stop();
          setState(() {
            mic = _Mic.idle;
            if (!noteIsError) _say('');
          });
        },
        onError: (code) {
          if (!mounted || session != _session) return;
          _awaitingFinal = false;
          setState(() => _say(_errorText(code), error: true));
        },
      ),
    );
    // cancelled, left or replaced meanwhile: the «cancel» queued by that
    // action reaches the service after this start (F08)
    if (!mounted || session != _session) return;
    if (!started) {
      setState(() {
        mic = _Mic.idle;
        _say('تعذّر بدء الإملاء — اكتب الوصف.', error: true);
      });
      return;
    }
    if (mic == _Mic.starting) {
      unawaited(_pulse.repeat(reverse: true));
      setState(() {
        mic = _Mic.listening;
        _say('يستمع… اضغط المايك للإيقاف');
      });
    }
  }

  /// Adds what was heard after the text that was there before.
  void _write(String words) {
    final w = words.trim();
    if (w.isEmpty) return;
    final text = '$_prefix$w';
    _written = text;
    widget.controller.value = TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }

  /// The user tapped the mic again: stop and keep the final words (unless
  /// the text is changed by hand before they arrive — see [_onText]).
  Future<void> _stopByUser() async {
    _pulse.stop();
    _awaitingFinal = true;
    setState(() {
      mic = _Mic.idle;
      _say('');
    });
    await _serial(dictation.stop);
  }

  /// Cancel / leaving / typing / disabled / another field: stop recording
  /// and ignore late results.
  void _abort() {
    _session++;
    _awaitingFinal = false;
    if (_owner == this) _owner = null;
    _pulse.stop();
    unawaited(_serial(dictation.cancel));
    if (!mounted) return;
    void reset() {
      mic = _Mic.idle;
      if (!noteIsError) _say('');
    }

    // May be called while the framework is building (didUpdateWidget).
    if (WidgetsBinding.instance.schedulerPhase == SchedulerPhase.idle ||
        WidgetsBinding.instance.schedulerPhase == SchedulerPhase.postFrameCallbacks) {
      setState(reset);
    } else {
      reset();
    }
  }

  static String _errorText(String code) {
    final c = code.toLowerCase();
    if (c.contains('permission')) return 'اسمح بالميكروفون والتعرّف على الكلام من إعدادات الهاتف.';
    if (c.contains('network') || c.contains('server')) return 'الإملاء يحتاج اتصالًا بالإنترنت على هذا الهاتف — اكتب الوصف.';
    if (c.contains('no_match') || c.contains('speech_timeout')) return 'لم يُسمع كلام واضح — حاول مرة أخرى.';
    return 'تعذّر الإملاء — اكتب الوصف يدويًا.';
  }

  @override
  Widget build(BuildContext context) {
    final dark = widget.dark;
    final enabled = widget.enabled;
    final on = mic != _Mic.idle;
    final noteColor = noteIsError ? (dark ? const Color(0xFFFFB4AB) : AppColors.danger) : (dark ? const Color(0xFFC5D0C8) : AppColors.muted);

    final micButton = Semantics(
      button: true,
      enabled: enabled,
      label: on ? 'إيقاف الإملاء' : 'إملاء الوصف بالصوت',
      child: Tap(
        onTap: enabled ? _toggle : null,
        child: SizedBox(
          key: const ValueKey<String>('dictation-mic'),
          width: _hit,
          height: _hit,
          child: Center(
            child: AnimatedBuilder(
              animation: _pulse,
              builder: (context, child) {
                final ring = on ? 3 + _pulse.value * 4 : 0.0;
                return Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: on ? (dark ? AppColors.white : AppColors.forest) : (dark ? const Color(0x26FFFFFF) : AppColors.mist),
                    boxShadow: on
                        ? <BoxShadow>[BoxShadow(color: (dark ? AppColors.white : AppColors.forest).withValues(alpha: 0.25), spreadRadius: ring)]
                        : null,
                  ),
                  alignment: Alignment.center,
                  child: child,
                );
              },
              child: LucideIcon(
                on ? Lu.circleStop : Lu.mic,
                size: 20,
                strokeWidth: 2,
                color: on ? (dark ? AppColors.forest : AppColors.white) : (dark ? AppColors.white : AppColors.forest),
              ),
            ),
          ),
        ),
      ),
    );

    final band = SizedBox(
      height: _hit,
      child: Row(
        children: <Widget>[
          Expanded(
            child: note.isEmpty
                ? const SizedBox.shrink()
                : Tx(
                    note,
                    style: ts(12, w: 600, lh: 16, c: noteColor),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
          ),
          const SizedBox(width: 6),
          micButton,
        ],
      ),
    );

    return Opacity(
      opacity: enabled ? 1 : 0.5,
      child: Container(
        height: widget.height,
        decoration: widget.decoration,
        padding: widget.padding,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            ?widget.header,
            Expanded(
              child: FieldBox(
                controller: widget.controller,
                enabled: enabled,
                placeholder: widget.placeholder,
                placeholderColor: widget.placeholderColor,
                style: widget.textStyle,
                height: double.infinity,
                padding: EdgeInsets.zero,
                multiline: true,
                // the caret can be anywhere in the text area: keeping the
                // frame's height under it reveals the mic band too.
                scrollPadding: EdgeInsets.fromLTRB(20, 20, 20, widget.height + widget.revealBelow),
              ),
            ),
            band,
          ],
        ),
      ),
    );
  }
}
