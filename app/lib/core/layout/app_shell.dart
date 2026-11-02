// The frame every screen is built in.
//
//   ┌───────────────────────────┐  status-bar strip (coloured like the header)
//   │ header (optional)         │  a title bar, or a custom bar (detail pages)
//   ├───────────────────────────┤
//   │ child (scrolls)           │  page content, padded so the tab bar never
//   │                           │  covers the last item
//   │   ╭───────────────────╮   │
//   │   │  floating tab bar │   │  5 tabs; "إضافة" is the raised green button
//   └───╰───────────────────╯───┘
//
// On wide screens the content is centred and capped at 512 px (768 px on
// tablets ≥ 768 px wide) so lines never get too long.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/data/store.dart';

class _Tab {
  const _Tab(this.to, this.label, this.icon, {this.guest = true, this.plus = false});

  final String to;
  final String label;
  final String icon;

  /// Visible to guests; when false a guest is sent to the login page.
  final bool guest;

  /// The raised "+" button in the middle.
  final bool plus;
}

const List<_Tab> _tabs = <_Tab>[
  _Tab('/', 'الرئيسية', Lu.home),
  _Tab('/requests', 'الطلبات', Lu.inbox),
  _Tab('/add', 'إضافة', Lu.plus, guest: false, plus: true),
  _Tab('/chats', 'المحادثات', Lu.messageCircle, guest: false),
  _Tab('/account', 'حسابي', Lu.userRound),
];

/// Maximum content width (512 on phones, 768 on tablets).
double shellMaxWidth(BuildContext context) => MediaQuery.sizeOf(context).width >= 768 ? 768 : 512;

/// Height reserved under the content for the floating tab bar.
const double kTabBarSpace = 100;

class AppShell extends StatelessWidget {
  const AppShell({
    super.key,
    required this.child,
    this.title,
    this.header,
    this.headerColor = AppColors.white,
    this.tone,
    this.background,
    this.scrollable = true,
    this.controller,
  });

  final Widget child;

  /// Simple title bar text (ignored when [header] is given).
  final String? title;

  /// A custom header bar (e.g. the detail pages' back/like/report bar).
  final Widget? header;

  /// Colour of the status-bar strip above [header].
  final Color headerColor;

  /// "forest" paints the title bar dark green with white text.
  final String? tone;

  /// Overrides the page background.
  final Color? background;

  /// When false the child fills the area below the header and manages its own
  /// scrolling (used by the chat screens).
  final bool scrollable;
  final ScrollController? controller;

  @override
  Widget build(BuildContext context) {
    final pathname = Nav.pathOf(context);
    final thread = pathname.startsWith('/chats/');
    final hideTabs = pathname.startsWith('/login') || thread;
    // Feed-like pages use the almost-white background; forms use paper.
    final feed =
        pathname == '/' ||
        pathname.startsWith('/listings/') ||
        pathname.startsWith('/projects/') ||
        pathname.startsWith('/requests') ||
        pathname.startsWith('/users') ||
        pathname.startsWith('/account') ||
        pathname.startsWith('/chats');
    final rootBg = background ?? (feed ? AppColors.offerPaper : AppColors.paper);
    final mq = MediaQuery.of(context);
    final safeTop = mq.padding.top;
    final safeBottom = mq.padding.bottom;
    final keyboard = mq.viewInsets.bottom > 0;
    final forest = tone == 'forest';
    final titleBg = forest ? AppColors.forest : rootBg;

    Widget? bar = header;
    var topBg = rootBg;
    if (bar != null) {
      topBg = headerColor;
    } else if (title != null) {
      topBg = titleBg;
      bar = Container(
        height: 56,
        color: titleBg,
        padding: const EdgeInsets.symmetric(horizontal: 20),
        alignment: AlignmentDirectional.centerStart,
        child: Tx(title!, style: ts(18, w: 600, lh: 27, c: forest ? AppColors.white : AppColors.ink)),
      );
    }

    final bottomPad = hideTabs ? 0.0 : kTabBarSpace + safeBottom;
    final Widget body = scrollable
        ? SingleChildScrollView(
            controller: controller,
            keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.manual,
            child: Padding(
              padding: EdgeInsets.only(bottom: bottomPad),
              child: child,
            ),
          )
        : Padding(
            padding: EdgeInsets.only(bottom: bottomPad),
            child: child,
          );

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: (forest && title != null && header == null) ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
      child: Scaffold(
        backgroundColor: AppColors.paper,
        resizeToAvoidBottomInset: true,
        body: Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: shellMaxWidth(context)),
            child: ColoredBox(
              color: rootBg,
              child: Stack(
                children: <Widget>[
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      Container(height: safeTop, color: topBg),
                      Expanded(
                        child: MediaQuery.removePadding(
                          context: context,
                          removeTop: true,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: <Widget>[
                              ?bar,
                              Expanded(child: body),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                  if (!hideTabs && !keyboard)
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: _TabBar(pathname: pathname, safeBottom: safeBottom),
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

/// The floating bottom navigation.
class _TabBar extends StatelessWidget {
  const _TabBar({required this.pathname, required this.safeBottom});

  final String pathname;
  final double safeBottom;

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    return Padding(
      padding: EdgeInsets.fromLTRB(12, 0, 12, safeBottom > 12 ? safeBottom : 12),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(28),
          border: Border.all(color: AppColors.line),
          boxShadow: AppShadows.raised,
        ),
        padding: const EdgeInsets.all(6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: <Widget>[
            for (final t in _tabs)
              Expanded(
                child: Center(
                  child: _TabItem(
                    tab: t,
                    on: t.to == '/' ? pathname == '/' : pathname.startsWith(t.to),
                    href: (!t.guest && user == null) ? '/login' : t.to,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _TabItem extends StatelessWidget {
  const _TabItem({required this.tab, required this.on, required this.href});

  final _Tab tab;
  final bool on;
  final String href;

  @override
  Widget build(BuildContext context) {
    final color = on ? AppColors.forest : AppColors.sage;
    final label = Tx(
      tab.label,
      style: ts(11, w: on ? 600 : 500, lh: 16.5, c: color),
      maxLines: 1,
    );
    final Widget content;
    if (tab.plus) {
      // The green "+" circle rises above the bar; its white ring cuts a notch.
      content = SizedBox(
        width: 56,
        height: 48,
        child: Stack(
          clipBehavior: Clip.none,
          alignment: Alignment.topCenter,
          children: <Widget>[
            Positioned(top: 32.5, child: label),
            Positioned(
              top: -27.5,
              child: Container(
                width: 56,
                height: 56,
                decoration: const BoxDecoration(
                  color: AppColors.forest,
                  shape: BoxShape.circle,
                  boxShadow: <BoxShadow>[
                    BoxShadow(color: Color(0x331C3228), blurRadius: 16, offset: Offset(0, 6)),
                    BoxShadow(color: AppColors.white, spreadRadius: 4),
                  ],
                ),
                alignment: Alignment.center,
                child: const LucideIcon(Lu.plus, size: 24, strokeWidth: 2.2, color: AppColors.white),
              ),
            ),
          ],
        ),
      );
    } else {
      content = ConstrainedBox(
        constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            AnimatedContainer(
              duration: const Duration(milliseconds: 150),
              width: 40,
              height: 40,
              decoration: BoxDecoration(color: on ? AppColors.forest : const Color(0x00000000), shape: BoxShape.circle),
              alignment: Alignment.center,
              child: LucideIcon(tab.icon, size: 18, strokeWidth: on ? 2.2 : 1.8, color: on ? AppColors.white : color),
            ),
            const SizedBox(height: 2),
            label,
          ],
        ),
      );
    }
    return Semantics(
      button: true,
      selected: on,
      label: tab.label,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: () => Nav.go(context, href, animate: false),
        child: ExcludeSemantics(child: content),
      ),
    );
  }
}
