// «أجهزتي» — the phones signed in to this account: each one's model, app version and last use. Any of them is
// signed out from here: a lost or sold phone leaves the account and stops receiving its notifications. Without a
// server (the prototype) only this phone is known.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/clock.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/auth/server_auth.dart';

/// `/account/devices`.
class DevicesView extends StatefulWidget {
  const DevicesView({super.key});

  @override
  State<DevicesView> createState() => _DevicesViewState();
}

class _DevicesViewState extends State<DevicesView> {
  late Future<List<DeviceSession>> _sessions = _load();
  String _notice = '';
  int? _ending;

  Future<List<DeviceSession>> _load() async {
    if (Api.on) return ServerAuth.sessions();
    return <DeviceSession>[DeviceSession.thisPhone()];
  }

  void _reload() {
    setState(() {
      _sessions = _load();
    });
  }

  Future<void> _end(DeviceSession session) async {
    final ok = await showAppDialog(
      context,
      icon: Lu.smartphone,
      title: session.current ? 'تسجيل الخروج من هذا الجهاز؟' : 'إنهاء جلسة ${session.device}؟',
      message: session.current ? null : 'يخرج هذا الجهاز من حسابك ولا تصله إشعاراته بعد الآن. للعودة يسجّل الدخول من جديد.',
      confirmLabel: session.current ? 'تسجيل الخروج' : 'إنهاء الجلسة',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() {
      _ending = session.id;
      _notice = '';
    });
    try {
      await ServerAuth.endSession(session);
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          _ending = null;
          _notice = e.forUser;
        });
      }
      return;
    }
    if (!mounted) return;
    if (session.current) {
      Nav.replace(context, '/');
      return;
    }
    setState(() {
      _ending = null;
      _notice = 'انتهت جلسة ${session.device}.';
      _sessions = _load();
    });
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<DeviceSession>>(
      future: _sessions,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return Tx('جارٍ تحميل أجهزتك…', style: ts(15, lh: 22.5, c: AppColors.muted));
        }
        final error = snapshot.error;
        if (error != null) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              FormError(error is ApiError ? error.forUser : 'تعذّر تحميل أجهزتك.'),
              const SizedBox(height: 12),
              AppButton(label: 'أعد المحاولة', tone: ButtonTone.secondary, onTap: _reload),
            ],
          );
        }
        final sessions = snapshot.data ?? const <DeviceSession>[];
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Tx(
              Api.on ? 'كل جهاز دخل حسابك. أنهِ جلسة أي جهاز لا تعرفه أو فقدته.' : 'هذا الجهاز. تظهر هنا كل أجهزتك حين يعمل التطبيق مع خادم زون.',
              style: ts(13, lh: 19.5, c: AppColors.muted),
            ),
            const SizedBox(height: 12),
            if (_notice.isNotEmpty) ...<Widget>[Tx(_notice, style: ts(14, w: 600, lh: 21, c: AppColors.teal)), const SizedBox(height: 12)],
            for (var i = 0; i < sessions.length; i++) ...<Widget>[
              if (i > 0) const SizedBox(height: 10),
              DeviceCard(
                session: sessions[i],
                busy: _ending == sessions[i].id,
                onEnd: Api.on && _ending == null ? () => _end(sessions[i]) : null,
              ),
            ],
          ],
        );
      },
    );
  }
}

/// One phone: its model, «هذا الجهاز», the app's version, the last use, and the way out.
class DeviceCard extends StatelessWidget {
  const DeviceCard({super.key, required this.session, this.onEnd, this.busy = false});

  final DeviceSession session;

  /// Null when the session cannot be ended here (the prototype, or another one is ending).
  final VoidCallback? onEnd;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final s = session;
    final system = switch (s.platform) {
      'ios' => 'iOS',
      'android' => 'Android',
      _ => null,
    };
    final meta = <String>[?system, if (s.appVersion != null) 'زون ${s.appVersion}'];
    final used = s.lastUsedAt ?? s.signedInAt;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(20), boxShadow: AppShadows.hairline),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(12)),
                alignment: Alignment.center,
                child: const LucideIcon(Lu.smartphone, size: 18, color: AppColors.white),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Tx(s.device, style: ts(15, w: 700, lh: 22.5, c: AppColors.ink), maxLines: 1, overflow: TextOverflow.ellipsis),
                    if (meta.isNotEmpty) Tx(meta.join(' · '), style: ts(13, lh: 19.5, c: AppColors.muted)),
                  ],
                ),
              ),
              if (s.current)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(999)),
                  child: Tx('هذا الجهاز', style: ts(12, w: 700, lh: 18, c: AppColors.forest)),
                ),
            ],
          ),
          if (used != null) ...<Widget>[
            const SizedBox(height: 8),
            Tx(
              s.lastUsedAt != null ? 'آخر استخدام: ${arRelative(used, appNow())}' : 'دخل في ${arDateTime(used)}',
              style: ts(13, lh: 19.5, c: AppColors.sage),
            ),
          ],
          if (onEnd != null || busy) ...<Widget>[
            const SizedBox(height: 12),
            AppButton(
              label: busy ? 'جارٍ الإنهاء…' : (s.current ? 'تسجيل الخروج من هذا الجهاز' : 'إنهاء الجلسة'),
              tone: ButtonTone.ghost,
              onTap: busy ? null : onEnd,
            ),
          ],
        ],
      ),
    );
  }
}
