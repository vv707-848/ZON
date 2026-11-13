// `/account/listings/:id/review` — the wording of a new or edited listing, with a server (§10.2).
//
// The server prepares the AI's wording after the listing is saved («قيد الصياغة»), then:
// * nothing to change, or the AI is not there: the listing is published with the advertiser's own text, and this
//   page opens it;
// * a suggestion: it waits for his decision («بانتظار مراجعتك») — «قبول ونشر» (the suggestion), «تعديل الوصف»
//   (his edit of it), «انشر بنصي» (his own). Without a decision it is published with his text after a while.
// The page asks again every few seconds while the wording is prepared; when it takes longer, it says so and sends
// him to «عروضي» (a notice comes when it is ready).

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_my_listings.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/widgets/form_inputs.dart';
import 'package:zon/features/add/widgets/polish_review.dart';

/// The wording review of the server's listing [id] (`L…`).
class ListingReviewScreen extends StatefulWidget {
  const ListingReviewScreen({super.key, required this.id});

  final String id;

  /// How often, and how many times, the page asks while the wording is prepared.
  static const Duration every = Duration(seconds: 3);
  static const int tries = 10;

  @override
  State<ListingReviewScreen> createState() => _ListingReviewScreenState();
}

class _ListingReviewScreenState extends State<ListingReviewScreen> {
  WordingReview? _review;
  String? _error;
  bool _late = false;
  bool _sending = false;
  bool _editing = false;
  String _text = '';
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    store.addListener(_onAccount);
    unawaited(_ask());
  }

  @override
  void dispose() {
    store.removeListener(_onAccount);
    super.dispose();
  }

  /// Another account (or none) under this page: nothing of the one before stays shown, and it is asked again —
  /// the server answers only the listing's advertiser.
  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    setState(() {
      _review = null;
      _text = '';
      _editing = false;
      _sending = false;
    });
    unawaited(_ask());
  }

  /// Whether an answer asked for under [epoch] is still for the account shown (and this page's latest asking).
  bool _currentFor(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _ask() async {
    final epoch = _epoch;
    setState(() {
      _error = null;
      _late = false;
    });
    for (var i = 0; i < ListingReviewScreen.tries; i++) {
      try {
        final review = await ServerMyListings.review(widget.id);
        if (!_currentFor(epoch)) return;
        if (!review.preparing) {
          if (!review.waiting) {
            // published as he wrote it (or decided already): the listing itself
            unawaited(Nav.replace(context, '/listings/${widget.id}'));
            return;
          }
          setState(() {
            _review = review;
            _text = review.suggested!;
          });
          return;
        }
      } on ApiError catch (e) {
        if (_currentFor(epoch)) setState(() => _error = e.forUser);
        return;
      }
      await Future<void>.delayed(ListingReviewScreen.every);
      if (!_currentFor(epoch)) return;
    }
    setState(() => _late = true);
  }

  Future<void> _decide(WordingDecision decision) async {
    setState(() {
      _sending = true;
      _error = null;
    });
    final epoch = _epoch;
    try {
      final listing = await ServerMyListings.decide(widget.id, decision, text: decision == WordingDecision.edit ? _text.trim() : null);
      store.keepServer(<Listing>[listing], epoch: epoch, detailed: true);
      if (_currentFor(epoch)) unawaited(Nav.replace(context, '/listings/${widget.id}'));
    } on ApiError catch (e) {
      if (_currentFor(epoch)) {
        setState(() {
          _sending = false;
          _error = e.forUser;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final review = _review;
    if (review == null) {
      return AppShell(
        title: 'صياغة الوصف',
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: _error != null
              ? EmptyBox(
                  title: 'تعذّر جلب الصياغة',
                  text: _error!,
                  textStyle: emptyTextStyle(),
                  action: 'أعد المحاولة',
                  onAction: () => unawaited(_ask()),
                )
              : _late
              ? EmptyBox(
                  title: 'الصياغة تأخذ وقتاً',
                  text: 'حُفظ عرضك. يصلك إشعار حين تجهز صياغة الوصف، وتجده في «عروضي».',
                  textStyle: emptyTextStyle(),
                  action: 'عروضي',
                  onAction: () => unawaited(Nav.replace(context, '/account/listings')),
                )
              : const LoadingBox(label: 'نصوغ وصف عرضك…'),
        ),
      );
    }
    final at = review.autoPublishAt;
    return AppShell(
      child: PolishStage(
        onBack: () => Nav.go(context, '/account/listings'),
        title: 'صياغة الوصف',
        lead: 'اقبل صياغة الذكاء أو عدّلها، أو انشر بنصك.${at == null ? '' : ' بلا قرار يُنشر بنصك في ${arDateTime(at)}.'}',
        actions: <Widget>[
          if (_error != null) FormError(_error!),
          if (_editing)
            AppButton(
              label: _sending ? 'جارٍ النشر…' : 'نشر بالنص المعدّل',
              onTap: _sending || _text.trim().length < 10 ? null : () => _decide(WordingDecision.edit),
            )
          else ...<Widget>[
            AppButton(label: _sending ? 'جارٍ النشر…' : 'قبول ونشر', onTap: _sending ? null : () => _decide(WordingDecision.approve)),
            AppButton(label: 'تعديل الوصف', tone: ButtonTone.secondary, onTap: _sending ? null : () => setState(() => _editing = true)),
          ],
          AppButton(label: 'انشر بنصي', tone: ButtonTone.ghost, onTap: _sending ? null : () => _decide(WordingDecision.reject)),
        ],
        children: <Widget>[
          PolishCol(label: 'ما كتبته أنت', text: review.raw),
          const SizedBox(height: 10),
          if (_editing)
            projTextarea(value: _text, maxLength: 3000, onChanged: (v) => setState(() => _text = v))
          else
            PolishCol(label: 'صياغة الذكاء', text: review.suggested!, ai: true),
        ],
      ),
    );
  }
}
