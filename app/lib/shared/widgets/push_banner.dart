// A notification that arrives while the app is open: a card at the top for a few seconds, the same on iPhone
// and Android (the system shows none then); a tap opens its page.

import 'dart:async';

import 'package:flutter/widgets.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/push/push.dart';

/// How long the card stays when nobody taps it.
const Duration kPushBannerTime = Duration(seconds: 5);

/// Shows [message] over the current page; [open] opens its page.
void showPushBanner(PushMessage message, {required void Function(PushMessage message) open}) {
  final overlay = Nav.key.currentState?.overlay;
  if (overlay == null || (message.title.isEmpty && message.body.isEmpty)) return;
  late final OverlayEntry entry;
  var shown = true;
  void hide() {
    if (!shown) return;
    shown = false;
    entry.remove();
  }

  entry = OverlayEntry(
    builder: (context) => PushBanner(
      message: message,
      onTap: () {
        hide();
        open(message);
      },
      onClose: hide,
    ),
  );
  overlay.insert(entry);
  Timer(kPushBannerTime, hide);
}

/// The card itself.
class PushBanner extends StatelessWidget {
  const PushBanner({super.key, required this.message, required this.onTap, required this.onClose});

  final PushMessage message;
  final VoidCallback onTap;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Align(
        alignment: Alignment.topCenter,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
          child: Tap(
            onTap: onTap,
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(20), boxShadow: AppShadows.modal),
              child: Row(
                children: <Widget>[
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(12)),
                    alignment: Alignment.center,
                    child: const LucideIcon(Lu.bell, size: 18, color: AppColors.white),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        if (message.title.isNotEmpty)
                          Tx(
                            message.title,
                            style: ts(15, w: 700, lh: 22.5, c: AppColors.ink),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        if (message.body.isNotEmpty)
                          Tx(
                            message.body,
                            style: ts(13, lh: 19.5, c: AppColors.muted),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  Tap(
                    onTap: onClose,
                    child: const Padding(
                      padding: EdgeInsets.all(6),
                      child: LucideIcon(Lu.x, size: 16, color: AppColors.muted),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
