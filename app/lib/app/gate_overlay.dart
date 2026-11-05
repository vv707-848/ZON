// The server's gate over the whole app (lib/core/net/app_gate.dart): «حدّث زون» when this version may no longer
// be used (426), «زون في الصيانة» during maintenance (503). The pages stay alive underneath, so the app goes on
// where it was once the gate opens again.

import 'dart:async';

import 'package:flutter/widgets.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/app_gate.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';

/// Wraps the app's navigator (ZonApp.builder).
class GateOverlay extends StatelessWidget {
  const GateOverlay({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<Gate>(
      valueListenable: AppGate.state,
      child: child,
      builder: (context, gate, app) => Stack(
        fit: StackFit.expand,
        children: <Widget>[
          ExcludeSemantics(excluding: gate is! GateOpen, child: app ?? const SizedBox.shrink()),
          if (gate is GateUpdate) UpdateRequiredScreen(gate: gate),
          if (gate is GateMaintenance) MaintenanceScreen(gate: gate),
        ],
      ),
    );
  }
}

/// This version may no longer be used: the way to the store.
class UpdateRequiredScreen extends StatelessWidget {
  const UpdateRequiredScreen({super.key, required this.gate});

  final GateUpdate gate;

  @override
  Widget build(BuildContext context) {
    final url = gate.storeUrl;
    final version = gate.minVersion.isEmpty ? '' : ' إلى النسخة ${gate.minVersion} أو أحدث';
    return _GateScreen(
      icon: Lu.smartphone,
      title: 'حدّث زون للمتابعة',
      message: 'هذه النسخة من التطبيق لم تعد تعمل. حدّثه$version من المتجر.',
      action: url == null ? null : AppButton(label: 'تحديث من المتجر', onTap: () => unawaited(openExternal(url))),
      note: url == null ? 'ابحث عن «زون» في المتجر وحدّثه.' : null,
    );
  }
}

/// Maintenance: the server's message, when it expects to be back, and a retry.
class MaintenanceScreen extends StatefulWidget {
  const MaintenanceScreen({super.key, required this.gate});

  final GateMaintenance gate;

  @override
  State<MaintenanceScreen> createState() => _MaintenanceScreenState();
}

class _MaintenanceScreenState extends State<MaintenanceScreen> {
  bool _checking = false;

  Future<void> _retry() async {
    final api = Api.client;
    if (api == null || _checking) return;
    setState(() => _checking = true);
    await AppGate.check(api);
    if (mounted) setState(() => _checking = false);
  }

  @override
  Widget build(BuildContext context) {
    final message = widget.gate.message;
    final until = widget.gate.until;
    return _GateScreen(
      icon: Lu.settings,
      title: 'زون في الصيانة',
      message: message == null || message.isEmpty ? 'نحدّث المنصة، ونعود قريباً.' : message,
      note: until == null ? null : 'نتوقع العودة ${arDateTime(until)}',
      action: AppButton(label: _checking ? 'جارٍ التحقق…' : 'أعد المحاولة', tone: ButtonTone.secondary, onTap: _checking ? null : _retry),
    );
  }
}

class _GateScreen extends StatelessWidget {
  const _GateScreen({required this.icon, required this.title, required this.message, this.note, this.action});

  final String icon;
  final String title;
  final String message;
  final String? note;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final note = this.note;
    final action = this.action;
    return ColoredBox(
      color: AppColors.paper,
      child: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const Center(child: ZonLogo(size: 64)),
                const SizedBox(height: 20),
                Center(
                  child: Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(14)),
                    alignment: Alignment.center,
                    child: LucideIcon(icon, size: 20, color: AppColors.white),
                  ),
                ),
                const SizedBox(height: 16),
                Tx(title, style: ts(24, w: 700, lh: 36, c: AppColors.ink), align: TextAlign.center),
                const SizedBox(height: 8),
                Tx(message, style: ts(15, lh: 24, c: AppColors.muted), align: TextAlign.center),
                if (note != null) ...<Widget>[
                  const SizedBox(height: 8),
                  Tx(note, style: ts(13, w: 600, lh: 19.5, c: AppColors.teal), align: TextAlign.center),
                ],
                if (action != null) ...<Widget>[const SizedBox(height: 24), action],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
