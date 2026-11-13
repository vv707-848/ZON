// Search-request cards (closed list card and the opened detail card).

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_chats.dart';
import 'package:zon/data/store.dart';

const Color _askPill = Color(0xFFF2F4F3);
const Color _askLabel = Color(0xFF6A776F);
const Color _askTo = Color(0xFF8A968E);
const Color _urgentRed = Color(0xFFC62828);
const Color _geoColor = Color(0xFFD7DED8);

/// Dark green header of a request card: buy/rent + urgent pills, the
/// reference number, the wanted types and the location.
class _AskHead extends StatelessWidget {
  const _AskHead({required this.item, required this.open});

  final RequestItem item;
  final bool open;

  @override
  Widget build(BuildContext context) {
    final e = item;
    final buy = e.purpose == 'sale';
    final types = e.types.isNotEmpty ? e.types.map((t) => kTypeLabel[t] ?? '').join(' · ') : 'عقار';
    final geo = <String>[e.city, ...e.neighborhoods].where((s) => s.isNotEmpty).join(' · ');
    final digits = e.id.replaceAll(RegExp(r'\D'), '');
    final refSrc = digits.isNotEmpty ? digits : e.id;
    final ref = refSrc.length > 4 ? refSrc.substring(refSrc.length - 4) : refSrc;

    Widget pill(String text, {required Color bg, required Color fg, String? icon}) {
      return Container(
        height: 24,
        padding: const EdgeInsets.symmetric(horizontal: 10),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (icon != null) ...<Widget>[LucideIcon(icon, size: 12, strokeWidth: 2.2, color: fg), const SizedBox(width: 4)],
            Tx(text, style: ts(13, w: 700, lh: 13, c: fg), softWrap: false),
          ],
        ),
      );
    }

    final top = SizedBox(
      height: 24,
      child: Row(
        children: <Widget>[
          pill(buy ? 'طلب شراء' : 'طلب إيجار', bg: AppColors.white, fg: AppColors.forest),
          if (e.urgent == true) ...<Widget>[const SizedBox(width: 8), pill('عاجل', bg: _urgentRed, fg: AppColors.white, icon: Lu.zap)],
          const Spacer(),
          const SizedBox(width: 8),
          Tx('#$ref', style: ts(16, w: 700, lh: 16, c: AppColors.white), softWrap: false),
        ],
      ),
    );

    final geoRow = Row(
      children: <Widget>[
        const LucideIcon(Lu.mapPin, size: 12, strokeWidth: 2.2, color: _geoColor),
        const SizedBox(width: 5),
        Flexible(
          child: Tx(
            geo,
            style: ts(13, w: 600, lh: open ? 17.4 : 12, c: _geoColor),
          ),
        ),
      ],
    );

    if (open) {
      return Container(
        color: AppColors.forest,
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            top,
            const SizedBox(height: 8),
            Tx(types, style: ts(20, w: 700, lh: 25, c: AppColors.white)),
            const SizedBox(height: 8),
            geoRow,
          ],
        ),
      );
    }
    // Fixed 88 px header: pills row (24), the title, then the location (16);
    // (21.6px) is centred on its 12px row and the geo line on its 16px row.
    return Container(
      height: 88,
      color: AppColors.forest,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Stack(
        clipBehavior: Clip.none,
        children: <Widget>[
          Positioned(left: 0, right: 0, top: 0, child: top),
          Positioned(
            left: 0,
            right: 0,
            top: 30 + (12 - 21.6) / 2,
            child: Tx(
              types,
              style: ts(18, w: 700, lh: 21.5, c: AppColors.white),
              maxLines: 1,
              softWrap: false,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            top: 48,
            height: 16,
            child: OverflowBox(maxHeight: double.infinity, alignment: Alignment.center, child: geoRow),
          ),
        ],
      ),
    );
  }
}

/// Pill with the author's role (باحث / وسيط / مالك / مطوّر).
class _AskRole extends StatelessWidget {
  const _AskRole(this.role);

  final Role role;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 26,
      padding: const EdgeInsets.symmetric(horizontal: 11),
      decoration: BoxDecoration(color: _askPill, borderRadius: BorderRadius.circular(999)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const LucideIcon(Lu.userRound, size: 13, strokeWidth: 2.2, color: AppColors.forest),
          const SizedBox(width: 5),
          Tx(kRoleLabel[role] ?? '', style: ts(13, w: 700, lh: 19.5, c: AppColors.forest), softWrap: false),
        ],
      ),
    );
  }
}

/// Body of a closed request card (`AskBody` without `open`).
class _AskClosedBody extends StatelessWidget {
  const _AskClosedBody({required this.item, required this.cta});

  final RequestItem item;
  final bool cta;

  @override
  Widget build(BuildContext context) {
    final e = item;
    final budget = e.priceMax != 0 && !e.priceMax.isNaN ? e.priceMax : e.priceMin;
    final area = e.areaMax != 0 && !e.areaMax.isNaN ? e.areaMax : e.areaMin;
    final roomsLo = e.roomsMin;
    final roomsHi = e.roomsMax;
    final showRooms = roomsLo > 0 || roomsHi > 0;
    final roomsTxt = showRooms ? '${sar(roomsHi)} - ${sar(roomsLo)}' : '';
    final valueStyle = ts(15, w: 700, lh: 18, c: AppColors.forest);

    Widget cell(String label, Widget value) {
      return Container(
        height: 56,
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
        decoration: BoxDecoration(color: _askPill, borderRadius: BorderRadius.circular(12)),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Tx(
              label,
              style: ts(13, w: 600, lh: 13, c: AppColors.forest),
              align: TextAlign.center,
              softWrap: false,
            ),
            const SizedBox(height: 4),
            value,
          ],
        ),
      );
    }

    final budgetOk = budget != 0 && !budget.isNaN;
    final areaOk = area != 0 && !area.isNaN;
    final cells = <Widget>[
      cell(
        'الميزانية',
        budgetOk
            ? SizedBox(
                height: 14,
                child: SarText(
                  sar(budget),
                  style: ts(15, w: 700, lh: 15, c: AppColors.forest),
                  iconW: 11,
                  iconH: 11,
                  gap: 3,
                  iconColor: AppColors.forest,
                ),
              )
            : Tx('—', style: valueStyle, softWrap: false),
      ),
      cell('المساحة', Tx(areaOk ? '${sar(area)}م²' : '—', style: valueStyle, softWrap: false)),
      if (showRooms) cell('الغرف', Tx(roomsTxt, style: valueStyle, softWrap: false)),
    ];

    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Align(alignment: AlignmentDirectional.centerEnd, child: _AskRole(e.role)),
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              for (var i = 0; i < cells.length; i++) ...<Widget>[if (i > 0) const SizedBox(width: 8), Expanded(child: cells[i])],
            ],
          ),
          if (e.description.isNotEmpty) ...<Widget>[
            const SizedBox(height: 12),
            Tx(
              e.description,
              style: ts(14, w: 500, lh: 23, c: AppColors.forest),
              align: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ],
          if (cta) ...<Widget>[
            const SizedBox(height: 12),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: Container(
                height: 36,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    const LucideIcon(Lu.chevronLeft, size: 15, strokeWidth: 2.2, color: AppColors.white),
                    const SizedBox(width: 5),
                    Tx('تفاصيل الطلب', style: ts(14, w: 700, lh: 21, c: AppColors.white), softWrap: false),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Two boxes "min إلى max" for a budget / area / rooms range.
class _AskRange extends StatelessWidget {
  const _AskRange({required this.from, required this.to, required this.small});

  final Widget from;
  final Widget to;
  final bool small;

  @override
  Widget build(BuildContext context) {
    Widget box(Widget child) => Container(
      height: small ? 40 : 46,
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(12)),
      alignment: Alignment.center,
      child: child,
    );
    return Row(
      children: <Widget>[
        Expanded(child: box(from)),
        SizedBox(
          width: 28,
          child: Tx(
            'إلى',
            style: ts(12, w: 600, lh: 18, c: _askTo),
            align: TextAlign.center,
            softWrap: false,
          ),
        ),
        Expanded(child: box(to)),
      ],
    );
  }
}

/// Body of an opened request card (ranges, description, author).
class _AskOpenBody extends StatelessWidget {
  const _AskOpenBody({required this.item, required this.hideWho});

  final RequestItem item;
  final bool hideWho;

  @override
  Widget build(BuildContext context) {
    final e = item;
    final roomsLo = e.roomsMin;
    final roomsHi = e.roomsMax;
    final showRooms = roomsLo > 0 || roomsHi > 0;
    final big = ts(16, w: 700, lh: 16, c: AppColors.forest);
    final sm = ts(14, w: 700, lh: 14, c: AppColors.forest);

    Widget money(double n) {
      if (n.isNaN) return Tx('—', style: big, softWrap: false);
      return SarText(sar(n), style: big, iconW: 12, iconH: 13, gap: 3.3, iconColor: AppColors.forest);
    }

    Widget label(String t) => Tx(
      t,
      style: ts(13, w: 600, lh: 13, c: _askLabel),
      align: TextAlign.right,
    );

    Widget box({required String title, required Widget range}) => Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(16)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: <Widget>[label(title), const SizedBox(height: 8), range]),
    );

    final areaBox = box(
      title: 'المساحة',
      range: _AskRange(
        small: true,
        from: Tx(areaLabel(e.areaMin), style: sm, softWrap: false),
        to: Tx(areaLabel(e.areaMax), style: sm, softWrap: false),
      ),
    );
    final roomsBox = box(
      title: 'الغرف',
      range: _AskRange(
        small: true,
        from: Tx(sar(roomsLo), style: sm, softWrap: false),
        to: Tx(sar(roomsHi), style: sm, softWrap: false),
      ),
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Align(alignment: AlignmentDirectional.centerStart, child: _AskRole(e.role)),
          const SizedBox(height: 14),
          box(
            title: 'الميزانية',
            range: _AskRange(small: false, from: money(e.priceMin), to: money(e.priceMax)),
          ),
          const SizedBox(height: 14),
          if (showRooms)
            Row(
              children: <Widget>[
                Expanded(child: areaBox),
                const SizedBox(width: 8),
                Expanded(child: roomsBox),
              ],
            )
          else
            areaBox,
          if (e.description.isNotEmpty) ...<Widget>[
            const SizedBox(height: 14),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 2),
              child: Tx(
                e.description,
                style: ts(16, w: 500, lh: 27, c: const Color(0xFF24352C)),
                align: TextAlign.right,
              ),
            ),
          ],
          if (!hideWho) ...<Widget>[const SizedBox(height: 14 + 2), _AskFoot(item: e)],
        ],
      ),
    );
  }
}

/// Footer: the author and the contact buttons.
class _AskFoot extends StatelessWidget {
  const _AskFoot({required this.item});

  final RequestItem item;

  @override
  Widget build(BuildContext context) {
    final e = item;
    final initial = e.author.isEmpty ? '' : String.fromCharCode(e.author.codeUnitAt(0));
    return Container(
      padding: const EdgeInsets.fromLTRB(2, 12, 2, 0),
      // a 1 px inner line at the top, drawn without taking layout space
      foregroundDecoration: const BoxDecoration(
        border: Border(top: BorderSide(color: AppColors.mistLine)),
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Row(
              children: <Widget>[
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(12)),
                  alignment: Alignment.center,
                  child: Tx(initial, style: ts(15, w: 700, lh: 22.5, c: AppColors.white)),
                ),
                const SizedBox(width: 10),
                Flexible(
                  child: Tx(
                    e.author,
                    style: ts(15, w: 700, lh: 22.5, c: AppColors.forest),
                    maxLines: 1,
                    softWrap: false,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          AskContact(name: e.author, role: e.role, requestId: e.id, username: e.authorUsername),
        ],
      ),
    );
  }
}

/// The chat button (only for signed-in users). No call: the server never
/// shares a request author's phone.
class AskContact extends StatelessWidget {
  const AskContact({super.key, required this.name, required this.role, this.requestId, this.username});

  final String name;
  final Role role;
  final String? requestId;

  /// With a server: the author's username (a conversation with him about the request).
  final String? username;

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    if (user == null) return const SizedBox.shrink();
    final id = slugName(name);
    Widget btn(String icon, VoidCallback onTap) => Tap(
      onTap: onTap,
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(14)),
        alignment: Alignment.center,
        child: LucideIcon(icon, size: 18, strokeWidth: 1.8, color: AppColors.white),
      ),
    );
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        btn(Lu.messageCircle, () {
          final u = username;
          if (Api.on && u != null) {
            Nav.go(context, ServerChats.startPath(u, about: requestId, name: name, role: role));
            return;
          }
          store.openChat(Chat(id: id, name: name, role: role, last: 'طلب مسحوب', requestId: requestId));
          Nav.go(context, '/chats/$id');
        }),
      ],
    );
  }
}

/// A search request in a list (closed card; tap to open).
class AskCard extends StatelessWidget {
  const AskCard({super.key, required this.item, this.actions, this.own = false, this.locked = false});

  final RequestItem item;
  final Widget? actions;
  final bool own;
  final bool locked;

  @override
  Widget build(BuildContext context) {
    final inner = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        _AskHead(item: item, open: false),
        _AskClosedBody(item: item, cta: !locked),
      ],
    );
    return Container(
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(22),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (locked) inner else Tap(onTap: () => Nav.go(context, '/requests/${item.id}'), child: inner),
            if (actions != null) Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), child: actions),
          ],
        ),
      ),
    );
  }
}

/// The full request card of the request page (+ a note for guests).
class AskCardOpen extends StatelessWidget {
  const AskCardOpen({super.key, required this.item, this.actions, this.hideWho = false});

  final RequestItem item;
  final Widget? actions;
  final bool hideWho;

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Container(
          decoration: BoxDecoration(
            color: AppColors.white,
            borderRadius: BorderRadius.circular(26),
            border: Border.all(color: AppColors.line),
            boxShadow: AppShadows.card,
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(26),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                _AskHead(item: item, open: true),
                _AskOpenBody(item: item, hideWho: hideWho),
                if (actions != null) Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), child: actions),
              ],
            ),
          ),
        ),
        if (user == null) ...<Widget>[
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(14)),
            // a 1.4 px inner ring, drawn without taking layout space
            foregroundDecoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppColors.mistLine, width: 1.4),
            ),
            child: Tx(
              'الطلبات ظاهرة لتعرف حجم السوق. الجوال والمحادثة بعد التفعيل.',
              style: ts(14, w: 600, lh: 21.5, c: AppColors.sage),
              align: TextAlign.center,
            ),
          ),
        ],
      ],
    );
  }
}
