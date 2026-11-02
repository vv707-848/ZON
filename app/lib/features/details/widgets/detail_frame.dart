// Page frame of the listing, project and unit pages.
//
//   ┌────────────────────────────────────┐
//   │ →                         🏳  ♡     │  DetailTopBar: back + the user's actions
//   ├────────────────────────────────────┤
//   │            photo gallery           │  edge to edge (PhotoGallery)
//   │               ● ○ ○                │
//   │  title, price, specs…              │  white page, 16 px side margins
//   └────────────────────────────────────┘

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

/// White page with the detail top bar, a full-width [gallery] and the
/// content [children] stacked with even spacing.
class DetailScaffold extends StatelessWidget {
  const DetailScaffold({super.key, required this.gallery, required this.children, this.actions, this.backRoute = '/'});

  /// Usually a [PhotoGallery]; spans the full screen width.
  final Widget gallery;

  /// Content sections under the gallery.
  final List<Widget> children;

  /// Buttons at the left end of the top bar (like + report).
  final Widget? actions;

  /// Where the back arrow goes when there is no previous page (deep link).
  final String backRoute;

  /// Vertical gap between content sections.
  static const double gap = 16;

  @override
  Widget build(BuildContext context) {
    return AppShell(
      background: AppColors.white,
      header: DetailTopBar(actions: actions, backRoute: backRoute),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          gallery,
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 18, 16, 28),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: gap), children[i]],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// 56 px white bar: back arrow on the right (RTL start), [actions] on the left.
class DetailTopBar extends StatelessWidget {
  const DetailTopBar({super.key, this.actions, this.backRoute = '/'});

  final Widget? actions;
  final String backRoute;

  void _back(BuildContext context) {
    final nav = Navigator.of(context);
    if (nav.canPop()) {
      nav.pop();
    } else {
      Nav.replace(context, backRoute);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 56,
      color: AppColors.white,
      padding: const EdgeInsets.symmetric(horizontal: 6),
      child: Row(
        children: <Widget>[
          Semantics(
            button: true,
            label: 'رجوع',
            child: Tap(
              onTap: () => _back(context),
              child: const SizedBox(
                width: 44,
                height: 44,
                child: Center(child: LucideIcon(Lu.arrowRight, size: 24, strokeWidth: 2.2, color: AppColors.deep)),
              ),
            ),
          ),
          const Spacer(),
          ?actions,
        ],
      ),
    );
  }
}
