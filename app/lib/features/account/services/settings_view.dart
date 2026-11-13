// الإعدادات — profile fields, notification switches, «أجهزتي», policies, «الدعم», sign out. With a server (ZON_API_URL)
// the profile, the switches and the sign-out are the server's (ServerAuth); without one they stay on the device.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/auth/auth.dart';
import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/server_support_view.dart';
import 'package:zon/features/account/services/widgets/service_form.dart';

const List<List<String>> kPolicies = <List<String>>[
  <String>['payments', 'سياسة الدفع', Lu.landmark],
  <String>['privacy', 'سياسة الخصوصية', Lu.shield],
  <String>['ip', 'سياسة حقوق الملكية والفكرية', Lu.lock],
  <String>['terms', 'أحكام وشروط استخدام المنصة', Lu.fileText],
  <String>['rega', 'ضوابط الإعلانات العقارية', Lu.scale],
];

/// The notification switches: the server's keys (docs/api.md PUT /me/notification-preferences) and their labels.
/// The six are on until turned off; «العروض والتسويق» is off until the person turns it on — a promotion reaches
/// the phone only with that consent (App Store Review Guideline 4.5.4, PDPL Art. 25), the bell always has it.
const List<(String, String)> kNoticePrefs = <(String, String)>[
  ('messages', 'رسائل'),
  ('followed_posts', 'نشر المتابَعين'),
  ('area_requests', 'طلبات المنطقة'),
  ('listing_status', 'حالة الإعلان'),
  ('ratings', 'تقييمات'),
  ('matching', 'مطابقة'),
  ('marketing', 'العروض والتسويق'),
];

/// On until turned off — except the marketing consent.
bool noticePrefDefault(String key) => key != 'marketing';

/// On/off switch (RTL: off → knob right, on → knob left).
class Sw extends StatelessWidget {
  const Sw({super.key, required this.on});

  final bool on;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 44,
      height: 26,
      decoration: BoxDecoration(color: on ? AppColors.forest : const Color(0x24121214), borderRadius: BorderRadius.circular(999)),
      child: Stack(
        children: <Widget>[
          PositionedDirectional(
            top: 3,
            start: on ? null : 3,
            end: on ? 3 : null,
            child: Container(
              width: 20,
              height: 20,
              decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(999)),
            ),
          ),
        ],
      ),
    );
  }
}

/// Notification setting row with a switch.
class NoteSw extends StatelessWidget {
  const NoteSw({super.key, required this.start, required this.end, this.last = false});

  final Widget start;
  final Widget end;
  final bool last;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minHeight: 48),
      decoration: BoxDecoration(
        border: last ? null : const Border(bottom: BorderSide(color: Color(0x0F121214))),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: <Widget>[
          Flexible(child: start),
          const SizedBox(width: 12),
          end,
        ],
      ),
    );
  }
}

/// Row linking to a policy page.
class PolicyRow extends StatelessWidget {
  const PolicyRow({super.key, required this.icon, required this.title, required this.onTap, this.iconStroke = 2});

  final String icon;
  final String title;
  final VoidCallback onTap;
  final double iconStroke;

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(20),
          boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
        ),
        child: Row(
          children: <Widget>[
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(12)),
              alignment: Alignment.center,
              child: LucideIcon(icon, size: 18, strokeWidth: iconStroke, color: AppColors.white),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Tx(title, style: ts(15, w: 700, lh: 22.5, c: AppColors.ink)),
            ),
            const SizedBox(width: 12),
            const LucideIcon(Lu.chevronLeft, size: 16, color: AppColors.muted),
          ],
        ),
      ),
    );
  }
}

/// الإعدادات: profile, notifications, policies, sign out.
class SettingsView extends StatefulWidget {
  const SettingsView({super.key, this.tab});

  /// The tab it opens on (`account` · `legal` · `support`).
  final String? tab;

  @override
  State<SettingsView> createState() => _SettingsViewState();
}

class _SettingsViewState extends State<SettingsView> {
  Session? get user => store.readSession();
  late String tab = const <String>{'account', 'legal', 'support'}.contains(widget.tab) ? widget.tab! : 'account';
  bool edit = false;
  late final TextEditingController _name = TextEditingController(text: user?.name ?? '');
  late final TextEditingController _phone = TextEditingController(text: user?.phone ?? '');
  late String city = user?.city ?? '';
  String theme = 'light';
  String lang = 'ar';
  late final Map<String, bool> notifs = <String, bool>{
    for (final (key, _) in kNoticePrefs) key: Api.on ? (ServerSession.prefs[key] ?? noticePrefDefault(key)) : noticePrefDefault(key),
  };
  String prefError = '';
  late final TextEditingController _tName = TextEditingController(text: user?.name ?? '');
  late final TextEditingController _tPhone = TextEditingController(text: user?.phone ?? '');
  final TextEditingController _tBody = TextEditingController();
  String sent = '';

  @override
  void dispose() {
    for (final c in <TextEditingController>[_name, _phone, _tName, _tPhone, _tBody]) {
      c.dispose();
    }
    super.dispose();
  }

  void _setDark(String next) {
    setState(() => theme = next);
    store.setItem('zone-theme', next);
  }

  String profileError = '';
  bool _saving = false;

  Future<void> _saveProfile() async {
    final u = user;
    if (u == null || _saving) return;
    final error = LocalAuth.nameError(_name.text) ?? LocalAuth.phoneError(_phone.text);
    setState(() => profileError = error ?? '');
    if (error != null) return;
    if (!Api.on) {
      LocalAuth.updateProfile(name: _name.text, phone: _phone.text, city: city);
      setState(() => edit = false);
      return;
    }
    if (_phone.text.trim() != u.phone) {
      // The server changes a number only with a code sent to the new one (POST /me/phone): not wired yet.
      setState(() => profileError = 'تغيير رقم الجوال يحتاج رمز تحقق إلى الرقم الجديد، ولم يُربط بعد في هذه النسخة.');
      return;
    }
    setState(() => _saving = true);
    final failed = await ServerAuth.updateProfile(name: _name.text, city: city);
    if (!mounted) return;
    setState(() {
      _saving = false;
      profileError = failed ?? '';
      if (failed == null) edit = false;
    });
  }

  /// One switch: saved on the server when the app has one (the old value comes back if it could not be saved).
  Future<void> _togglePref(String key) async {
    final on = !(notifs[key] ?? false);
    setState(() {
      notifs[key] = on;
      prefError = '';
    });
    if (!Api.on) return;
    final failed = await ServerAuth.setPref(key, on);
    if (!mounted || failed == null) return;
    setState(() {
      notifs[key] = !on;
      prefError = failed;
    });
  }

  Widget _block(String h, Widget body) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: <Widget>[
      Tx(h, style: ts(14, w: 700, lh: 21, c: AppColors.ink)),
      const SizedBox(height: 8),
      body,
    ],
  );

  Widget _panel(Widget child, {EdgeInsets padding = const EdgeInsets.all(16)}) => Container(
    padding: padding,
    decoration: BoxDecoration(color: AppColors.surface, borderRadius: BorderRadius.circular(24), boxShadow: AppShadows.hairline),
    child: child,
  );

  /// 1.1 records nobody could be proven to own (QA F01): shown, not given
  /// to anyone; the account may ask for them — nothing moves before the
  /// owner is checked (server / support).
  Widget _legacyRecovery(int listings, int requests) {
    final asked = store.legacyRecoveryRequested();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx('عروض: $listings · طلبات: $requests', style: ts(15, w: 700, lh: 22.5, c: AppColors.ink)),
        const SizedBox(height: 6),
        Tx(
          'أُنشئت على هذا الجهاز بالإصدار السابق ولم يثبت صاحبها، فلم تُنقل لأي حساب. تبقى ظاهرة كما كانت ولا يعدّلها أحد.',
          style: ts(13, lh: 19.5, c: AppColors.muted),
        ),
        const SizedBox(height: 4),
        Tx('إن كانت لك فاطلب استعادتها؛ تُنقل لصاحبها بعد التحقق منه عند ربط الخادم.', style: ts(13, lh: 19.5, c: AppColors.muted)),
        const SizedBox(height: 12),
        if (asked)
          Tx('سُجّل طلبك — بانتظار التحقق، ولم يُنقل شيء.', style: ts(13, w: 600, lh: 19.5, c: AppColors.forest))
        else
          AppButton(label: 'طلب استعادة', tone: ButtonTone.secondary, onTap: () => setState(store.requestLegacyRecovery)),
      ],
    );
  }

  List<Widget> _gap(List<Widget> items, double g) => <Widget>[
    for (var i = 0; i < items.length; i++) ...<Widget>[if (i > 0) SizedBox(height: g), items[i]],
  ];

  @override
  Widget build(BuildContext context) {
    final u = user;
    final meta = StringBuffer(u != null ? (kRoleLabel[u.role] ?? '') : '');
    if (u != null && u.city.isNotEmpty) meta.write(' · ${u.city}');
    if (u != null && u.phone.isNotEmpty) meta.write(' · ${u.phone}');
    final text14 = ts(15, w: 600, lh: 22.5, c: AppColors.ink);

    final hero = Container(
      padding: const EdgeInsets.fromLTRB(20, 22, 20, 18),
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(28)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Tx('ZONE ID', style: ts(11, w: 700, lh: 16.5, c: const Color(0xFFC8E6D0))),
          const SizedBox(height: 8),
          Tx(u?.name.isNotEmpty == true ? u!.name : 'حساب زون', style: ts(28, w: 700, lh: 42, c: AppColors.white)),
          const SizedBox(height: 6),
          Tx(meta.toString(), style: ts(13, lh: 19.5, c: const Color(0xC4FFFFFF))),
          const SizedBox(height: 16),
          Tap(
            onTap: () => setState(() {
              tab = 'account';
              edit = true;
            }),
            child: Container(
              height: 44,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(999)),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[Tx('تعديل معلوماتي', style: ts(16, w: 700, lh: 24, c: AppColors.forest))],
              ),
            ),
          ),
        ],
      ),
    );

    final rail = Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(999),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Row(
        children: <Widget>[
          for (final e in const <List<Object>>[
            <Object>['account', 'الحساب', 100],
            <Object>['legal', 'السياسات والأحكام', 155],
            <Object>['support', 'الدعم', 100],
          ]) ...<Widget>[
            if (e[0] != 'account') const SizedBox(width: 4),
            Expanded(
              flex: e[2] as int,
              child: Tap(
                onTap: () => setState(() => tab = e[0] as String),
                child: Container(
                  height: 40,
                  padding: const EdgeInsets.symmetric(horizontal: 6),
                  decoration: BoxDecoration(
                    color: tab == e[0] ? AppColors.forest : const Color(0x00000000),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  alignment: Alignment.center,
                  child: Tx(
                    e[1] as String,
                    style: ts(13, w: 700, lh: 19.5, c: tab == e[0] ? AppColors.white : AppColors.sage),
                    softWrap: false,
                    maxLines: 1,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );

    final body = <Widget>[hero, rail];

    if (tab == 'account') {
      final profile = edit
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _gap(<Widget>[
                SvcField(
                  label: 'الاسم',
                  child: selInput(controller: _name),
                ),
                SvcField(
                  label: 'الجوال',
                  child: selInput(controller: _phone, dir: TextDirection.ltr, keyboard: TextInputType.phone),
                ),
                SvcField(
                  label: 'المدينة',
                  child: selSelect(
                    value: city,
                    options: <Opt>[const Opt('', 'اختر المدينة'), ...optsOf(kCities.keys)],
                    onChanged: (v) => setState(() => city = v),
                  ),
                ),
                if (profileError.isNotEmpty) FormError(profileError),
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Row(
                    children: <Widget>[
                      Expanded(
                        child: AppButton(label: _saving ? 'جارٍ الحفظ…' : 'حفظ', onTap: _saving ? null : _saveProfile),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: AppButton(
                          label: 'إلغاء',
                          tone: ButtonTone.ghost,
                          onTap: () => setState(() {
                            _name.text = u?.name ?? '';
                            _phone.text = u?.phone ?? '';
                            city = u?.city ?? '';
                            profileError = '';
                            edit = false;
                          }),
                        ),
                      ),
                    ],
                  ),
                ),
              ], 12),
            )
          : Tap(
              onTap: () => setState(() => edit = true),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  NoteSw(
                    start: Tx('الاسم', style: text14.copyWith(color: AppColors.muted)),
                    end: Tx(u?.name.isNotEmpty == true ? u!.name : '—', style: text14),
                  ),
                  NoteSw(
                    start: Tx('الجوال', style: text14.copyWith(color: AppColors.muted)),
                    end: Tx(u?.phone.isNotEmpty == true ? u!.phone : '—', style: text14),
                  ),
                  NoteSw(
                    start: Tx('المدينة', style: text14.copyWith(color: AppColors.muted)),
                    end: Tx(u?.city.isNotEmpty == true ? u!.city : 'غير محددة', style: text14),
                  ),
                  Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: Tx(
                      'اضغط للتعديل',
                      style: ts(13, w: 600, lh: 17.5, c: AppColors.teal),
                      align: TextAlign.center,
                    ),
                  ),
                ],
              ),
            );
      final account = <Widget>[
        _block('معلوماتي الشخصية', _panel(profile)),
        _block(
          'المظهر واللغة',
          _panel(
            Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Tap(
                  onTap: () => _setDark(theme == 'dark' ? 'light' : 'dark'),
                  child: NoteSw(
                    start: Tx('الوضع الداكن', style: text14),
                    end: Sw(on: theme == 'dark'),
                  ),
                ),
                Tap(
                  onTap: () => setState(() => lang = lang == 'en' ? 'ar' : 'en'),
                  child: NoteSw(
                    start: Tx('English', style: text14),
                    end: Sw(on: lang == 'en'),
                    last: lang != 'en',
                  ),
                ),
                if (lang == 'en')
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 0, 4, 8),
                    child: Tx('English UI is rolling out — listings stay in Arabic.', style: ts(12, lh: 18, c: AppColors.muted)),
                  ),
              ],
            ),
          ),
        ),
        _block(
          'الإشعارات',
          _panel(
            Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                for (var i = 0; i < kNoticePrefs.length; i++)
                  Tap(
                    onTap: () => _togglePref(kNoticePrefs[i].$1),
                    child: NoteSw(
                      start: Tx(kNoticePrefs[i].$2, style: text14),
                      end: Sw(on: notifs[kNoticePrefs[i].$1] ?? false),
                      last: i == kNoticePrefs.length - 1,
                    ),
                  ),
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Tx(
                    'العروض والتسويق: عروض زون وحملاتها على جوالك. مطفأ حتى تفعّله، وتوقفه متى شئت.',
                    style: ts(12, lh: 18, c: AppColors.muted),
                  ),
                ),
                if (prefError.isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 12), child: FormError(prefError)),
              ],
            ),
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
          ),
        ),
        PolicyRow(icon: Lu.smartphone, title: 'أجهزتي', onTap: () => Nav.go(context, '/account/devices')),
        if (store.unclaimedLegacy() case final legacy when legacy.listings.isNotEmpty || legacy.requests.isNotEmpty)
          _block('بيانات من الإصدار السابق', _panel(_legacyRecovery(legacy.listings.length, legacy.requests.length))),
        if (u?.role != 'developer') PolicyRow(icon: Lu.landmark, title: 'الاشتراكات والباقة', onTap: () => Nav.go(context, '/account/plans')),
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(24), boxShadow: AppShadows.card),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Directionality(
                textDirection: TextDirection.rtl,
                child: Tx('REGA · FAL', style: ts(11, w: 600, lh: 16.5, c: const Color(0xFFC8E6D0))),
              ),
              const SizedBox(height: 8),
              Tx('رخصة المنصة · زون', style: ts(18, w: 700, lh: 28, c: AppColors.white)),
              const SizedBox(height: 4),
              Tx('تُعرض وفق متطلبات الهيئة العامة للعقار', style: ts(13, lh: 17.5, c: const Color(0x99FFFFFF))),
            ],
          ),
        ),
        AppButton(
          label: 'تسجيل الخروج',
          tone: ButtonTone.ghost,
          onTap: () async {
            // Closes this phone's session — the account and its data stay (on the server, its token goes).
            await Auth.signOut();
            if (context.mounted) unawaited(Nav.go(context, '/'));
          },
        ),
      ];
      body.add(Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: _gap(account, 20)));
    } else if (tab == 'legal') {
      body.add(
        _block(
          'السياسات والأحكام لمنصة زون',
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: _gap(<Widget>[
              for (final p in kPolicies) PolicyRow(icon: p[2], title: p[1], iconStroke: 1.7, onTap: () => Nav.go(context, '/legal/${p[0]}')),
            ], 10),
          ),
        ),
      );
    } else {
      body.add(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: _gap(<Widget>[
            if (Api.on)
              const ServerSupportView()
            else
              _block(
              'تذكرة دعم',
              _panel(
                Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: _gap(<Widget>[
                    SvcField(
                      label: 'الاسم',
                      child: selInput(controller: _tName),
                    ),
                    SvcField(
                      label: 'الجوال',
                      child: selInput(controller: _tPhone, dir: TextDirection.ltr),
                    ),
                    SvcField(
                      label: 'نص التذكرة',
                      child: FieldBox(
                        controller: _tBody,
                        style: kSelText,
                        height: 112,
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        decoration: kSelDeco,
                        multiline: true,
                      ),
                    ),
                    if (sent.isNotEmpty) Tx(sent, style: ts(15, w: 600, lh: 21.5, c: AppColors.teal)),
                    AppButton(
                      label: 'إرسال',
                      onTap: () {
                        if (_tBody.text.trim().isEmpty) return;
                        setState(() {
                          sent = 'وصلت التذكرة — يصدر رقم قيد من لوحة الإدارة';
                          _tBody.clear();
                        });
                      },
                    ),
                  ], 12),
                ),
              ),
            ),
            AppButton(
              label: 'حذف حسابي',
              tone: ButtonTone.danger,
              onTap: () async {
                if (await showAppDialog(context, icon: Lu.trash2, title: 'تأكيد حذف الحساب؟', confirmLabel: 'حذف', destructive: true)) {
                  if (!context.mounted) return;
                  await showAppNotice(context, icon: Lu.check, title: 'طلب الحذف سُجّل — يُنفَّذ من الباك إند');
                }
              },
            ),
          ], 20),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: _gap(body, 20)),
    );
  }
}
