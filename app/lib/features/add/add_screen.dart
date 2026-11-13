// `/add` — إضافة: the user first chooses what to add, then the matching flow
// opens in place:
//
// | choice       | who                | flow file          |
// |--------------|--------------------|--------------------|
// | إعلان عقاري   | owner, broker      | listing_form.dart  |
// | مشروع         | developer          | project_form.dart  |
// | طلب عرض       | everyone           | request_form.dart  |
//
// Seekers skip the choice and go straight to the request form.
// `/add?type=listing` opens the listing flow directly (e.g. «ابدأ نشر عقارك»
// on the owner plan); `type=request` / `type=project` likewise.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/listing_form.dart';
import 'package:zon/features/add/project_form.dart';
import 'package:zon/features/add/request_form.dart';
import 'package:zon/features/add/widgets/add_kit.dart';

/// What the user is adding.
enum _Choice { pick, listing, project, request }

/// `/add` — إضافة
class AddScreen extends StatefulWidget {
  const AddScreen({super.key, this.start});

  /// `listing` | `project` | `request` — skips the choice screen.
  final String? start;

  @override
  State<AddScreen> createState() => _AddScreenState();
}

class _AddScreenState extends State<AddScreen> {
  /// Null until the user picks (then the default depends on the role).
  late _Choice? choice = _start;

  _Choice? get _start => switch (widget.start) {
    'listing' => _Choice.listing,
    'project' => _Choice.project,
    'request' => _Choice.request,
    _ => null,
  };

  /// The account the open form belongs to: what one account typed (an owner's name and phone, his photos) is
  /// never shown to the next, so another account (or none) gets a new, empty form.
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    store.addListener(_onAccount);
  }

  @override
  void dispose() {
    store.removeListener(_onAccount);
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    setState(() {
      _epoch = store.serverEpoch;
      choice = _start;
    });
  }

  void _pick(_Choice c) => setState(() => choice = c);

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    if (user == null) return const RedirectTo('/login');
    final c = choice ?? (user.role == 'seeker' ? _Choice.request : _Choice.pick);
    void backToPick() => _pick(_Choice.pick);
    final Widget body = switch (c) {
      _Choice.pick => _ChooseView(role: user.role, onPick: _pick),
      _Choice.request => RequestForm(
        name: user.name,
        city: user.city,
        // seekers have no choice screen: leave /add instead
        onBack: () => user.role == 'seeker' ? Nav.back(context) : backToPick(),
      ),
      _Choice.project => ProjectForm(name: user.name, city: user.city, onBack: backToPick),
      _Choice.listing => ListingForm(name: user.name, city: user.city, role: user.role, onBack: backToPick),
    };
    return AppShell(
      background: c == _Choice.pick ? AppColors.white : null,
      child: KeyedSubtree(key: ValueKey<(_Choice, int)>((c, _epoch)), child: body),
    );
  }
}

/// "ماذا تريد؟" — the two options of the user's role.
class _ChooseView extends StatelessWidget {
  const _ChooseView({required this.role, required this.onPick});

  final String role;
  final ValueChanged<_Choice> onPick;

  @override
  Widget build(BuildContext context) {
    final dev = role == 'developer';
    final options = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx(
          'إضافة',
          style: ts(14, w: 700, lh: 21, c: AppColors.success),
          align: TextAlign.center,
        ),
        const SizedBox(height: 8),
        Tx(
          'ماذا تريد؟',
          style: ts(28, w: 700, lh: 40, c: AppColors.deep),
          align: TextAlign.center,
        ),
        const SizedBox(height: 22),
        _Option(
          icon: dev ? Lu.building2 : Lu.home,
          title: dev ? 'مشروع' : 'إعلان عقاري',
          sub: dev ? 'وحدات المشروع' : 'بيع أو إيجار',
          onTap: () => onPick(dev ? _Choice.project : _Choice.listing),
        ),
        const SizedBox(height: 10),
        _Option(icon: Lu.search, title: 'طلب عرض', sub: 'ارفع طلب بحث وسوف يتواصل معك الوسطاء في منطقتك', onTap: () => onPick(_Choice.request)),
      ],
    );
    return Stack(
      children: <Widget>[
        Container(
          constraints: BoxConstraints(minHeight: addStageMinHeight(context)),
          color: AppColors.white,
          padding: const EdgeInsets.fromLTRB(20, 72, 20, 108),
          alignment: Alignment.center,
          child: options,
        ),
        PositionedDirectional(top: 16, start: 16, child: CloseCircle(onTap: () => Nav.back(context))),
      ],
    );
  }
}

/// One option card: green icon tile, title and subtitle.
class _Option extends StatelessWidget {
  const _Option({required this.icon, required this.title, required this.sub, required this.onTap});

  final String icon;
  final String title;
  final String sub;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(22),
          border: Border.all(color: AppColors.mistLine),
          boxShadow: AppShadows.card,
        ),
        child: Row(
          children: <Widget>[
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(16)),
              alignment: Alignment.center,
              child: LucideIcon(icon, size: 22, strokeWidth: 1.75, color: AppColors.white),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: StretchRows(
                gap: 4,
                minHeight: 60,
                children: <Widget>[
                  Tx(title, style: ts(18, w: 700, lh: 27, c: AppColors.deep)),
                  Tx(sub, style: ts(14, w: 500, lh: 20.5, c: AppColors.sage)),
                ],
              ),
            ),
            const SizedBox(width: 8),
            const LucideIcon(Lu.chevronLeft, size: 18, color: AppColors.stone),
          ],
        ),
      ),
    );
  }
}
