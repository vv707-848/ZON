// `/chats` — المحادثات: the Barq entry card, filters and conversations.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';

class _Filter {
  const _Filter(this.id, this.label, this.icon);

  final String id;
  final String label;
  final String icon;
}

const List<_Filter> _filters = <_Filter>[
  _Filter('all', 'الكل', Lu.users),
  _Filter('developer', 'المطورين', Lu.building2),
  _Filter('broker', 'الوسطاء', Lu.keyRound),
  _Filter('seeker', 'الباحثين', Lu.search),
  _Filter('owner', 'الملاك', Lu.users),
];

const Color _grey8a = Color(0xFF8A8A8A);

/// `/chats` — المحادثات
class ChatsScreen extends StatefulWidget {
  const ChatsScreen({super.key});

  @override
  State<ChatsScreen> createState() => _ChatsScreenState();
}

class _ChatsScreenState extends State<ChatsScreen> {
  String tab = 'all';

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    if (user == null) return const RedirectTo('/login');
    final rows = store.chats().where((c) => tab == 'all' || c.role == tab).toList();
    return AppShell(
      child: Padding(
        padding: const EdgeInsets.only(top: 10, bottom: 28),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
              child: _BarqCard(onTap: () => Nav.go(context, '/chats/barq')),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 14),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  for (final f in _filters)
                    Expanded(
                      child: Tap(
                        onTap: () => setState(() => tab = f.id),
                        child: _Orb(filter: f, on: tab == f.id),
                      ),
                    ),
                ],
              ),
            ),
            if (rows.isNotEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    for (var i = 0; i < rows.length; i++) ...<Widget>[
                      if (i > 0) const SizedBox(height: 8),
                      _WireRow(chat: rows[i], onTap: () => Nav.go(context, '/chats/${rows[i].id}')),
                    ],
                  ],
                ),
              )
            else
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 64),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Tx(
                      'لا صفقات بعد. المحادثة تُفتح من عرض أو طلب.',
                      style: ts(15, lh: 21.5, c: AppColors.muted),
                      align: TextAlign.center,
                    ),
                    const SizedBox(height: 12),
                    Tap(
                      onTap: () => Nav.go(context, '/'),
                      child: Tx(
                        'تصفّح العروض',
                        style: ts(15, w: 700, lh: 21.5, c: AppColors.ink),
                        align: TextAlign.center,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Entry card of the Barq assistant.
class _BarqCard extends StatelessWidget {
  const _BarqCard({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(22)),
        clipBehavior: Clip.antiAlias,
        child: Stack(
          clipBehavior: Clip.none,
          children: <Widget>[
            Row(
              children: <Widget>[
                Container(
                  width: 56,
                  height: 56,
                  decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(18)),
                  alignment: Alignment.center,
                  child: const LucideIcon(Lu.zap, size: 20, color: AppColors.forest, fill: AppColors.forest),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Tx('برق', style: ts(18, w: 700, lh: 27, c: AppColors.white)),
                      const SizedBox(height: 2),
                      Tx('المساعد متصل', style: ts(12, w: 700, lh: 18, c: const Color(0xFFC8E6D0))),
                      const SizedBox(height: 4),
                      Tx(
                        'اسأل عن الصك والتمويل بأي لغة',
                        style: ts(14, lh: 21, c: const Color(0xC4FFFFFF)),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
              ],
            ),
            // "live" dot next to the avatar
            PositionedDirectional(
              top: 0,
              start: 58 - 16,
              child: Container(
                width: 10,
                height: 10,
                decoration: BoxDecoration(
                  color: const Color(0xFF7DCF9A),
                  borderRadius: BorderRadius.circular(99),
                  boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.forest, spreadRadius: 3)],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A round filter button.
class _Orb extends StatelessWidget {
  const _Orb({required this.filter, required this.on});

  final _Filter filter;
  final bool on;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: <Widget>[
        Container(
          width: 48,
          height: 48,
          decoration: BoxDecoration(color: on ? AppColors.forest : AppColors.white, shape: BoxShape.circle),
          foregroundDecoration: on
              ? null
              : BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: const Color(0xFFDDDDDD), width: 1.5),
                ),
          alignment: Alignment.center,
          child: LucideIcon(filter.icon, size: 16, strokeWidth: 1.8, color: on ? AppColors.white : AppColors.deep),
        ),
        const SizedBox(height: 6),
        Tx(
          filter.label,
          style: ts(11, w: 700, lh: 16.5, c: on ? AppColors.forest : const Color(0xFF6B6B6B)),
          align: TextAlign.center,
        ),
      ],
    );
  }
}

/// One conversation in the list.
class _WireRow extends StatelessWidget {
  const _WireRow({required this.chat, required this.onTap});

  final Chat chat;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = chat;
    final initial = c.name.isEmpty ? '' : String.fromCharCode(c.name.codeUnitAt(0));
    return Tap(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(22),
          boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
        ),
        // avatar | name and last message | time (top-aligned)
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Center(
                child: Container(
                  width: 48,
                  height: 48,
                  decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(16)),
                  alignment: Alignment.center,
                  child: Tx(initial, style: ts(16, w: 700, lh: 24, c: AppColors.white)),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Center(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Row(
                        children: <Widget>[
                          Flexible(
                            child: Tx(c.name, style: ts(16, w: 700, lh: 24, c: AppColors.ink)),
                          ),
                          const SizedBox(width: 8),
                          Tx(kRoleLabel[c.role] ?? '', style: ts(16, lh: 24, c: AppColors.ink)),
                        ],
                      ),
                      const SizedBox(height: 3),
                      Tx(
                        c.last.isNotEmpty ? c.last : 'بدون رسائل',
                        style: ts(14, lh: 21, c: const Color(0xFF555555)),
                        maxLines: 1,
                        softWrap: false,
                        overflow: TextOverflow.ellipsis,
                      ),
                      if (c.listingId != null && c.listingId!.isNotEmpty) ...<Widget>[
                        const SizedBox(height: 2),
                        Tx(
                          'عرض مسحوب',
                          style: ts(12, lh: 18, c: _grey8a),
                          maxLines: 1,
                          softWrap: false,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Align(
                alignment: Alignment.topCenter,
                child: Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Tx(c.at ?? '', style: ts(12, lh: 18, c: _grey8a), softWrap: false),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
