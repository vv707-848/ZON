// `/notifications` — الإشعارات (brief N01).
//
// Opened by the bell on the home page. Rows look like the conversations
// list: an icon for the type, a short title, one line, the time and an
// unread mark; newest first. A tap marks the row read (the bell count drops)
// and opens where it belongs:
//   match   → the matching assistant (its results)
//   message → that conversation
//   listing → the listing / project page
//   request → the request details
//   plan    → the plan or the related service
// A target that no longer exists (deleted listing, removed chat…) or that
// the account's role cannot open shows a short notice instead.
// The rows come from data/notifications/notification_center.dart.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/clock.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/store.dart';

/// `/notifications`
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  @override
  void initState() {
    super.initState();
    // Save the rows computed from the data so their time stays fixed.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) notifications.sync();
    });
  }

  Future<void> _open(AppNotification n) async {
    notifications.markRead(n.id);
    if (!targetAvailable(n.target)) {
      await showAppNotice(context, icon: Lu.info, title: 'لم يعد هذا العنصر متاحًا', message: 'ربما حُذف أو لم يعد ضمن صلاحيات حسابك.');
      return;
    }
    if (!mounted) return;
    await Nav.go(context, n.target);
  }

  @override
  Widget build(BuildContext context) {
    if (store.readSession() == null) return const RedirectTo('/login');
    return AppShell(
      child: ListenableBuilder(
        listenable: store,
        builder: (context, _) {
          final rows = notifications.list();
          final now = appNow();
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 20, 16, 32),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                BackLink(onTap: () => Nav.back(context)),
                const SizedBox(height: 12),
                Tx('الإشعارات', style: ts(20, w: 700, lh: 28, c: AppColors.ink)),
                const SizedBox(height: 16),
                if (rows.isEmpty)
                  const _Empty()
                else
                  for (var i = 0; i < rows.length; i++) ...<Widget>[
                    if (i > 0) const SizedBox(height: 8),
                    NotificationRow(item: rows[i], time: arRelative(rows[i].time, now), onTap: () => _open(rows[i])),
                  ],
              ],
            ),
          );
        },
      ),
    );
  }
}

/// Whether [target] can still be opened by the signed-in account.
bool targetAvailable(String target) {
  final s = store.readSession();
  if (s == null) return false;
  final seg = Loc(target).segs;
  if (seg.isEmpty) return true;
  switch (seg[0]) {
    case 'listings':
    case 'projects':
      if (seg.length < 2) return true;
      final l = store.listingById(seg[1]);
      return l != null && (l.active != false || store.ownsListing(l));
    case 'requests':
      return seg.length < 2 || store.requestById(seg[1]) != null;
    case 'chats':
      return seg.length < 2 || seg[1] == 'barq' || store.chatById(seg[1]) != null;
    case 'account':
      if (seg.length < 2) return true;
      final roles = kSvcRoles[seg[1]];
      return roles == null || roles.contains(s.role);
  }
  return true;
}

/// One notification, in the style of a conversation row.
class NotificationRow extends StatelessWidget {
  const NotificationRow({super.key, required this.item, required this.time, required this.onTap});

  final AppNotification item;
  final String time;
  final VoidCallback onTap;

  static String iconOf(String kind) => switch (kind) {
    NoteKind.match => Lu.sparkles,
    NoteKind.message => Lu.messageCircle,
    NoteKind.listing => Lu.home,
    NoteKind.request => Lu.search,
    _ => Lu.crown,
  };

  @override
  Widget build(BuildContext context) {
    final unread = !item.read;
    return Semantics(
      button: true,
      label: '${unread ? 'غير مقروء: ' : ''}${item.title}',
      child: Tap(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: AppColors.white,
            borderRadius: BorderRadius.circular(22),
            boxShadow: <BoxShadow>[BoxShadow(color: unread ? const Color(0xFFCFE3D6) : AppColors.mistLine, spreadRadius: 1)],
          ),
          // icon | title and line | time + unread dot
          child: IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Center(
                  child: Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(color: unread ? AppColors.forest : AppColors.mist, borderRadius: BorderRadius.circular(16)),
                    alignment: Alignment.center,
                    child: LucideIcon(iconOf(item.kind), size: 20, strokeWidth: 1.9, color: unread ? AppColors.white : AppColors.forest),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Center(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        // two lines: a name in the title is not cut with larger text
                        Tx(
                          item.title,
                          style: ts(16, w: unread ? 700 : 600, lh: 24, c: unread ? AppColors.ink : AppColors.deep),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 3),
                        Tx(
                          item.body,
                          style: ts(14, lh: 21, c: unread ? const Color(0xFF555555) : AppColors.muted),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: <Widget>[
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Tx(time, style: ts(12, lh: 18, c: const Color(0xFF8A8A8A)), softWrap: false),
                    ),
                    const SizedBox(height: 8),
                    if (unread)
                      Container(
                        key: const ValueKey<String>('unread-dot'),
                        width: 9,
                        height: 9,
                        decoration: const BoxDecoration(color: Color(0xFFC24B28), shape: BoxShape.circle),
                      ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 56, horizontal: 12),
      child: Column(
        children: <Widget>[
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(18)),
            alignment: Alignment.center,
            child: const LucideIcon(Lu.bell, size: 24, color: AppColors.forest),
          ),
          const SizedBox(height: 14),
          Tx(
            'لا توجد إشعارات جديدة',
            style: ts(16, w: 700, lh: 24, c: AppColors.deep),
            align: TextAlign.center,
          ),
        ],
      ),
    );
  }
}
