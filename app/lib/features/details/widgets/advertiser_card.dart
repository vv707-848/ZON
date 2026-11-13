// Advertiser card and the phone-numbers pop-up.

import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/simulations.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_chats.dart';
import 'package:zon/data/store.dart';

/// The advertiser's card: avatar, name, role, chat and call buttons.
class AdvertiserCard extends StatelessWidget {
  const AdvertiserCard({super.key, required this.name, required this.role, this.listingId, this.phones, this.username});

  final String name;
  final Role role;
  final String? listingId;
  final List<String>? phones;

  /// With a server: his username — his page, and a conversation with him (none for one's own listing).
  final String? username;

  @override
  Widget build(BuildContext context) {
    final p = name.trim().isNotEmpty ? name.trim() : (kRoleLabel[role] ?? '');
    final id = slugName(p);
    final nums = (phones != null && phones!.isNotEmpty)
        ? phones!
        : kSimulations && role == 'developer'
        ? kProjectPhones
        : const <String>[];
    Widget btn({required String label, required String icon, required bool ghost, required VoidCallback onTap}) {
      return Tap(
        onTap: onTap,
        child: Container(
          height: 46,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(
            color: ghost ? AppColors.white : AppColors.deep,
            borderRadius: BorderRadius.circular(999),
            border: ghost ? Border.all(color: AppColors.deep, width: 1.5) : null,
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              LucideIcon(icon, size: 16, strokeWidth: 1.8, color: ghost ? AppColors.deep : AppColors.white),
              const SizedBox(width: 6),
              Tx(label, style: ts(14, w: 700, lh: 21, c: ghost ? AppColors.deep : AppColors.white)),
            ],
          ),
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: Color(0x14121214), spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tap(
            onTap: () => Nav.go(context, '/users/${Api.on && username != null ? Uri.encodeComponent(username!) : id}${listingId != null ? '?listing=$listingId' : ''}'),
            child: Row(
              children: <Widget>[
                Container(
                  width: 44,
                  height: 44,
                  decoration: const BoxDecoration(color: AppColors.forest, shape: BoxShape.circle),
                  alignment: Alignment.center,
                  child: Tx(p.isEmpty ? '' : String.fromCharCode(p.runes.first), style: ts(16, w: 600, lh: 24, c: AppColors.white)),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Tx(
                        p,
                        style: ts(16, w: 600, lh: 24, c: AppColors.deep),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        softWrap: false,
                      ),
                      const SizedBox(height: 1),
                      Tx(kRoleLabel[role] ?? '', style: ts(13, w: 500, lh: 19.5, c: AppColors.muted)),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              Expanded(
                child: btn(
                  label: 'محادثة',
                  icon: Lu.messageCircle,
                  ghost: false,
                  onTap: () {
                    final u = username;
                    if (Api.on && u != null) {
                      Nav.go(context, store.readSession() == null ? '/login' : ServerChats.startPath(u, about: listingId, name: p, role: role));
                      return;
                    }
                    store.openChat(Chat(id: id, name: p, role: role, last: listingId != null ? 'عرض مسحوب' : 'مرحباً', listingId: listingId));
                    Nav.go(context, '/chats/$id');
                  },
                ),
              ),
              if (nums.isNotEmpty) ...<Widget>[
                const SizedBox(width: 6),
                Expanded(
                  child: btn(
                    label: 'اتصال',
                    icon: Lu.phone,
                    ghost: true,
                    onTap: () {
                      if (nums.length == 1) {
                        callPhone(nums.first);
                      } else {
                        showCallPop(context, nums);
                      }
                    },
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

/// Pop-up list of phone numbers (above the tab bar).
Future<void> showCallPop(BuildContext context, List<String> nums) {
  return showGeneralDialog<void>(
    context: context,
    barrierDismissible: true,
    barrierLabel: 'call',
    barrierColor: const Color(0x66171719),
    transitionDuration: Duration.zero,
    pageBuilder: (ctx, a, b) {
      final bottom = MediaQuery.paddingOf(ctx).bottom;
      return Align(
        alignment: Alignment.bottomCenter,
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: math.min(512, MediaQuery.sizeOf(ctx).width)),
          child: Padding(
            padding: EdgeInsets.fromLTRB(18, 18, 18, 18 + 72 + math.max(0, bottom - 11.2)),
            child: Material(
              type: MaterialType.transparency,
              child: DefaultTextStyle(
                style: baseTextStyle(),
                child: Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(22)),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      Tx('أرقام الاتصال', style: ts(16, lh: 26.5, c: AppColors.ink)),
                      const SizedBox(height: 4),
                      for (final n in nums) ...<Widget>[
                        const SizedBox(height: 8),
                        Tap(
                          onTap: () => callPhone(n),
                          child: Container(
                            height: 48,
                            decoration: BoxDecoration(color: const Color(0xFFF3F5F4), borderRadius: BorderRadius.circular(14)),
                            alignment: Alignment.center,
                            child: Directionality(
                              textDirection: TextDirection.ltr,
                              child: Tx(n, style: ts(16, w: 700, lh: 24, c: AppColors.ink)),
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      );
    },
  );
}
